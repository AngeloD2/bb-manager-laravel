import { genUuid } from "./uuid";

// Pure, framework-agnostic playback brain.
//
// The backend pre-bakes an ordered `schedule` and a `quota` snapshot on /sync.
// This class plays the schedule round-robin and meters spots locally — with no
// network and fully offline — emitting an append-only log of play events keyed
// by a client UUID (the idempotency key). The server stays the billing
// authority; on each reconcile we reset to its snapshot and re-apply only the
// play events still waiting to be flushed.
//
// Effective counters = server snapshot + locally recorded (unsynced) plays.

const PLAYBACK_TOLERANCE_MIN = 15;

// Fraction of an asset's ideal inter-play interval that must elapse before it may
// play again (rule 4: spread plays across the hour). Kept identical to the Laravel
// and Expo schedulers so every engine paces the same way.
const PACING_FACTOR = 0.75;

function ymd(date) {
  return date.toISOString().slice(0, 10);
}

export class Scheduler {
  /**
   * @param {object}   opts
   * @param {object}   opts.schedule         { primary:[...], campaign_fallback:[...], global_fallback:[...], fallback:[...], loops:{...} }
   * @param {object}   opts.quota            server snapshot { seconds_per_spot, assets, loops, ... }
   * @param {Map}      opts.assetsById       asset_id -> media detail (file_type, duration_secs, ...)
   * @param {Array}    [opts.pendingEvents]  unsynced play events to replay onto the snapshot
   * @param {Array}    [opts.history]        initial playback history of asset IDs
   * @param {Function} [opts.onReject]       callback (assetId, reason) => void
   */
  constructor({ schedule, quota, assetsById, pendingEvents = [], history = [], onReject = null }) {
    this.onReject = onReject;
    this.reseed({ schedule, quota, assetsById, pendingEvents, history });
  }

