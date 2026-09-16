import { test } from 'node:test';
import assert from 'node:assert';
import React, { act, useRef } from 'react';
import ReactDOM from 'react-dom/client';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { usePlaybackLoop } from '../../resources/js/player/hooks/usePlaybackLoop.js';
import { Scheduler } from '../../resources/js/player/lib/scheduler.js';

// Setup minimal DOM environment for React 19 in Node
globalThis.window = globalThis;
globalThis.navigator = { userAgent: 'Node' };
globalThis.HTMLIFrameElement = class HTMLIFrameElement {};
globalThis.HTMLElement = class HTMLElement {};
globalThis.IS_REACT_ACT_ENVIRONMENT = true;

const doc = {
  nodeType: 9,
  defaultView: globalThis,
  createElement: (tag) => ({
    tagName: tag.toUpperCase(),
    nodeType: 1,
    style: {},
    children: [],
    appendChild: () => {},
    removeChild: () => {},
    setAttribute: () => {},
    getAttribute: () => null,
    addEventListener: () => {},
    removeEventListener: () => {},
    ownerDocument: doc,
  }),
  createElementNS: () => ({ nodeType: 1, style: {}, ownerDocument: doc }),
  createTextNode: () => ({ nodeType: 3, ownerDocument: doc }),
  addEventListener: () => {},
  removeEventListener: () => {},
};
globalThis.document = doc;

test('Playback loop resumes and plays downloaded asset after starting with no media scheduled', async () => {
  const qc = new QueryClient();
  let hookResult = null;

  function TestComponent({ enabled, getNextAsset }) {
    const interruptRef = useRef(false);
    hookResult = usePlaybackLoop({ interruptRef, enabled, getNextAsset });
    return null;
  }

  const root = ReactDOM.createRoot(doc.createElement('div'));
  let nextAsset = null;
  const getNextAsset = () => nextAsset;

  // 1. Board player starts with no media scheduled
  await act(async () => {
    root.render(
      React.createElement(QueryClientProvider, { client: qc },
        React.createElement(TestComponent, { enabled: true, getNextAsset })
      )
    );
  });

  assert.strictEqual(hookResult.currentAsset, null);
  assert.strictEqual(hookResult.noAsset, true);

  // 2. An asset is given play tokens; prefetching starts -> enabled becomes false
  await act(async () => {
    root.render(
      React.createElement(QueryClientProvider, { client: qc },
        React.createElement(TestComponent, { enabled: false, getNextAsset })
      )
    );
  });

  // 3. Asset is downloaded and ready in local scheduler
  nextAsset = { id: 101, name: 'Billboard Ad 1', file_type: 'video' };

  // 4. Prefetch completes -> enabled becomes true
  await act(async () => {
    root.render(
      React.createElement(QueryClientProvider, { client: qc },
        React.createElement(TestComponent, { enabled: true, getNextAsset })
      )
    );
  });

  // Allow microtasks / state updates to settle
  await new Promise((resolve) => setTimeout(resolve, 50));

  // Assert: It should now be playing the asset, not stuck waiting for browser refresh!
  assert.notStrictEqual(
    hookResult.currentAsset,
    null,
    'Asset should play after download without requiring a browser refresh'
  );
  assert.strictEqual(hookResult.currentAsset.id, 101);
  assert.strictEqual(hookResult.noAsset, false);

  // Clean up
  await act(async () => {
    root.unmount();
  });
});

test('Playback loop plays immediately via interrupt when an already-cached asset is assigned to an idle player', async () => {
  const qc = new QueryClient();
  let hookResult = null;
  let interruptFn = null;

  function TestComponent({ enabled, getNextAsset }) {
    const interruptRef = useRef(false);
    hookResult = usePlaybackLoop({ interruptRef, enabled, getNextAsset });
    interruptFn = hookResult.interrupt;
    return null;
  }

  const root = ReactDOM.createRoot(doc.createElement('div'));
  let nextAsset = null;
  const getNextAsset = () => nextAsset;

  // 1. Board player starts with no media scheduled
  await act(async () => {
    root.render(
      React.createElement(QueryClientProvider, { client: qc },
        React.createElement(TestComponent, { enabled: true, getNextAsset })
      )
    );
  });

  assert.strictEqual(hookResult.currentAsset, null);
  assert.strictEqual(hookResult.noAsset, true);

  // 2. An asset is given play tokens, and is already cached (enabled stays true)
  nextAsset = { id: 202, name: 'Cached Ad 2', file_type: 'photo' };

  // 3. sync arrives and interrupts the idle state
  await act(async () => {
    interruptFn();
  });

  await new Promise((resolve) => setTimeout(resolve, 50));

  // Assert: It should now be playing the asset immediately
  assert.notStrictEqual(
    hookResult.currentAsset,
    null,
    'Cached asset should play immediately on sync without waiting for 60s timeout'
  );
  assert.strictEqual(hookResult.currentAsset.id, 202);
  assert.strictEqual(hookResult.noAsset, false);

  // Clean up
  await act(async () => {
    root.unmount();
  });
});

