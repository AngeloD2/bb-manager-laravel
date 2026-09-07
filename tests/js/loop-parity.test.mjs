// Cross-engine parity: the JS scheduler must make the same deterministic loop-rule
// decisions (order, loop-completion, daily-cap exclusion) as the PHP queue
// generator. Both sides assert against the SAME canonical fixture; the PHP
// counterpart is tests/Feature/LoopParityTest.php. If either
// engine drifts on those rules, its parity test fails.
//
// Run: npm test   (uses tests/js/loader.mjs so Vite-style imports load in Node)
import { test } from 'node:test';
import assert from 'node:assert';
import { readFileSync, existsSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { Scheduler } from '../../resources/js/player/lib/scheduler.js';

// Single source of truth: the same fixture the PHP parity test asserts against.
const FIXTURE_URL = new URL(
  '../fixtures/loop_parity.json',
  import.meta.url,
);

test('JS scheduler matches the canonical parity fixture', { skip: existsSync(fileURLToPath(FIXTURE_URL)) ? false : 'canonical fixture not found' }, () => {
  const fx = JSON.parse(readFileSync(FIXTURE_URL, 'utf8'));

  // The server emits primary already ordered by loop position then order_index;
  // reproduce that ordering from the fixture so we test the same input shape.
  const loopPos = new Map(fx.loopOrder.map((id, i) => [id, i]));
  const primaryAssets = fx.assets
    .filter((a) => !fx.loops.find((l) => l.id === a.loop)?.isFallback)
    .sort(
      (x, y) =>
        (loopPos.get(x.loop) ?? Infinity) - (loopPos.get(y.loop) ?? Infinity) ||
        x.orderIndex - y.orderIndex,
    );

  const schedule = {
    primary: primaryAssets.map((a) => ({ asset_id: a.id, loop_id: a.loop })),
    fallback: [],
  };

  const quotaAssets = {};
  const assetsById = new Map();
  for (const a of fx.assets) {
    quotaAssets[a.id] = {
      footprint: Math.max(1, Math.ceil(a.durationSecs / fx.secondsPerSpot)),
      plays_today: a.playsToday,
      max_daily_plays: a.maxDailyPlays,
      play_spots_remaining: a.playSpotsRemaining,
    };
    assetsById.set(a.id, {
      name: a.id,
      file_type: 'video',
      duration_secs: a.durationSecs,
      loop_id: a.loop,
      download_url: 'x',
    });
  }

  const s = new Scheduler({
    schedule,
    quota: { seconds_per_spot: fx.secondsPerSpot, assets: quotaAssets, loops: {} },
    assetsById,
  });

  const got = [];
  for (let i = 0; i < fx.picks; i++) got.push(s.pickNext().asset_id);

  assert.deepStrictEqual(got, fx.expected);
});
