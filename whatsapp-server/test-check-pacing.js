/**
 * Why "Check numbers" looked broken, in numbers.
 *
 * The POS enqueues a batch of ten checks and then waits for the answers inside a
 * single web request. The bridge paces itself between jobs. Before the fix those
 * two numbers did not fit together, so most of every batch was still unanswered
 * when the request gave up, and those numbers stayed NULL - which is the same as
 * never having been checked.
 *
 * Computed rather than slept through: the ranges are what the code in agent.js can
 * produce, and actually waiting them out would take minutes to prove arithmetic.
 */
const BATCH = 10;          // assets/js/marketing.js posts limit: 10
// The POS wait is written in seconds because that is how the PHP reads
// (waAgentWaitForChecks($db, $keys, 30)). The pacing figures below are
// milliseconds, because that is what setTimeout takes. Both are held in one unit
// here - everything is milliseconds - so the comparisons mean something.
const POS_WAIT_OLD = 25_000;
const POS_WAIT_NEW = 30_000;

// agent.js, after the fix. These are the two branches of the pacing decision.
const CHECK_MIN = 200, CHECK_MAX = 500;    // an existence probe
const SEND_MIN = 3000, SEND_MAX = 8000;    // a campaign message

const range = (lo, hi, n) => ({ min: lo * n, max: hi * n });
const sec = (v) => (v / 1000).toFixed(1) + 's';

let fail = 0;
const check = (name, ok, detail = '') => {
  if (!ok) fail++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  - ' + detail : ''}`);
};

console.log(`batch = ${BATCH} checks   POS wait: ${sec(POS_WAIT_OLD)} before, ${sec(POS_WAIT_NEW)} now\n`);

const before = range(SEND_MIN, SEND_MAX, BATCH);
const after = range(CHECK_MIN, CHECK_MAX, BATCH);

console.log(`before (checks paced like a send): ${sec(before.min)} - ${sec(before.max)}`);
console.log(`after  (checks paced like a check): ${sec(after.min)} - ${sec(after.max)}\n`);

check('before: a typical batch outlasted the old wait',
  (before.min + before.max) / 2 > POS_WAIT_OLD,
  `typical ${sec((before.min + before.max) / 2)} against ${sec(POS_WAIT_OLD)}`);

check('before: the old wait could not cover even the fastest batch',
  before.min > POS_WAIT_OLD, `fastest possible was ${sec(before.min)}`);

check('before: the failure was on every batch, not an unlucky one',
  before.min > POS_WAIT_OLD,
  'so roughly the first 3-4 of 10 numbers were recorded and the rest left NULL');

check('after: the slowest batch finishes well inside the new wait',
  after.max < POS_WAIT_NEW * 0.2,
  `${sec(after.max)} worst case against ${sec(POS_WAIT_NEW)}`);

check('after: the margin survives a bridge that is also sending',
  (after.max + SEND_MAX * 2) < POS_WAIT_NEW,
  `a batch behind two paced sends still fits: ${sec(after.max + SEND_MAX * 2)}`);

console.log(`\n${fail} failed`);
process.exit(fail ? 1 : 0);
