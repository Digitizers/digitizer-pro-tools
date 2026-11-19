// Run the real wizard with a checked baseline-update box; no WP or network.
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const source = fs.readFileSync(require.resolve('../modules/onboarding/assets/js/wizard.js'), 'utf8');

async function probe(items, autoUpdate, cleanup = false) {
  const requests = [];
  let click;
  const elements = {
    'dpt-onb-run': { disabled: false, addEventListener: (_, fn) => { click = fn; } },
    'dpt-onb-summary': { textContent: '' },
    'dpt-onb-auto-update': { checked: autoUpdate },
    'dpt-onb-cleanup': { checked: cleanup },
  };
  vm.runInNewContext(source, {
    window: { DPT_ONB: { ajaxUrl: 'fixture-only', nonce: 'fixture-only', strings: {
      summary: '%1$d installed, %2$d activated, %3$d skipped, %4$d failed.',
    } } },
    document: {
      getElementById: id => elements[id],
      querySelectorAll: () => items.map(value => ({ value })),
      querySelector: () => null,
    },
    FormData,
    fetch: async (_, { body }) => {
      const request = Object.fromEntries(body);
      requests.push(request);
      return { json: async () => ({ success: true, data: { id: request.item, outcome: 'installed', results: [] } }) };
    },
  });
  click();
  await new Promise(resolve => setImmediate(resolve));
  assert.equal(elements['dpt-onb-run'].disabled, false, 'wizard finishes');
  return requests;
}

(async () => {
  const enrolled = await probe(['wordfence', 'twentytwentyfive'], true);
  assert.deepEqual(enrolled.map(r => r.action), ['dpt_onb_apply', 'dpt_onb_apply'], 'checked box only applies baseline items');
  assert.ok(enrolled.every(r => r.auto_update === '1'), 'checked box still enrolls baseline items');
  assert.deepEqual(await probe([], true), [], 'checked box with no items never enrolls DPT');
  const plain = await probe(['wordfence'], false);
  assert.equal(plain.length, 1);
  assert.equal(plain[0].auto_update, undefined, 'unchecked box leaves baseline enrollment unchanged');
  const cleanup = await probe([], true, true);
  assert.deepEqual(cleanup.map(r => r.action), ['dpt_onb_cleanup'], 'cleanup-only run still works');
  console.log('7 wizard checks passed');
})().catch(error => { console.error(error); process.exitCode = 1; });
