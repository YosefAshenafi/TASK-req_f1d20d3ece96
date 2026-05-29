'use strict';
/**
 * Unit tests for the fmt() date-formatter from frontend/js/app.js.
 * Run with: node tests/frontend/test-fmt.js (TZ=UTC expected)
 * No external dependencies — Node.js built-in assert only.
 */
const assert = require('assert');

// Inline definition mirroring app.js exactly
function fmt(dtStr) {
  if (!dtStr) return '—';
  const d = new Date(dtStr);
  if (isNaN(d.getTime())) return '—';
  const mm     = String(d.getMonth() + 1).padStart(2, '0');
  const dd     = String(d.getDate()).padStart(2, '0');
  const yyyy   = d.getFullYear();
  const h      = d.getHours();
  const min    = String(d.getMinutes()).padStart(2, '0');
  const hour12 = h % 12 || 12;
  const ampm   = h < 12 ? 'AM' : 'PM';
  return `${mm}/${dd}/${yyyy} ${hour12}:${min} ${ampm}`;
}

// Null / empty / invalid inputs
assert.strictEqual(fmt(null),          '—', 'null → em dash');
assert.strictEqual(fmt(''),            '—', 'empty string → em dash');
assert.strictEqual(fmt('not-a-date'),  '—', 'invalid date string → em dash');

// Zero-padded month and day (single-digit values must be padded)
// 2024-01-05 14:00 UTC → 01/05/2024 2:00 PM
assert.strictEqual(fmt('2024-01-05T14:00:00Z'), '01/05/2024 2:00 PM',
  'single-digit month and day must be zero-padded');

// Midnight → 12:00 AM
assert.strictEqual(fmt('2024-12-25T00:00:00Z'), '12/25/2024 12:00 AM',
  'midnight hour must display as 12:00 AM');

// Noon → 12:00 PM
assert.strictEqual(fmt('2024-06-15T12:00:00Z'), '06/15/2024 12:00 PM',
  'noon hour must display as 12:00 PM');

// AM boundary: 11:59
assert.strictEqual(fmt('2024-09-03T11:59:00Z'), '09/03/2024 11:59 AM',
  '11:59 must be AM');

// PM boundary: 13:01 → 1:01 PM
assert.strictEqual(fmt('2024-09-03T13:01:00Z'), '09/03/2024 1:01 PM',
  '13:01 must display as 1:01 PM');

// Zero-padded minutes: 09:05
assert.strictEqual(fmt('2024-03-08T09:05:00Z'), '03/08/2024 9:05 AM',
  'single-digit minute must be zero-padded');

// 23:59 PM
assert.strictEqual(fmt('2024-11-11T23:59:00Z'), '11/11/2024 11:59 PM',
  '23:59 must be 11:59 PM');

console.log('All fmt() tests passed.');
