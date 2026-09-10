import { test } from 'node:test';
import assert from 'node:assert';
import { Scheduler } from '../../resources/js/player/lib/scheduler.js';

function createAssetDetail(id, loopId = null, orderIndex = null, extra = {}) {
  return {
    id,
    name: id,
    file_type: 'video',
    duration_secs: 15,
    loop_id: loopId,
    order_index: orderIndex,
    download_url: 'https://example.com/media/' + id,
    ...extra,
  };
}

test('Explicit assets scheduled before unordered assets and unordered sorted by least recently played', () => {
  const loopId = 'loop-mixed';
  const assetsById = new Map([
    ['e1', createAssetDetail('e1', loopId, 0)],
    ['u1', createAssetDetail('u1', loopId, null)],
    ['u2', createAssetDetail('u2', loopId, null)],
  ]);

  const schedule = {
    primary: [
      { asset_id: 'e1', loop_id: loopId, order_index: 0 },
      { asset_id: 'u1', loop_id: loopId, order_index: null },
      { asset_id: 'u2', loop_id: loopId, order_index: null },
    ],
    loops: {
      [loopId]: { id: loopId, name: 'Mixed Loop', is_fallback: false, is_bundle: false },
    },
    fallback: [],
  };

  const tenMinutesAgo = new Date(Date.now() - 10 * 60 * 1000).toISOString();

  const quota = {
    seconds_per_spot: 15,
    assets: {
      e1: { play_spots_remaining: 100 },
      u1: { play_spots_remaining: 100, last_played_at: tenMinutesAgo },
      u2: { play_spots_remaining: 100, last_played_at: null },
    },
    loops: {
      [loopId]: { max_daily_spots: null, spots_spent_today: 0 },
    },
  };

  const scheduler = new Scheduler({ schedule, quota, assetsById });

  const picked = [];
  let simulatedTime = Date.now();

  for (let i = 0; i < 6; i++) {
    const now = new Date(simulatedTime);
    const asset = scheduler.pickNext(now);
    assert.ok(asset, `Expected an asset for pick ${i}`);
    picked.push(asset.asset_id);
    scheduler.recordPlay(asset, now);
    simulatedTime += 15000;
  }

  // Pass 1: e1 (explicit), u2 (never played, ts=0), u1 (played 10m ago)
  // Pass 2: e1 (explicit), u2 (played 30s ago), u1 (played 15s ago)
  assert.deepStrictEqual(picked, ['e1', 'u2', 'u1', 'e1', 'u2', 'u1']);
});

test('Explicit assets are sorted by order_index ASC', () => {
  const loopId = 'loop-explicit';
  const assetsById = new Map([
    ['p3', createAssetDetail('p3', loopId, 2)],
    ['p1', createAssetDetail('p1', loopId, 0)],
    ['p2', createAssetDetail('p2', loopId, 1)],
  ]);

  // Insert in scrambled order into schedule.primary
  const schedule = {
    primary: [
      { asset_id: 'p3', loop_id: loopId, order_index: 2 },
      { asset_id: 'p1', loop_id: loopId, order_index: 0 },
      { asset_id: 'p2', loop_id: loopId, order_index: 1 },
    ],
    loops: {
      [loopId]: { id: loopId, name: 'Explicit Loop', is_fallback: false, is_bundle: false },
    },
    fallback: [],
  };

  const quota = {
    seconds_per_spot: 15,
    assets: {
      p1: { play_spots_remaining: 100 },
      p2: { play_spots_remaining: 100 },
      p3: { play_spots_remaining: 100 },
    },
  };

  const scheduler = new Scheduler({ schedule, quota, assetsById });

  const picked = [];
  for (let i = 0; i < 3; i++) {
    const asset = scheduler.pickNext();
    picked.push(asset.asset_id);
    scheduler.recordPlay(asset);
  }

  assert.deepStrictEqual(picked, ['p1', 'p2', 'p3']);
});