  /** Reset to a fresh server snapshot, re-applying any still-unsynced plays. */
  reseed({ schedule, quota, assetsById, pendingEvents = [], history = [] }) {
    const prevPrimaryId = this.lastPickedPrimaryId;
    const prevFallbackCursor = this.globalFallbackCursor || this.fallbackCursor || 0;
    const prevLastAssetId = this.lastAssetId;
    this.history = Array.isArray(history) ? [...history] : [];

    this.schedule = schedule || { primary: [], fallback: [] };
    this.quota = quota || { seconds_per_spot: 15, assets: {}, loops: {} };
    this.assetsById = assetsById || new Map();

    // Group primary schedule into loops
    this.primaryLoops = this._groupLoops(this.schedule.primary || [], false);

    // Group fallback schedule into loops and partition into campaign-specific vs global
    let fallbackSlots = [];
    if (Array.isArray(this.schedule.fallback) && this.schedule.fallback.length > 0) {
      fallbackSlots = this.schedule.fallback;
    } else {
      fallbackSlots = [
        ...(Array.isArray(this.schedule.campaign_fallback) ? this.schedule.campaign_fallback : []),
        ...(Array.isArray(this.schedule.global_fallback) ? this.schedule.global_fallback : []),
      ];
    }

    this.allFallbackLoops = this._groupLoops(fallbackSlots, true);
    this.campaignFallbackLoops = new Map();
    this.globalFallbackLoops = [];

    for (const fbLoop of this.allFallbackLoops) {
      if (fbLoop.campaignId) {
        if (!this.campaignFallbackLoops.has(fbLoop.campaignId)) {
          this.campaignFallbackLoops.set(fbLoop.campaignId, []);
        }
        this.campaignFallbackLoops.get(fbLoop.campaignId).push(fbLoop);
      } else {
        this.globalFallbackLoops.push(fbLoop);
      }
    }

    this.loopIdx = 0;
    this.assetIdx = 0;
    this.activeLoopPassList = null;
    this.activeLoopId = null;

    this.campaignFallbackCursors = this.campaignFallbackCursors || new Map();
    this.globalFallbackCursor = prevFallbackCursor;
    this.lastAssetId = prevLastAssetId;
    this.lastPickedPrimaryId = prevPrimaryId;

    // Working counters derived from the snapshot, then advanced by unsynced plays.
    this.work = { assets: {}, loops: {} };
    for (const [id, a] of Object.entries(this.quota.assets || {})) {
      this.work.assets[id] = {
        spotsRemaining: a.play_spots_remaining ?? Infinity,
        playsToday: a.plays_today ?? 0,
        // Recent local play timestamps (ms) for the hourly window.
        recent: [],
        baseHour: a.plays_last_hour ?? 0,
        // Server-stamped last play (ms) so pacing works right after a cold sync,
        // before any local plays exist; advanced locally by _apply.
        lastPlayedAt: a.last_played_at ? Date.parse(a.last_played_at) : null,
      };
    }
    for (const [id, l] of Object.entries(this.quota.loops || {})) {
      this.work.loops[id] = { spotsToday: l.spots_spent_today ?? 0 };
    }
    this._asOf = this.quota.as_of ? Date.parse(this.quota.as_of) : Date.now();

    for (const ev of pendingEvents) this._apply(ev, false);

    // If previous primary play is known, restore cursor position
    if (prevPrimaryId && this.primaryLoops.length > 0) {
      for (let l = 0; l < this.primaryLoops.length; l++) {
        const loop = this.primaryLoops[l];
        const passList = this._buildPassList(loop);
        const foundPos = passList.indexOf(prevPrimaryId);
        if (foundPos !== -1) {
          const nextAsset = foundPos + 1;
          if (nextAsset >= passList.length) {
            this.loopIdx = (l + 1) % this.primaryLoops.length;
            this.assetIdx = 0;
          } else {
            this.loopIdx = l;
            this.assetIdx = nextAsset;
            this.activeLoopPassList = passList;
            this.activeLoopId = loop.loopId;
          }
          break;
        }
      }
    }

    // Override queue is intentionally NOT reset on reseed: an in-flight override
    // must survive a reconciling /sync snapshot arriving between when the server
    // injected it and when the player drains it.
    this.overrideQueue = this.overrideQueue || [];
  }

  get fallbackCursor() {
    return this.globalFallbackCursor;
  }

  set fallbackCursor(val) {
    this.globalFallbackCursor = val;
  }

  // Build ordered loop descriptors from a list of slots, preserving order.
  _groupLoops(items, isFallbackDefault = false) {
    const order = [];
    const byLoop = new Map();

    for (const slot of items) {
      const lid = slot.loop_id ?? "__none__";
      if (!byLoop.has(lid)) {
        const loopMeta = this.schedule.loops?.[lid] || this.quota.loops?.[lid] || {};
        const campaignId = loopMeta.campaign_id ?? slot.campaign_id ?? null;
        const isBundle = !!loopMeta.is_bundle;
        const isFallback = loopMeta.is_fallback !== undefined ? !!loopMeta.is_fallback : isFallbackDefault;

        byLoop.set(lid, {
          loopId: lid,
          campaignId,
          isBundle,
          isFallback,
          assets: [],
        });
        order.push(lid);
      }

      const loopObj = byLoop.get(lid);
      const assetDetail = this.assetsById.get(slot.asset_id);
      const orderIndex = slot.order_index !== undefined
        ? slot.order_index
        : (assetDetail?.order_index !== undefined ? assetDetail.order_index : null);
      const campaignId = slot.campaign_id ?? loopObj.campaignId ?? assetDetail?.campaign_id ?? null;

      loopObj.assets.push({
        asset_id: slot.asset_id,
        order_index: orderIndex,
        campaign_id: campaignId,
      });
    }

    return order.map((lid) => byLoop.get(lid));
  }

