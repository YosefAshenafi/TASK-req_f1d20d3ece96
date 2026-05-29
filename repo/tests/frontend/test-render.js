'use strict';
/**
 * Unit tests for publish-time rule rendering helpers from
 * frontend/pages/activities.html: normalizeActivitySupplies() and parseTagInput().
 * Run with: node tests/frontend/test-render.js
 * No external dependencies — Node.js built-in assert only.
 */
const assert = require('assert');

// ── Inline definitions mirroring activities.html exactly ─────────────────────

function fmtDatetimeLocal(str) {
  if (!str) return '';
  return String(str).slice(0, 16).replace(' ', 'T');
}

function normalizeActivitySupplies(raw) {
  if (Array.isArray(raw)) return raw.map(s => String(s).trim()).filter(Boolean);
  if (typeof raw === 'string' && raw) return raw.split(',').map(s => s.trim()).filter(Boolean);
  return [];
}

function parseTagInput(str) {
  if (!str || typeof str !== 'string') return [];
  return [...new Set(str.split(',').map(t => t.trim()).filter(Boolean))];
}

// ── normalizeActivitySupplies ─────────────────────────────────────────────────

// Array input: values passed through as trimmed strings
assert.deepStrictEqual(
  normalizeActivitySupplies(['markers', 'paper', 'glue']),
  ['markers', 'paper', 'glue'],
  'array of strings passes through unchanged'
);

// Array input: values are coerced to string and trimmed
assert.deepStrictEqual(
  normalizeActivitySupplies(['  markers  ', '  paper']),
  ['markers', 'paper'],
  'array values are trimmed'
);

// Array input: empty/blank items dropped
assert.deepStrictEqual(
  normalizeActivitySupplies(['markers', '', '  ', 'paper']),
  ['markers', 'paper'],
  'empty and blank array entries are dropped'
);

// String input: comma-split, trim, filter
assert.deepStrictEqual(
  normalizeActivitySupplies('markers, paper, glue'),
  ['markers', 'paper', 'glue'],
  'comma-separated string is split and trimmed'
);

assert.deepStrictEqual(
  normalizeActivitySupplies('markers,,  ,paper'),
  ['markers', 'paper'],
  'empty segments between commas are dropped from string'
);

// Null / undefined / non-string/non-array inputs return empty
assert.deepStrictEqual(normalizeActivitySupplies(null),      [], 'null → empty');
assert.deepStrictEqual(normalizeActivitySupplies(undefined), [], 'undefined → empty');
assert.deepStrictEqual(normalizeActivitySupplies(0),         [], 'number → empty');
assert.deepStrictEqual(normalizeActivitySupplies(''),        [], 'empty string → empty');

// ── parseTagInput ─────────────────────────────────────────────────────────────

// Basic comma-separated input
assert.deepStrictEqual(
  parseTagInput('outdoor, sports, teamwork'),
  ['outdoor', 'sports', 'teamwork'],
  'comma-separated tag string is split and trimmed'
);

// Leading/trailing whitespace per token is trimmed
assert.deepStrictEqual(
  parseTagInput('  outdoor  ,  sports  '),
  ['outdoor', 'sports'],
  'each tag is trimmed of surrounding whitespace'
);

// Duplicates are removed (Set dedup)
assert.deepStrictEqual(
  parseTagInput('sports, outdoor, sports'),
  ['sports', 'outdoor'],
  'duplicate tags are deduplicated'
);

// Empty segments ignored
assert.deepStrictEqual(
  parseTagInput('outdoor,,, sports,,'),
  ['outdoor', 'sports'],
  'empty segments from consecutive commas are dropped'
);

// Single tag
assert.deepStrictEqual(parseTagInput('outdoor'), ['outdoor'], 'single tag works');

// Null / empty / non-string inputs return empty
assert.deepStrictEqual(parseTagInput(null),      [], 'null → empty');
assert.deepStrictEqual(parseTagInput(''),         [], 'empty string → empty');
assert.deepStrictEqual(parseTagInput(undefined),  [], 'undefined → empty');

// ── fmtDatetimeLocal ──────────────────────────────────────────────────────────

// Space-separated datetime string → T-separator for datetime-local input
assert.strictEqual(
  fmtDatetimeLocal('2024-06-15 14:30:00'),
  '2024-06-15T14:30',
  'space separator replaced with T, seconds truncated'
);

// Already T-separated (ISO 8601) — still truncated to 16 chars
assert.strictEqual(
  fmtDatetimeLocal('2024-01-05T09:05:00'),
  '2024-01-05T09:05',
  'T-separated string truncated to HH:MM precision'
);

// Null / empty / falsy → empty string (safe for datetime-local value attribute)
assert.strictEqual(fmtDatetimeLocal(null),      '', 'null → empty string');
assert.strictEqual(fmtDatetimeLocal(''),         '', 'empty string → empty string');
assert.strictEqual(fmtDatetimeLocal(undefined),  '', 'undefined → empty string');

// Midnight
assert.strictEqual(
  fmtDatetimeLocal('2024-12-25 00:00:00'),
  '2024-12-25T00:00',
  'midnight datetime preserved correctly'
);

console.log('All normalizeActivitySupplies(), parseTagInput(), and fmtDatetimeLocal() tests passed.');