test('Bundle loops are skipped atomically if the first asset fails constraints or caps', () => {
  const bundleLoopId = 'bundle-1';
  const fallbackLoopId = 'fallback-1';

  const assetsById = new Map([
    ['part1', createAssetDetail('part1', bundleLoopId, 0)],
    ['part2', createAssetDetail('part2', bundleLoopId, 1)],
    ['f1', createAssetDetail('f1', fallbackLoopId, 0)],
  ]);

  const schedule = {
    primary: [
      { asset_id: 'part1', loop_id: bundleLoopId, order_index: 0 },
      { asset_id: 'part2', loop_id: bundleLoopId, order_index: 1 },
    ],
    global_fallback: [
      { asset_id: 'f1', loop_id: fallbackLoopId, campaign_id: null, order_index: 0 },
    ],
    loops: {
      [bundleLoopId]: { id: bundleLoopId, name: 'Bundle', is_bundle: true, is_fallback: false },
      [fallbackLoopId]: { id: fallbackLoopId, name: 'Fallback', is_bundle: false, is_fallback: true },
    },
  };

  // part1 is capped at 1 play today and already played 1
  const quota = {
    seconds_per_spot: 15,
    assets: {
      part1: { play_spots_remaining: 100, max_daily_plays: 1, plays_today: 1 },
      part2: { play_spots_remaining: 100, max_daily_plays: 10, plays_today: 0 },
      f1: { play_spots_remaining: 100 },
    },
  };

  const rejections = [];
  const scheduler = new Scheduler({
    schedule,
    quota,
    assetsById,
    onReject: (id, reason) => rejections.push({ id, reason }),
  });

  const picked = [];
  for (let i = 0; i < 2; i++) {
    const asset = scheduler.pickNext();
    assert.ok(asset, `Expected fallback asset for pick ${i}`);
    picked.push(asset.asset_id);
    scheduler.recordPlay(asset);
  }

  // Because part1 is capped and bundle is atomic, entire bundle is skipped (neither part1 nor part2 plays)
  assert.deepStrictEqual(picked, ['f1', 'f1']);
  assert.ok(rejections.some((r) => r.id === 'part1' && r.reason === 'daily_exceeded'));
  assert.strictEqual(rejections.some((r) => r.id === 'part2'), false, 'part2 should not be evaluated');
});

test('Bundle loops play sequentially when the first asset is eligible', () => {
  const bundleLoopId = 'bundle-loop';
  const normalLoopId = 'normal-loop';

  const assetsById = new Map([
    ['part1', createAssetDetail('part1', bundleLoopId, 0)],
    ['part2', createAssetDetail('part2', bundleLoopId, 1)],
    ['p3', createAssetDetail('p3', normalLoopId, 0)],
  ]);

  const schedule = {
    primary: [
      { asset_id: 'part1', loop_id: bundleLoopId, order_index: 0 },
      { asset_id: 'part2', loop_id: bundleLoopId, order_index: 1 },
      { asset_id: 'p3', loop_id: normalLoopId, order_index: 0 },
    ],
    loops: {
      [bundleLoopId]: { id: bundleLoopId, name: 'Bundle', is_bundle: true, is_fallback: false },
      [normalLoopId]: { id: normalLoopId, name: 'Normal', is_bundle: false, is_fallback: false },
    },
  };

  const quota = {
    seconds_per_spot: 15,
    assets: {
      part1: { play_spots_remaining: 100 },
      part2: { play_spots_remaining: 100 },
      p3: { play_spots_remaining: 100 },
    },
  };

  const scheduler = new Scheduler({ schedule, quota, assetsById });

  const picked = [];
  for (let i = 0; i < 6; i++) {
    const asset = scheduler.pickNext();
    assert.ok(asset);
    picked.push(asset.asset_id);
    scheduler.recordPlay(asset);
  }

  assert.deepStrictEqual(picked, ['part1', 'part2', 'p3', 'part1', 'part2', 'p3']);
});

test('Campaign-specific fallback substituted when campaign primary loop exhausts', () => {
  const primaryLoopId = 'primary-alpha';
  const campaignFbLoopId = 'fb-alpha';
  const globalFbLoopId = 'fb-global';
  const campaignId = 'campaign-alpha';

  const assetsById = new Map([
    ['alpha_p1', createAssetDetail('alpha_p1', primaryLoopId, 0, { campaign_id: campaignId })],
    ['alpha_fb1', createAssetDetail('alpha_fb1', campaignFbLoopId, 0, { campaign_id: campaignId })],
    ['global_fb1', createAssetDetail('global_fb1', globalFbLoopId, 0, { campaign_id: null })],
  ]);

  const schedule = {
    primary: [
      { asset_id: 'alpha_p1', loop_id: primaryLoopId, campaign_id: campaignId, order_index: 0 },
    ],
    campaign_fallback: [
      { asset_id: 'alpha_fb1', loop_id: campaignFbLoopId, campaign_id: campaignId, order_index: 0 },
    ],
    global_fallback: [
      { asset_id: 'global_fb1', loop_id: globalFbLoopId, campaign_id: null, order_index: 0 },
    ],
    loops: {
      [primaryLoopId]: { id: primaryLoopId, name: 'Alpha Primary', campaign_id: campaignId, is_fallback: false, is_bundle: false },
      [campaignFbLoopId]: { id: campaignFbLoopId, name: 'Alpha Fallback', campaign_id: campaignId, is_fallback: true, is_bundle: false },
      [globalFbLoopId]: { id: globalFbLoopId, name: 'Global Fallback', campaign_id: null, is_fallback: true, is_bundle: false },
    },
  };

  // alpha_p1 is capped
  const quota = {
    seconds_per_spot: 15,
    assets: {
      alpha_p1: { play_spots_remaining: 100, max_daily_plays: 1, plays_today: 1 },
      alpha_fb1: { play_spots_remaining: 100 },
      global_fb1: { play_spots_remaining: 100 },
    },
  };

  const scheduler = new Scheduler({ schedule, quota, assetsById });

  const picked = [];
  for (let i = 0; i < 2; i++) {
    const asset = scheduler.pickNext();
    assert.ok(asset);
    picked.push(asset.asset_id);
    scheduler.recordPlay(asset);
  }

  // Substitutes campaign-specific fallback (alpha_fb1), NOT global fallback (global_fb1)
  assert.deepStrictEqual(picked, ['alpha_fb1', 'alpha_fb1']);
});