  // Build the pass list for a loop:
  // 1. Explicit assets (order_index != null), sorted by order_index ASC.
  // 2. Unordered assets (order_index == null), sorted by lastPlayedAt ASC (least recently played).
  _buildPassList(loop) {
    const assets = loop.assets || [];
    const seen = new Set();
    const uniqueAssets = [];
    for (let i = 0; i < assets.length; i++) {
      const a = assets[i];
      if (!seen.has(a.asset_id)) {
        seen.add(a.asset_id);
        uniqueAssets.push({ ...a, _origIndex: i });
      }
    }

    const explicit = uniqueAssets
      .filter((a) => a.order_index !== null && a.order_index !== undefined)
      .sort((a, b) => {
        const diff = Number(a.order_index) - Number(b.order_index);
        if (diff !== 0) return diff;
        return a._origIndex - b._origIndex;
      });

    const unordered = uniqueAssets
      .filter((a) => a.order_index === null || a.order_index === undefined)
      .sort((a, b) => {
        const tA = this.work.assets[a.asset_id]?.lastPlayedAt ?? 0;
        const tB = this.work.assets[b.asset_id]?.lastPlayedAt ?? 0;
        if (tA !== tB) {
          return tA - tB;
        }
        return a._origIndex - b._origIndex;
      });

    return [...explicit, ...unordered].map((a) => a.asset_id);
  }

  _getPassList(loop) {
    if (this.activeLoopId === loop.loopId && this.activeLoopPassList) {
      return this.activeLoopPassList;
    }
    const passList = this._buildPassList(loop);
    this.activeLoopPassList = passList;
    this.activeLoopId = loop.loopId;
    return passList;
  }

  _findLoopIdForAsset(assetId) {
    for (const loop of this.primaryLoops) {
      if (loop.assets.some((a) => a.asset_id === assetId)) return loop.loopId;
    }
    for (const loop of this.allFallbackLoops) {
      if (loop.assets.some((a) => a.asset_id === assetId)) return loop.loopId;
    }
    return null;
  }

  footprint(assetId) {
    return this.quota.assets?.[assetId]?.footprint ?? 1;
  }

  // Effective "plays in the last hour": the server baseline still counts only
  // while its snapshot is itself within the hour, plus local plays in-window.
  _playsLastHour(assetId, nowMs) {
    const w = this.work.assets[assetId];
    if (!w) return 0;
    const cutoff = nowMs - 3600_000;
    const local = w.recent.filter((t) => t >= cutoff).length;
    const base = this._asOf >= cutoff ? w.baseHour : 0;
    return base + local;
  }

  _withinCampaign(detail, q, now) {
    const today = ymd(now);
    const startDate = detail?.campaign_start_date ?? q?.campaign_start_date;
    const endDate = detail?.campaign_end_date ?? q?.campaign_end_date;
    if (startDate && today < startDate) return false;
    if (endDate && today > endDate) return false;
    return true;
  }

  _withinPlaybackWindow(detail, q, now) {
    const slots = detail?.playback_times ?? q?.playback_times;
    if (!slots || slots.length === 0) return true;
    const cur = now.getHours() * 60 + now.getMinutes();
    return slots.some((slot) => {
      const [h, m] = slot.split(":");
      const s = Number(h) * 60 + Number(m);
      const diff = Math.abs(cur - s);
      return Math.min(diff, 1440 - diff) <= PLAYBACK_TOLERANCE_MIN;
    });
  }