test('Scheduler skips still-downloading assets in favor of ready assets, and includes newly downloaded asset once ready', () => {
  const assetsById = new Map([
    ['asset-1', { id: 'asset-1', name: 'Ad 1', duration_secs: 15, file_type: 'video', loop_id: 1 }],
    ['asset-2', { id: 'asset-2', name: 'Ad 2', duration_secs: 15, file_type: 'video', loop_id: 1 }],
    ['asset-3', { id: 'asset-3', name: 'Ad 3', duration_secs: 15, file_type: 'video', loop_id: 1 }],
  ]);

  const readySet = new Set(['asset-1', 'asset-2']); // asset-3 is still downloading!
  const isAssetReady = (id) => readySet.has(id);

  const scheduler = new Scheduler({
    schedule: {
      primary: [
        { asset_id: 'asset-1', loop_id: 1 },
        { asset_id: 'asset-2', loop_id: 1 },
        { asset_id: 'asset-3', loop_id: 1 },
      ],
      fallback: [],
    },
    quota: { seconds_per_spot: 15, assets: {}, loops: {} },
    assetsById,
    isAssetReady,
  });

  // Pick 1: Should pick asset-1
  const pick1 = scheduler.pickNext();
  assert.strictEqual(pick1.asset_id, 'asset-1');

  // Pick 2: Should pick asset-2
  const pick2 = scheduler.pickNext();
  assert.strictEqual(pick2.asset_id, 'asset-2');

  // Pick 3: asset-3 is NOT ready yet, so it should loop back to ready asset-1!
  const pick3 = scheduler.pickNext();
  assert.strictEqual(pick3.asset_id, 'asset-1', 'Should skip downloading asset-3 and pick ready asset-1');

  // Now background download of asset-3 completes!
  readySet.add('asset-3');

  // Pick 4: Should pick asset-2
  const pick4 = scheduler.pickNext();
  assert.strictEqual(pick4.asset_id, 'asset-2');

  // Pick 5: asset-3 is now ready, so it should be picked in rotation!
  const pick5 = scheduler.pickNext();
  assert.strictEqual(pick5.asset_id, 'asset-3', 'Newly downloaded asset-3 should enter rotation gracefully');
});

test('Playback loop continues active playback uninterrupted when a new asset is added and downloading in background', async () => {
  const qc = new QueryClient();
  let hookResult = null;

  function TestComponent({ enabled, getNextAsset }) {
    const interruptRef = useRef(false);
    hookResult = usePlaybackLoop({ interruptRef, enabled, getNextAsset });
    return null;
  }

  const root = ReactDOM.createRoot(doc.createElement('div'));
  let currentPick = { id: 'asset-1', name: 'Ad 1' };
  const getNextAsset = () => currentPick;

  // 1. Board is actively playing asset-1 (enabled=true)
  await act(async () => {
    root.render(
      React.createElement(QueryClientProvider, { client: qc },
        React.createElement(TestComponent, { enabled: true, getNextAsset })
      )
    );
  });

  assert.strictEqual(hookResult.currentAsset?.id, 'asset-1');

  // 2. A new asset-2 is added to the loop and begins downloading in background.
  // Playback loop stays enabled because hasReadyAssets is true.
  // The playing asset MUST NOT be cleared or set to null!
  await act(async () => {
    root.render(
      React.createElement(QueryClientProvider, { client: qc },
        React.createElement(TestComponent, { enabled: true, getNextAsset })
      )
    );
  });

  assert.strictEqual(
    hookResult.currentAsset?.id,
    'asset-1',
    'Active playback must not be stopped when new asset downloads in background'
  );

  // 3. Asset-1 naturally ends. Next asset is picked.
  currentPick = { id: 'asset-2', name: 'Ad 2' };
  await act(async () => {
    hookResult.onVideoEnded();
  });

  await new Promise((resolve) => setTimeout(resolve, 50));

  assert.strictEqual(hookResult.currentAsset?.id, 'asset-2');

  // Clean up
  await act(async () => {
    root.unmount();
  });
});
