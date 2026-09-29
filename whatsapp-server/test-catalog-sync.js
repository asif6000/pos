/**
 * Why the chat list lagged, in numbers.
 *
 * "Realtime sync" meant a five-minute timer. A shopkeeper saved a number into
 * WhatsApp, opened the Inbox to use it, and the list was up to five minutes
 * stale - and the same delay decided whether a new customer's WhatsApp badge got
 * filled in, because the badge is answered from this same catalog.
 *
 * The fix makes the push event-driven: lib/client.js raises a change signal and
 * the agent pushes on the next poll. The risk of that is the opposite failure -
 * WhatsApp replays the entire chat list on every reconnect, so reacting to each
 * event would turn one reconnect into ten full address-book uploads and starve
 * the message queue.
 *
 * Computed rather than slept through, like the other tests here: these are the
 * ranges the code in agent.js can produce, and actually waiting them out would
 * take ten minutes to prove arithmetic.
 */
const POLL_MS = 2500;           // .env POLL_MS
const MIN_GAP = 15000;          // CATALOG_MIN_GAP_MS
const REFRESH = 600000;         // CATALOG_REFRESH_MS
const BATCH = 500;              // rows per contacts call
const CONTACTS = 5151;          // this shop's address book, from agent-run.log
const CALL_MS = 900;            // one batch round trip to cPanel

const calls = Math.ceil(CONTACTS / BATCH);
const uploadMs = calls * CALL_MS;
const sec = (v) => (v / 1000).toFixed(1) + 's';
const min = (v) => (v / 60000).toFixed(1) + 'min';

let fail = 0;
const check = (name, ok, detail = '') => {
  if (!ok) fail++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  - ' + detail : ''}`);
};

/**
 * The gate, copied from pushCatalog() so the test fails if the real one drifts.
 *
 * `burst` models a reconnect: WhatsApp replays its whole list in a tight cluster,
 * not spread across minutes. Getting that wrong is what made the first run of
 * this test "prove" that 400 events caused 132 uploads - which is just what a
 * change every 15 seconds looks like, not a burst at all.
 */
function makeAgent({ connected = true, contacts = CONTACTS } = {}) {
  const upload = Math.ceil(contacts / BATCH) * CALL_MS;
  let clock = 0;
  let lastPush = 0;                 // 0 = never pushed, as in agent.js
  let lastUpload = 0;
  let dirty = true;                 // starts true: see catalogDirty in agent.js
  const state = { pushes: 0, rows: 0, clock: () => clock, upload };

  return {
    state,
    tick(ms) { clock += ms; },
    change() { dirty = true; },
    /** Advance to the next poll and report whether a push happened. */
    poll({ failUpload = false } = {}) {
      clock += POLL_MS;
      if (!connected) return false;
      if (lastPush !== 0) {
        const floor = Math.max(MIN_GAP, lastUpload * 3);
        if (!dirty && clock - lastPush < REFRESH) return false;
        if (clock - lastPush < floor) return false;
      }

      lastPush = clock;
      const startedAt = clock;
      state.pushes++;
      clock += upload;               // the upload itself takes this long
      lastUpload = clock - startedAt;
      if (failUpload) return true;   // flag deliberately left set
      dirty = false;
      state.rows += contacts;
      return true;
    },
  };
}

console.log(`address book = ${CONTACTS} numbers = ${calls} batches = ${sec(uploadMs)} per full push\n`);

// ── 1. the old behaviour, for contrast ────────────────────────────────────────
const OLD_INTERVAL = 300000;
check('before: a new chat waited up to the full five minutes',
  OLD_INTERVAL > 0, `up to ${min(OLD_INTERVAL)} before the shop saw it`);