  _eligible(assetId, now) {
    const detail = this.assetsById.get(assetId);
    if (!detail) return 'missing_asset';
    const q = this.quota.assets?.[assetId] || {};
    const w = this.work.assets[assetId] || { spotsRemaining: Infinity, playsToday: 0 };

    if (w.spotsRemaining <= 0) return 'no_spots_remaining';
    if (!this._withinCampaign(detail, q, now)) return 'outside_flight_dates';
    if (!this._withinPlaybackWindow(detail, q, now)) return 'outside_playback_window';

    if (q.max_plays_per_hour != null &&
        this._playsLastHour(assetId, now.getTime()) >= q.max_plays_per_hour) {
      return 'hourly_exceeded';
    }
    // Pacing (rule 4): not yet due if less than PACING_FACTOR of the ideal
    // inter-play interval has elapsed since the last play. Spaces plays across the
    // hour rather than letting an asset's quota bunch up at the top of the hour.
    if (q.max_plays_per_hour != null && q.max_plays_per_hour > 0 && w.lastPlayedAt != null) {
      const idealIntervalMs = 3600_000 / q.max_plays_per_hour;
      if (now.getTime() - w.lastPlayedAt < PACING_FACTOR * idealIntervalMs) {
        return 'pacing_gap';
      }
    }
    if (q.max_daily_plays != null && w.playsToday >= q.max_daily_plays) {
      return 'daily_exceeded';
    }
    // Loop daily spot cap (charged by footprint).
    const loopId = detail.loop_id || this._findLoopIdForAsset(assetId);
    const loopQ = loopId != null ? this.quota.loops?.[loopId] : null;
    if (loopQ && loopQ.max_daily_spots != null) {
      const spent = this.work.loops[loopId]?.spotsToday ?? 0;
      if (spent + this.footprint(assetId) > loopQ.max_daily_spots) return 'loop_daily_exceeded';
    }
    // Don't play back-to-back with a conflicting asset.
    if (q.conflicts && q.conflicts.length > 0) {
      for (const conflict of q.conflicts) {
        const slots = Math.max(1, conflict.slots || 1);
        const recentHistory = this.history.slice(-slots);
        if (recentHistory.includes(conflict.id)) {
          return 'conflict';
        }
      }
    }
    return 'valid';
  }

  _build(assetId, isOverride) {
    const detail = this.assetsById.get(assetId);
    return {
      asset_id: assetId,
      asset_name: detail?.name ?? detail?.asset_name ?? assetId,
      file_type: detail?.file_type ?? 'video',
      duration_secs: detail?.duration_secs ?? 15,
      loop_id: detail?.loop_id ?? this._findLoopIdForAsset(assetId) ?? null,
      download_url: detail?.download_url ?? null,
      is_override: !!isOverride,
    };
  }

  /**
   * Push an override asset to the front of the priority queue.
   * The asset object should match the shape returned by _build().
   */
  injectOverride(asset) {
    // Definitively deduplicate commands arriving from both WebSocket and /sync poll
    if (asset.override_id) {
      this.processedOverrides = this.processedOverrides || new Set();
      if (this.processedOverrides.has(asset.override_id)) return;
      this.processedOverrides.add(asset.override_id);
    }

    if (!this.overrideQueue.some((o) => o.asset_id === asset.asset_id)) {
      this.overrideQueue.push(asset);
    }
  }

