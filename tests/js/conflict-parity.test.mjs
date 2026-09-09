import { test } from 'node:test';
import assert from 'node:assert';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { Scheduler } from '../../resources/js/player/lib/scheduler.js';

const FIXTURE_URL = new URL('../fixtures/conflict_parity.json', import.meta.url);

test('Scheduler conflict parity', () => {
  const fixture = JSON.parse(readFileSync(FIXTURE_URL, 'utf8'));
  let assetsById = new Map();
  let quota = { assets: {} };
  for (const a of fixture.assets) {
    assetsById.set(a.id, a);
    quota.assets[a.id] = { play_spots_remaining: Infinity };
  }

  // Build conflicts as { id, slots } in quota.assets
  const conflictMap = {};
  for (const c of fixture.conflicts) {
    if (!conflictMap[c.asset_id_1]) conflictMap[c.asset_id_1] = [];
    conflictMap[c.asset_id_1].push({ id: c.asset_id_2, slots: c.separation_slots });
    
    // Also apply symmetrically for testing
    if (!conflictMap[c.asset_id_2]) conflictMap[c.asset_id_2] = [];
    conflictMap[c.asset_id_2].push({ id: c.asset_id_1, slots: c.separation_slots });
  }

  for (const [id, confs] of Object.entries(conflictMap)) {
    if (quota.assets[id]) {
      quota.assets[id].conflicts = confs;
    }
  }

  for (const scenario of fixture.scenarios) {
    let schedule = { primary: [] };
    let overrides = [];
    
    if (scenario.is_fallback) {
      schedule.fallback = [{ asset_id: scenario.candidate }];
    } else if (scenario.is_override) {
      overrides.push({ asset_id: scenario.candidate, is_override: true });
    } else {
      schedule.primary = [{ asset_id: scenario.candidate, loop_id: 'loop-1' }];
    }

    const scheduler = new Scheduler({
      schedule,
      quota,
      assetsById,
      history: scenario.history
    });

    for (const o of overrides) {
      scheduler.injectOverride(o);
    }

    const picked = scheduler.pickNext(new Date());
    const wasPicked = picked ? picked.asset_id === scenario.candidate : false;
    
    if (scenario.expected) {
      assert.strictEqual(wasPicked, true, `Expected ${scenario.name} to be picked`);
    } else {
      assert.strictEqual(wasPicked, false, `Expected ${scenario.name} to be skipped`);
      if (scenario.is_override) {
        // Verify it was deferred (still in queue)
        assert.strictEqual(scheduler.overrideQueue.some(o => o.asset_id === scenario.candidate), true, `Expected deferred override for ${scenario.name}`);
      }
    }
  }
});