test('Global fallback used when campaign has no fallback loops', () => {
  const primaryLoopId = 'primary-beta';
  const globalFbLoopId = 'fb-global';
  const campaignId = 'campaign-beta';

  const assetsById = new Map([
    ['beta_p1', createAssetDetail('beta_p1', primaryLoopId, 0, { campaign_id: campaignId })],
    ['global_fb1', createAssetDetail('global_fb1', globalFbLoopId, 0, { campaign_id: null })],
  ]);

  const schedule = {
    primary: [
      { asset_id: 'beta_p1', loop_id: primaryLoopId, campaign_id: campaignId, order_index: 0 },
    ],
    global_fallback: [
      { asset_id: 'global_fb1', loop_id: globalFbLoopId, campaign_id: null, order_index: 0 },
    ],
    loops: {
      [primaryLoopId]: { id: primaryLoopId, name: 'Beta Primary', campaign_id: campaignId, is_fallback: false, is_bundle: false },
      [globalFbLoopId]: { id: globalFbLoopId, name: 'Global Fallback', campaign_id: null, is_fallback: true, is_bundle: false },
    },
  };

  // beta_p1 is capped
  const quota = {
    seconds_per_spot: 15,
    assets: {
      beta_p1: { play_spots_remaining: 100, max_daily_plays: 1, plays_today: 1 },
      global_fb1: { play_spots_remaining: 100 },
    },
  };

  const scheduler = new Scheduler({ schedule, quota, assetsById });

  const picked = [];
  for (let i = 0; i < 2; i++) {
    const asset = scheduler.pickNext();
    assert.ok(asset);
    picked.push(asset.asset_id);
    scheduler.recordPlay(asset);
  }

  assert.deepStrictEqual(picked, ['global_fb1', 'global_fb1']);
});

test('Round-robins multiple campaign-specific fallbacks', () => {
  const primaryLoopId = 'primary-gamma';
  const fb1LoopId = 'fb-gamma-1';
  const fb2LoopId = 'fb-gamma-2';
  const campaignId = 'campaign-gamma';

  const assetsById = new Map([
    ['gamma_p1', createAssetDetail('gamma_p1', primaryLoopId, 0, { campaign_id: campaignId })],
    ['gamma_fb1', createAssetDetail('gamma_fb1', fb1LoopId, 0, { campaign_id: campaignId })],
    ['gamma_fb2', createAssetDetail('gamma_fb2', fb2LoopId, 0, { campaign_id: campaignId })],
  ]);

  const schedule = {
    primary: [
      { asset_id: 'gamma_p1', loop_id: primaryLoopId, campaign_id: campaignId, order_index: 0 },
    ],
    campaign_fallback: [
      { asset_id: 'gamma_fb1', loop_id: fb1LoopId, campaign_id: campaignId, order_index: 0 },
      { asset_id: 'gamma_fb2', loop_id: fb2LoopId, campaign_id: campaignId, order_index: 0 },
    ],
    loops: {
      [primaryLoopId]: { id: primaryLoopId, name: 'Gamma Primary', campaign_id: campaignId, is_fallback: false, is_bundle: false },
      [fb1LoopId]: { id: fb1LoopId, name: 'Gamma FB 1', campaign_id: campaignId, is_fallback: true, is_bundle: false },
      [fb2LoopId]: { id: fb2LoopId, name: 'Gamma FB 2', campaign_id: campaignId, is_fallback: true, is_bundle: false },
    },
  };

  // gamma_p1 is capped
  const quota = {
    seconds_per_spot: 15,
    assets: {
      gamma_p1: { play_spots_remaining: 100, max_daily_plays: 1, plays_today: 1 },
      gamma_fb1: { play_spots_remaining: 100 },
      gamma_fb2: { play_spots_remaining: 100 },
    },
  };

  const scheduler = new Scheduler({ schedule, quota, assetsById });

  const picked = [];
  for (let i = 0; i < 3; i++) {
    const asset = scheduler.pickNext();
    assert.ok(asset);
    picked.push(asset.asset_id);
    scheduler.recordPlay(asset);
  }

  // Round-robin among campaign fallbacks: fb1, fb2, fb1
  assert.deepStrictEqual(picked, ['gamma_fb1', 'gamma_fb2', 'gamma_fb1']);
});