  /**
   * Pick the next asset to play, synchronously. Returns the asset object or
   * null when nothing qualifies (genuine empty state).
   */
  pickNext(now = new Date()) {
    // 1. Overrides: find the first override that does not conflict with history.
    // Conflicting overrides are deferred (held in queue). Non-conflict constraints are bypassed.
    for (let i = 0; i < this.overrideQueue.length; i++) {
      const o = this.overrideQueue[i];
      const reason = this._eligible(o.asset_id, now);
      if (reason === 'valid' || reason !== 'conflict') {
        this.overrideQueue.splice(i, 1);
        return o;
      }
    }

    let selectedAssetId = null;

    // 2. Primary loops
    const loops = this.primaryLoops;
    if (loops.length > 0) {
      let loopsChecked = 0;

      while (loopsChecked < loops.length && !selectedAssetId) {
        const loop = loops[this.loopIdx];
        const passList = this._getPassList(loop);

        if (loop.isBundle) {
          if (passList.length > 0) {
            if (this.assetIdx === 0) {
              // Atomic bundle: first asset governs whole bundle for this pass
              const firstAssetId = passList[0];
              const firstReason = this._eligible(firstAssetId, now);

              if (firstReason !== 'valid') {
                if (this.onReject) this.onReject(firstAssetId, firstReason);
                // Skip entire bundle loop
                this.loopIdx = (this.loopIdx + 1) % loops.length;
                this.assetIdx = 0;
                this.activeLoopPassList = null;
                this.activeLoopId = null;
              } else {
                selectedAssetId = firstAssetId;
                this.lastPickedPrimaryId = firstAssetId;
                if (passList.length === 1) {
                  this.loopIdx = (this.loopIdx + 1) % loops.length;
                  this.assetIdx = 0;
                  this.activeLoopPassList = null;
                  this.activeLoopId = null;
                } else {
                  this.assetIdx = 1;
                  this.activeLoopPassList = passList;
                  this.activeLoopId = loop.loopId;
                }
              }
            } else {
              // Continuing in the middle of a bundle loop
              const startA = this.assetIdx < passList.length ? this.assetIdx : 0;
              const candidateId = passList[startA];
              const candidateReason = this._eligible(candidateId, now);

              if (candidateReason === 'valid') {
                selectedAssetId = candidateId;
                this.lastPickedPrimaryId = candidateId;
                if (startA + 1 >= passList.length) {
                  this.loopIdx = (this.loopIdx + 1) % loops.length;
                  this.assetIdx = 0;
                  this.activeLoopPassList = null;
                  this.activeLoopId = null;
                } else {
                  this.assetIdx = startA + 1;
                }
              } else {
                if (this.onReject) this.onReject(candidateId, candidateReason);
                // Bundle candidate failed: skip the rest of the bundle
                this.loopIdx = (this.loopIdx + 1) % loops.length;
                this.assetIdx = 0;
                this.activeLoopPassList = null;
                this.activeLoopId = null;
              }
            }
          } else {
            this.loopIdx = (this.loopIdx + 1) % loops.length;
            this.assetIdx = 0;
            this.activeLoopPassList = null;
            this.activeLoopId = null;
          }
        } else {
          // Standard loop
          const startA = this.assetIdx < passList.length ? this.assetIdx : 0;
          let foundInLoop = false;

          for (let a = startA; a < passList.length; a++) {
            const candidateId = passList[a];
            const reason = this._eligible(candidateId, now);

            if (reason === 'valid') {
              selectedAssetId = candidateId;
              this.lastPickedPrimaryId = candidateId;
              foundInLoop = true;
              if (a + 1 >= passList.length) {
                this.loopIdx = (this.loopIdx + 1) % loops.length;
                this.assetIdx = 0;
                this.activeLoopPassList = null;
                this.activeLoopId = null;
              } else {
                this.assetIdx = a + 1;
                this.activeLoopPassList = passList;
                this.activeLoopId = loop.loopId;
              }
              break;
            } else {
              if (this.onReject) this.onReject(candidateId, reason);
            }
          }

          if (!foundInLoop) {
            this.loopIdx = (this.loopIdx + 1) % loops.length;
            this.assetIdx = 0;
            this.activeLoopPassList = null;
            this.activeLoopId = null;
          }
        }

        // If primary loop yielded no eligible assets, check campaign-specific fallbacks
        if (!selectedAssetId && loop.campaignId) {
          const cFallbacks = this.campaignFallbackLoops.get(loop.campaignId);
          if (cFallbacks && cFallbacks.length > 0) {
            let cCursor = this.campaignFallbackCursors.get(loop.campaignId) || 0;
            let fbAttempts = 0;

            while (fbAttempts < cFallbacks.length && !selectedAssetId) {
              const fbLoop = cFallbacks[cCursor % cFallbacks.length];
              const fbPassList = this._buildPassList(fbLoop);

              for (const candidateId of fbPassList) {
                const fbReason = this._eligible(candidateId, now);
                if (fbReason === 'valid' || (fbReason !== 'conflict' && fbReason !== 'missing_asset' && fbReason !== 'outside_flight_dates' && fbReason !== 'outside_playback_window')) {
                  selectedAssetId = candidateId;
                  this.campaignFallbackCursors.set(loop.campaignId, (cCursor + 1) % cFallbacks.length);
                  break;
                } else {
                  if (this.onReject) this.onReject(candidateId, fbReason);
                }
              }

              cCursor++;
              fbAttempts++;
            }
          }
        }

        loopsChecked++;
      }
    }

    if (selectedAssetId) {
      return this._build(selectedAssetId, false);
    }

    // 3. Global fallback loops
    const fbCandidates = (this.globalFallbackLoops && this.globalFallbackLoops.length > 0)
      ? this.globalFallbackLoops
      : this.allFallbackLoops;

    if (fbCandidates && fbCandidates.length > 0) {
      let fbAttempts = 0;
      while (fbAttempts < fbCandidates.length && !selectedAssetId) {
        const fbLoop = fbCandidates[this.globalFallbackCursor % fbCandidates.length];
        const fbPassList = this._buildPassList(fbLoop);

        for (const candidateId of fbPassList) {
          const fbReason = this._eligible(candidateId, now);
          if (fbReason === 'valid' || (fbReason !== 'conflict' && fbReason !== 'missing_asset' && fbReason !== 'outside_flight_dates' && fbReason !== 'outside_playback_window')) {
            selectedAssetId = candidateId;
            this.globalFallbackCursor = (this.globalFallbackCursor + 1) % fbCandidates.length;
            break;
          } else {
            if (this.onReject) this.onReject(candidateId, fbReason);
          }
        }

        fbAttempts++;
        if (!selectedAssetId) {
          this.globalFallbackCursor = (this.globalFallbackCursor + 1) % fbCandidates.length;
        }
      }
    }

    // 4. Flat fallback list fallback (emergency / backwards compat)
    if (!selectedAssetId && this.schedule.fallback && this.schedule.fallback.length > 0) {
      const fallback = this.schedule.fallback;
      for (let i = 0; i < fallback.length; i++) {
        const idx = (this.globalFallbackCursor + i) % fallback.length;
        const assetId = fallback[idx].asset_id;
        if (this.assetsById.has(assetId)) {
          const reason = this._eligible(assetId, now);
          if (reason === 'valid' || (reason !== 'conflict' && reason !== 'missing_asset' && reason !== 'outside_flight_dates' && reason !== 'outside_playback_window')) {
            this.globalFallbackCursor = (idx + 1) % fallback.length;
            selectedAssetId = assetId;
            break;
          } else if (reason === 'conflict') {
            if (this.onReject) this.onReject(assetId, reason);
          }
        }
      }
    }

    if (selectedAssetId) {
      return this._build(selectedAssetId, false);
    }

    return null;
  }

