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
   * @param {object}   opts.schedule    { primary:[{asset_id,loop_id}], fallback:[...] }
   * @param {object}   opts.quota       server snapshot { seconds_per_spot, assets, loops, ... }
   * @param {Map}      opts.assetsById  asset_id -> media detail (file_type, duration_secs, ...)
   * @param {Array}    [opts.pendingEvents]  unsynced play events to replay onto the snapshot
   */
  constructor({ schedule, quota, assetsById, pendingEvents = [], history = [], onReject = null }) {
    this.onReject = onReject;
    this.reseed({ schedule, quota, assetsById, pendingEvents, history });
  }

  /** Reset to a fresh server snapshot, re-applying any still-unsynced plays. */
  reseed({ schedule, quota, assetsById, pendingEvents = [], history = [] }) {
    const prevPrimaryId = this.lastPickedPrimaryId;
    const prevFallbackCursor = this.fallbackCursor || 0;
    const prevLastAssetId = this.lastAssetId;
    this.history = Array.isArray(history) ? [...history] : [];

    this.schedule = schedule || { primary: [], fallback: [] };
    this.quota = quota || { seconds_per_spot: 15, assets: {}, loops: {} };
    this.assetsById = assetsById || new Map();

    // Group the flat primary schedule into ordered loops so we can finish every
    // eligible asset of a loop before advancing to the next (rules 1 & 2). Grouping
    // by loop_id (preserving first-seen order) is robust even if the server snapshot
    // happens to interleave loops. The cursor points at the next slot to consider.
    this.primaryLoops = this._groupByLoop(this.schedule.primary || []);
    
    this.loopIdx = 0;
    this.assetIdx = 0;
    this.fallbackCursor = prevFallbackCursor;
    this.lastAssetId = prevLastAssetId;
    this.lastPickedPrimaryId = prevPrimaryId;

    if (prevPrimaryId) {
      for (let l = 0; l < this.primaryLoops.length; l++) {
        const loop = this.primaryLoops[l];
        let found = false;
        for (let a = 0; a < loop.assetIds.length; a++) {
          if (loop.assetIds[a] === prevPrimaryId) {
            const nextAsset = a + 1;
            if (nextAsset >= loop.assetIds.length) {
              this.loopIdx = (l + 1) % this.primaryLoops.length;
              this.assetIdx = 0;
            } else {
              this.loopIdx = l;
              this.assetIdx = nextAsset;
            }
            found = true;
            break;
          }
        }
        if (found) break;
      }
    }

    // Override queue is intentionally NOT reset on reseed: an in-flight override
    // must survive a reconciling /sync snapshot arriving between when the server
    // injected it and when the player drains it.
    this.overrideQueue = this.overrideQueue || [];

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
  }

  // Build ordered loops [{ loopId, assetIds: [...] }] from the flat primary list,
  // keeping loops in first-seen order and assets in their scheduled order.
  _groupByLoop(primary) {
    const order = [];
    const byLoop = new Map();
    for (const slot of primary) {
      const lid = slot.loop_id ?? "__none__";
      if (!byLoop.has(lid)) {
        byLoop.set(lid, []);
        order.push(lid);
      }
      byLoop.get(lid).push(slot.asset_id);
    }
    return order.map((lid) => ({ loopId: lid, assetIds: byLoop.get(lid) }));
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

  _withinCampaign(detail, now) {
    const today = ymd(now);
    if (detail.campaign_start_date && today < detail.campaign_start_date) return false;
    if (detail.campaign_end_date && today > detail.campaign_end_date) return false;
    return true;
  }

  _withinPlaybackWindow(detail, now) {
    const slots = detail.playback_times;
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
    if (!this._withinCampaign(detail, now)) return 'outside_flight_dates';
    if (!this._withinPlaybackWindow(detail, now)) return 'outside_playback_window';

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
    const loopId = detail.loop_id;
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
      asset_name: detail?.name,
      file_type: detail?.file_type,
      duration_secs: detail?.duration_secs,
      loop_id: detail?.loop_id ?? null,
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
    // Overrides: find the first override that does not conflict with history.
    // Conflicting overrides are deferred (held in queue).
    for (let i = 0; i < this.overrideQueue.length; i++) {
      const o = this.overrideQueue[i];
      const reason = this._eligible(o.asset_id, now);
      if (reason === 'valid' || reason !== 'conflict') {
        // If it's valid, or if it failed for a reason other than conflict (we bypass
        // non-conflict constraints for overrides), we can play it.
        // Wait, issue says "defer overrides that conflict with history". 
        // Only CONFLICT defers it. Other constraints are bypassed.
        this.overrideQueue.splice(i, 1);
        return o;
      }
    }

    const loops = this.primaryLoops;
    if (loops.length > 0) {
      // Walk each loop at most once per call, starting from the current cursor.
      // Within the current loop we resume at assetIdx; later loops start at 0. The
      // first eligible asset wins, and the cursor advances to just after it — so a
      // loop whose tail is capped/not-due does not stall the rotation.
      for (let l = 0; l < loops.length; l++) {
        const loopIdx = (this.loopIdx + l) % loops.length;
        const loop = loops[loopIdx];
        const start = l === 0 ? this.assetIdx : 0;
        for (let a = start; a < loop.assetIds.length; a++) {
          const assetId = loop.assetIds[a];
          const reason = this._eligible(assetId, now);
          if (reason === 'valid') {
            const nextAsset = a + 1;
            if (nextAsset >= loop.assetIds.length) {
              // Finished this loop's pass — advance to the next loop.
              this.loopIdx = (loopIdx + 1) % loops.length;
              this.assetIdx = 0;
            } else {
              this.loopIdx = loopIdx;
              this.assetIdx = nextAsset;
            }
            this.lastPickedPrimaryId = assetId;
            return this._build(assetId, false);
          } else {
            if (this.onReject) this.onReject(assetId, reason);
          }
        }
      }
    }
    // No primary qualifies (pacing gap or all capped) — fall back to filler.
    const fallback = this.schedule.fallback || [];
    for (let i = 0; i < fallback.length; i++) {
      const idx = (this.fallbackCursor + i) % fallback.length;
      const assetId = fallback[idx].asset_id;
      if (this.assetsById.has(assetId)) {
        const reason = this._eligible(assetId, now);
        if (reason === 'valid' || (reason !== 'conflict' && reason !== 'missing_asset')) {
          // Fallbacks bypass pacing/caps, but MUST pass conflict check.
          this.fallbackCursor = (idx + 1) % fallback.length;
          return this._build(assetId, false);
        } else if (reason === 'conflict') {
          if (this.onReject) this.onReject(assetId, reason);
        }
      }
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
    const w = this.work.assets[event.asset_id];
    if (w) {
      if (w.spotsRemaining !== Infinity) w.spotsRemaining -= 1;
      w.playsToday += 1;
      const t = Date.parse(event.played_at) || Date.now();
      w.recent.push(t);
      w.lastPlayedAt = w.lastPlayedAt == null ? t : Math.max(w.lastPlayedAt, t);
    }
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
