/* Run with node tests/queue-runner.js; no browser, dependencies or database. */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const script = fs.readFileSync(path.join(__dirname, '../js/queue-runner.js'), 'utf8');

function fixture() {
  let prefilter;
  const elements = [];
  const selectors = {};
  function $(selector) {
    if (!selector.startsWith('<') && selectors[selector]) {
      return selectors[selector];
    }
    const element = {
      selector, content: '', attributes: {}, hidden: false,
      hide() { this.hidden = true; return this; },
      empty() { this.content = ''; return this; },
      text(value) { this.content = value; return this; },
      attr(name, value) { this.attributes[name] = value; return this; },
      addClass() { return this; },
      appendTo(parent) { this.parent = parent; return this; },
      append() { return this; },
      html() { throw new Error('Server response must not be rendered as HTML'); }
    };
    elements.push(element);
    if (!selector.startsWith('<')) { selectors[selector] = element; }
    return element;
  }
  $.ajaxPrefilter = (type, callback) => { assert.equal(type, 'json'); prefilter = callback; };
  const config = {
    qrid: 'com.skvare.collationfix_123',
    urls: ['/queue/ajax/runNext', '/queue/ajax/skipNext', '/queue/ajax/onEnd'],
    logUrl: '/conversion-log', analysisUrl: '/analysis'
  };
  vm.runInNewContext(script, {
    CRM: {$, collationfixRunner: config},
    ts: (message, params = {}) => message.replace(/%(\d+)/g, (_, key) => params[key]),
    Date: {now: () => 60000}
  });
  let succeeded = 0;
  const original = {
    url: config.urls[0], data: {qrid: config.qrid},
    error() { throw new Error('Unsafe core error handler invoked'); },
    success(data, status, xhr) { succeeded++; assert.equal(this, original); assert.equal(status, 'success'); assert.ok(xhr); }
  };
  const options = {...original, data: 'qrid=' + config.qrid};
  prefilter(options, original);
  return {options, original, elements, selectors, prefilter, succeeded: () => succeeded};
}

for (const [label, status, responseText] of [
  ['empty gateway timeout', 504, ''],
  ['HTML server error', 502, '<script>throw new Error("unsafe")</script>'],
  ['truncated JSON', 500, '{"is_error":'],
  ['connection lost', 0, ''],
  ['JSON null', 500, 'null'],
  ['JSON array', 500, '[]']
]) {
  const f = fixture();
  assert.doesNotThrow(() => f.options.error({status, responseText}, 'error'));
  assert.equal(f.succeeded(), 0);
  assert.equal(f.selectors['#crm-queue-runner-buttonset'].hidden, true);
  assert.ok(f.elements.some(e => e.content.includes('HTTP status: ' + status)));
  assert.ok(f.elements.some(e => e.content.includes('may still be running')));
  assert.ok(f.elements.some(e => e.attributes.href === '/conversion-log'));
  assert.ok(f.elements.some(e => e.attributes.href === '/analysis'));
  console.log('PASS: ' + label + ' pauses with diagnostics and recovery links');
}

for (const data of [undefined, null, [], {}, 'OK']) {
  const f = fixture();
  f.options.success(data, 'success', {status: 204});
  assert.equal(f.succeeded(), 0);
  assert.equal(f.selectors['#crm-queue-runner-buttonset'].hidden, true);
}
console.log('PASS: invalid successful responses do not advance the queue');

const f = fixture();
f.options.error({status: 500, responseText: JSON.stringify({is_error: 1, is_continue: 0, exception: '<b>Database unavailable</b>'})}, 'error');
assert.ok(f.elements.some(e => e.selector === '<pre>' && e.content === '<b>Database unavailable</b>'));
console.log('PASS: structured server error is displayed as text');

for (const data of [
  {is_error: 0, is_continue: 1, numberOfItems: 2},
  {is_error: 1, is_continue: 0, exception: 'Task failed'},
  {is_error: 0, is_continue: 0, numberOfItems: 0, redirect_url: '/conversion-log'}
]) {
  const f = fixture();
  f.options.success.call(f.original, data, 'success', {status: 200});
  assert.equal(f.succeeded(), 1);
  assert.equal(f.elements.length, 0);
}
console.log('PASS: normal queue responses retain core handling');

for (const override of [{data: {qrid: 'other_queue'}}, {url: '/other-endpoint'}, {data: undefined}]) {
  const f = fixture();
  const original = {...f.original, ...override};
  const options = {...original};
  f.prefilter(options, original);
  assert.equal(options.error, original.error);
  assert.equal(options.success, original.success);
}
console.log('PASS: unrelated requests retain their callbacks');