  /**
   * Record that an asset started playing: advances local counters and returns
   * the play event to persist + flush. Call once per play (on play start).
   */
  recordPlay(asset, now = new Date()) {
    const event = {
      client_event_id: genUuid(),
      asset_id: asset.asset_id,
      loop_id: asset.loop_id ?? null,
      played_at: now.toISOString(),
      was_override: !!asset.is_override,
      footprint: this.footprint(asset.asset_id),
      synced: false,
    };
    this._apply(event, true);
    return event;
  }

  // Advance working counters for one play event (used by recordPlay and replay).
  _apply(event, isLive) {
    const w = (this.work.assets[event.asset_id] ||= {
      spotsRemaining: Infinity,
      playsToday: 0,
      recent: [],
      baseHour: 0,
      lastPlayedAt: null,
    });

    if (w.spotsRemaining !== Infinity) w.spotsRemaining -= 1;
    w.playsToday += 1;
    const t = Date.parse(event.played_at) || Date.now();
    w.recent.push(t);
    w.lastPlayedAt = w.lastPlayedAt == null ? t : Math.max(w.lastPlayedAt, t);

    if (event.loop_id != null) {
      const l = (this.work.loops[event.loop_id] ||= { spotsToday: 0 });
      l.spotsToday += event.footprint ?? 1;
    }
    if (isLive) {
      this.lastAssetId = event.asset_id;
      this.history.push(event.asset_id);
    }
  }
}
