'use strict';
/**
 * Unit tests for normalizeActivityTags() from frontend/pages/activities.html.
 * Run with: node tests/frontend/test-tags.js
 * No external dependencies — Node.js built-in assert only.
 */
const assert = require('assert');

// Inline definition mirroring activities.html exactly
function normalizeActivityTags(raw) {
  if (!Array.isArray(raw)) return [];
  return raw
    .map(t => (typeof t === 'string' ? t : (t && typeof t === 'object' ? String(t.tag ?? '') : '')))
    .map(s => s.trim())
    .filter(Boolean);
}

// Plain string entries
assert.deepStrictEqual(
  normalizeActivityTags(['sports', 'outdoor']),
  ['sports', 'outdoor'],
  'plain string array passes through unchanged'
);

// Object entries {id, activity_id, tag} — backend relation payload shape
assert.deepStrictEqual(
  normalizeActivityTags([{ id: 1, activity_id: 5, tag: 'sports' }]),
  ['sports'],
  'object entry uses .tag value'
);

assert.deepStrictEqual(
  normalizeActivityTags([
    { id: 1, activity_id: 5, tag: 'sports' },
    { id: 2, activity_id: 5, tag: 'outdoor' },
  ]),
  ['sports', 'outdoor'],
  'multiple object entries all extracted correctly'
);

// Mixed string and object entries
assert.deepStrictEqual(
  normalizeActivityTags(['outdoor', { id: 1, activity_id: 3, tag: 'sports' }]),
  ['outdoor', 'sports'],
  'mixed string and object entries both resolved'
);

// Null, empty string, empty .tag, null .tag — all dropped
assert.deepStrictEqual(
  normalizeActivityTags([null, '', { tag: '' }, { tag: null }, 'valid']),
  ['valid'],
  'null, empty string, and empty/null .tag entries are dropped'
);

// Whitespace-only values dropped after trim
assert.deepStrictEqual(
  normalizeActivityTags(['  ', { tag: '   ' }, 'real']),
  ['real'],
  'whitespace-only values are dropped after trim'
);

// No [object Object] in any output
const objResult = normalizeActivityTags([{ id: 1, activity_id: 2, tag: 'x' }, { id: 2, activity_id: 2, tag: 'y' }]);
objResult.forEach(t =>
  assert.ok(!t.includes('[object'), `tag value must not contain "[object": got "${t}"`)
);

// Non-array inputs return empty
assert.deepStrictEqual(normalizeActivityTags(null),      [], 'null input → empty array');
assert.deepStrictEqual(normalizeActivityTags(undefined), [], 'undefined input → empty array');
assert.deepStrictEqual(normalizeActivityTags('string'),  [], 'string input → empty array');
assert.deepStrictEqual(normalizeActivityTags(42),        [], 'number input → empty array');

console.log('All normalizeActivityTags() tests passed.');