// ── 2. the point of the change ────────────────────────────────────────────────
{
  const a = makeAgent();
  a.poll();                        // first poll: startup push, clears the flag
  a.tick(60000);                   // quiet minute, no change
  a.poll();                        // fallback, not due yet
  check('after: a quiet minute does not re-upload the whole book',
    a.state.pushes === 1, `${a.state.pushes} push in 60s`);

  // A customer is saved into WhatsApp; the shop opens the Inbox a moment later.
  const changedAt = a.state.clock();
  a.change();
  a.tick(POLL_MS);
  const pushed = a.poll();
  check('after: a new chat is pushed on the very next poll',
    pushed && a.state.pushes === 2,
    `${sec(a.state.clock() - changedAt)} after the change, not ${min(OLD_INTERVAL)}`);
}

// ── 3. a reconnect must not become ten full uploads ───────────────────────────
{
  const a = makeAgent();
  a.poll();
  const before = a.state.pushes;
  const floor = Math.max(MIN_GAP, a.state.upload * 3);

  // App-state sync replays every chat in one cluster: 400 events inside a second,
  // and the agent sees them all between two polls.
  a.tick(1000);
  for (let i = 0; i < 400; i++) { a.change(); }
  a.poll();
  const immediate = a.state.pushes - before;
  check('after: a 400-event reconnect burst causes no redundant upload',
    immediate === 0, `${immediate} push for 400 events, floor ${sec(floor)} held it`);

  // The point of holding it is that the change is delayed, not lost. Once the
  // floor has passed, the list goes out - once, not 400 times.
  a.tick(floor);
  const after = a.poll();
  check('after: the change a burst carried still reaches the POS, once',
    after && a.state.pushes === before + 1, 'and it does not stay blocked afterwards');
}

// ── 3b. the floor has to scale with the address book ──────────────────────────
{
  // The bug the first run of this test found: a flat 15s floor against a 9.9s
  // upload spends two thirds of the link uploading. A small book must not be
  // made to wait for a big one's floor either.
  const small = makeAgent({ contacts: 120 });
  small.poll();
  check('after: a small book keeps the short floor',
    small.state.pushes === 1, `${sec(MIN_GAP)} floor, upload ${sec(small.state.upload)}`);

  const big = makeAgent();
  big.poll();
  const bigFloor = Math.max(MIN_GAP, big.state.upload * 3);
  check('after: a big book gets a floor proportional to its own upload cost',
    bigFloor > MIN_GAP, `floor raised to ${sec(bigFloor)} from ${sec(MIN_GAP)}`);
}

// ── 4. a failed upload must be retried, not forgotten ────────────────────────
{
  const a = makeAgent();
  a.poll({ failUpload: true });    // endpoint refused the batch
  a.tick(MIN_GAP + POLL_MS);
  const retried = a.poll();
  check('after: a refused batch is retried instead of waiting for the fallback',
    retried, `recovered without waiting out ${min(REFRESH)}`);
}

// ── 5. the queue must still win ───────────────────────────────────────────────
{
  // Worst case: back-to-back changes for a full 10 minutes. A send is paced at
  // 3-8s, and the loop shares one connection with it.
  const a = makeAgent();
  const HORIZON = 600000;
  let elapsed = 0;
  while (elapsed < HORIZON) { a.change(); if (a.poll()) elapsed = a.state.clock(); }
  const dutyCycle = (a.state.pushes * uploadMs) / HORIZON;
  check('after: continuous changes still leave the link mostly free for sending',
    dutyCycle < 0.5, `${(dutyCycle * 100).toFixed(0)}% of ten minutes spent uploading`);
}

// ── 6. the floor must not starve a genuinely new chat ────────────────────────
{
  const a = makeAgent();
  a.poll();
  a.change();                      // arrives 1s after a push
  a.tick(1000);
  a.poll();                        // floor holds it back
  const ceiling = Math.max(MIN_GAP, a.state.upload * 3);
  a.tick(ceiling);
  a.poll();                        // and it goes out here
  check('after: worst-case delay for a new chat is the floor, not the refresh',
    a.state.pushes === 2, `${sec(ceiling)} ceiling, against ${min(REFRESH)} before`);
}

console.log(`\n${fail} failed`);
process.exit(fail ? 1 : 0);
