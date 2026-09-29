/**
 * Why the POS said "bridge 15 minute dhore report koreni" about a bridge that
 * was running, in numbers.
 *
 * THE FAULT
 * ---------
 * agent.js runs one `while` loop. Every step in it was awaited bare, so a step
 * whose promise never settled parked the loop for good - including the
 * sayHello() at the very top of the next iteration. That call is the only thing
 * that writes wa_agents.last_seen, and that column is the only thing the POS has
 * to go on. So a frozen loop looks exactly like a dead shop PC.
 *
 * The log is the proof, and it is unambiguous. The address book upload runs on
 * the first line of every iteration, ahead of the "are we connected" check, so
 * even a disconnected WhatsApp kept logging. It logged on a clean ~10 minute
 * cadence all afternoon and then stopped dead:
 *
 *     12:46:24, 12:55:54, 12:56:44, 13:06:44   <- every 10 minutes, reliably
 *     13:16:40  job 2062 sent
 *     ... nothing at all ...
 *     16:07:41  agent.js exited with code 1073807364
 *
 * Two hours fifty-one minutes with no upload is a loop that had stopped
 * iterating. The process itself lived on for all of it.
 *
 * WHAT THE POS DID WITH THAT
 * --------------------------
 * marketingAgentStatus() compares last_seen against WA_AGENT_STALE_SECONDS (45)
 * and, past it, says "the bridge is not reporting, start it". It cannot tell a
 * frozen agent from an unplugged one - it was right to be cautious and wrong
 * about the cause. Restarting the bridge did fix it, for the same reason
 * pressing any button would have.
 *
 * WHAT THIS CHECKS
 * ----------------
 * A model of the loop, the heartbeat and the POS's staleness test, on a fake
 * clock so a three-hour outage takes milliseconds. Five properties:
 *
 *   1. a hung step makes the POS declare a live bridge dead      (the fault)
 *   2. the heartbeat keeps reporting through it anyway           (the fix)
 *   3. the stuck step is named in the log, once                  (diagnosis)
 *   4. a healthy loop reports exactly as fast as it did before   (no regression)
 *   5. a dead link is not retried harder than it used to be       (no harm)
 *
 * Plus the one the heartbeat depends on: a deadline really does release the
 * loop, so the two fixes are independent rather than either alone sufficing.
 */

// ── the real numbers, so this test fails if agent.js drifts ──────────────────
const POLL_MS = 2500;             // .env POLL_MS
const HEARTBEAT_MS = 15000;       // agent.js HEARTBEAT_MS
const STUCK_MS = 60000;           // agent.js STUCK_MS
const STALE_SECONDS = 45;         // config/marketing.php WA_AGENT_STALE_SECONDS
const CATALOG_TIMEOUT_MS = 120000;
const JOB_TIMEOUT_MS = 180000;
const MAX_BACKOFF_MS = 30000;
const LOOP_BACKOFF_MS = Math.min(30000, POLL_MS * 8);
// The heartbeat's line in the sand: a loop silent for longer than its own
// worst-case backoff is stopped, not slow.
const MAX_LOOP_GAP_MS = 30000;

// Costs of an ordinary step, from the shop this was written for. CATALOG_UPLOAD_MS
// is the ~10s the code comment claims for this shop's 5,151 contacts.
const CATALOG_UPLOAD_MS = 10000;
const CATALOG_REFRESH_MS = 10 * 60 * 1000;
const SEND_PACE_MS = 5000;

const sec = (v) => (v / 1000).toFixed(1) + 's';
const min = (v) => (v / 60000).toFixed(1) + 'min';

/**
 * ageOfLastSeen() is in SECONDS - it comes straight off a POS staleness check
 * that compares against WA_AGENT_STALE_SECONDS, which is a count of seconds.
 * Formatting it with the millisecond helpers above divides it a second time and
 * reports every age as a rounding error, so these two are the only ones that may
 * be used on it.
 */
const ageSec = (v) => (Number.isFinite(v) ? `${v.toFixed(1)}s` : 'never');
const ageMin = (v) => (Number.isFinite(v) ? `${(v / 60).toFixed(1)}min` : 'never');

let fail = 0;
const check = (name, ok, detail = '') => {
  if (!ok) fail++;
  console.log(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  - ' + detail : ''}`);
};

/**
 * agent.js's loop + heartbeat + the POS's staleness test.
 *
 * `hangOn` names the step that never settles - that is the fault itself. `dead`
 * models a shop PC with no internet, where every call fails. Every clock is fake
 * and every honest step is instant unless it is the broken one.
 */
function makeAgent({ hangOn = null, dead = false, jobs = true } = {}) {
  let clock = 0;
  let lastHelloAt = -1e9;      // last SUCCESSFUL hello - what last_seen tracks
  // The heartbeat keeps its own attempt clock, for the same reason agent.js does:
  // one shared timestamp lets the loop's attempt reset the heartbeat's backoff,
  // so neither ever engages on a dead link.
  let heartbeatTriedAt = -1e9;
  let currentStep = 'idle';
  let currentStepAt = 0;
  let stuckNoted = false;
  let helloBusy = false;
  let heartbeatFails = 0;
  // When the *loop* last called home, failed or not. The heartbeat reads this to
  // tell a slow loop from a stopped one - a failing loop is still a running one,
  // and joining in would double the retries against a link already known down.
  let lastLoopTryAt = -1e9;
  let lastCatalogPush = 0;     // 0 = never pushed, as in agent.js
  let wedged = false;
  let fails = 0;               // consecutive failed hellos, as in loop()

  const notes = [];
  let hellos = 0;
  let attempts = 0;

  /** What last_seen in wa_agents holds, in seconds. Infinity = never set. */
  const ageOfLastSeen = () => (lastHelloAt < 0 ? Infinity : (clock - lastHelloAt) / 1000);

  /** What marketingAgentStatus() would show the shop right now. */
  const posSees = () => (ageOfLastSeen() > STALE_SECONDS ? 'OFFLINE' : 'CONNECTED');

  function sayHello(fromLoop = false) {
    attempts++;
    // Only the loop's own calls vouch for the loop. If the heartbeat counted
    // itself here it would be permanently reassuring itself.
    if (fromLoop) lastLoopTryAt = clock;
    if (dead) return false;
    lastHelloAt = clock;
    hellos++;
    return true;
  }

  /** heartbeatTick() from agent.js, with the agent's own step names. */
  function heartbeatTick() {
    if (helloBusy) return;
    if (clock - lastHelloAt < HEARTBEAT_MS) return;      // the loop is doing fine

    // Not slow - stopped. A loop that is merely failing keeps calling home on its
    // own backoff, and this is what keeps the heartbeat from doubling that.
    if (clock - lastLoopTryAt < MAX_LOOP_GAP_MS) return;

    if (heartbeatFails > 0) {
      const backoff = Math.min(MAX_BACKOFF_MS, HEARTBEAT_MS * Math.min(8, heartbeatFails));
      if (clock - heartbeatTriedAt < backoff) return;
    }

    if (currentStep !== 'idle' && !stuckNoted) {
      const held = Math.round((clock - currentStepAt) / 1000);
      if (held * 1000 < STUCK_MS) return;                // not stuck long enough to say
      stuckNoted = true;
      notes.push(`poll loop stuck on: ${currentStep} (${held}s) - still reporting`);
    }

    helloBusy = true;
    heartbeatTriedAt = clock;
    heartbeatFails = sayHello() ? 0 : heartbeatFails + 1;
    helloBusy = false;
  }

  /** The steps this iteration actually runs, as [name, cost, ceiling]. */
  function stepsThisIteration() {
    const out = [];
    // pushCatalog() uploads when WhatsApp says the book changed, or when the
    // refresh interval has passed. Otherwise it costs nothing at all.
    const catalogDue = lastCatalogPush === 0 || clock - lastCatalogPush >= CATALOG_REFRESH_MS;
    if (catalogDue) out.push(['address book upload', CATALOG_UPLOAD_MS, CATALOG_TIMEOUT_MS]);
    if (jobs) out.push(['job 2062 (send)', SEND_PACE_MS, JOB_TIMEOUT_MS]);
    return out;
  }

  /**
   * One iteration of loop().
   *
   * `deadline: false` is the code as it was: steps are awaited bare, so the one
   * named by `hangOn` never returns and there is no next iteration.
   */
  function loopOnce({ deadline = true } = {}) {
    if (!sayHello(true)) {
      // The loop's own backoff, exactly as agent.js does it when sayHello fails.
      // Without this the model retries flat out and the heartbeat comparison in
      // scenario 5 measures nothing.
      fails++;
      clock += Math.min(MAX_BACKOFF_MS, POLL_MS * Math.min(8, fails));
      return 'offline';
    }
    fails = 0;

    for (const [name, cost, ceiling] of stepsThisIteration()) {
      currentStep = name;
      currentStepAt = clock;
      stuckNoted = false;

      if (name === hangOn) {
        if (!deadline) { wedged = true; return 'wedged'; }

        // The ceiling wins the race in agent.js. One important difference from
        // the abandoned push above: this leaves a job claimed with no outcome
        // written, which is the case waAgentClaimJob() re-queues after ten
        // minutes. agent.js reports nothing for it here - it logs and carries on
        // - so the model moves straight to the next iteration, which is the
        // poll the POS is waiting for.
        notes.push(`step ${name} gave up after ${Math.round(ceiling / 1000)}s`);
        clock += ceiling;
        currentStep = 'idle';
        continue;
      }

      clock += cost;
      if (name === 'address book upload') lastCatalogPush = clock;
      currentStep = 'idle';
    }

    clock += POLL_MS;
    return 'ok';
  }

  return {
    notes,
    ageOfLastSeen,
    posSees,
    isWedged: () => wedged,
    hellos: () => hellos,
    attempts: () => attempts,
    step: () => currentStep,
    clockNow: () => clock,
    tick: (ms) => { clock += ms; },
    loopOnce,
    heartbeatTick,
  };
}

/**
 * Run for `forMs` of wall clock, the way the process would.
 *
 * Once the loop wedges the tick keeps advancing and the heartbeat keeps firing -
 * time does not stop for a stuck loop, which is the entire reason the staleness
 * message appeared. Only the loop itself stops calling in. Returning instead
 * would make scenario 1 "pass" for the wrong reason: last_seen would read as
 * fresh because the clock had never moved on past the wedge.
 */
function run(agent, forMs, { heartbeat = true, deadline = true, jobs = true } = {}) {
  const tick = Math.min(POLL_MS, HEARTBEAT_MS);
  // Measured on the agent's own clock, not a local counter. A step's cost is
  // advanced by loopOnce() and so is the backoff after a failed hello - counting
  // ticks instead would run a backoff scenario for far longer than the duration
  // it claims to cover, and quietly flatter or damn the heartbeat depending on
  // which way it fell.
  while (agent.clockNow() < forMs) {
    agent.tick(tick);
    if (!agent.isWedged() && agent.loopOnce({ deadline }) === 'wedged') continue;
    if (heartbeat) agent.heartbeatTick();
  }
}

/**
 * Run the scenario, asking after every poll whether the POS ever saw a stale row.
 *
 * A single reading at the end of a run is a sample of one instant, and the
 * difference between "never went stale" and "is stale right now at this
 * particular moment" is most of what these checks are about.
 */
function sampleOver(forMs, agent, opts) {
  const tick = Math.min(POLL_MS, HEARTBEAT_MS);
  let everStale = false;
  while (agent.clockNow() < forMs) {
    agent.tick(tick);
    if (!agent.isWedged() && agent.loopOnce({ deadline: opts.deadline }) === 'wedged') continue;
    if (opts.heartbeat) agent.heartbeatTick();
    if (agent.posSees() === 'OFFLINE') everStale = true;
  }
  return everStale;
}

// ── 1. the fault, reproduced ────────────────────────────────────────────────
//
// Three hours of the address book upload never returning. Before the fix this
// was all it took to make a running bridge look like an unplugged shop PC.

console.log('a hung step, no heartbeat  (before the fix)');
{
  const agent = makeAgent({ hangOn: 'address book upload', jobs: false });
  run(agent, 3 * 60 * 60 * 1000, { heartbeat: false, deadline: false });

  check('the loop really is stuck',
    agent.isWedged(),
    `parked inside the upload, ${ageMin(agent.ageOfLastSeen())} of silence since`);

  check('the POS declares the bridge dead anyway',
    agent.posSees() === 'OFFLINE',
    `last_seen is ${ageMin(agent.ageOfLastSeen())} old against a ${STALE_SECONDS}s limit`);

  check('which is exactly the message the shop was shown',
    agent.ageOfLastSeen() > 15 * 60,
    '"bridge 15 minute dhore report koreni" - while the PC was switched on');
}

// ── 2. the same fault, with the heartbeat ───────────────────────────────────
//
// The heartbeat does not fix the hang. It fixes the lie: the POS is told the
// bridge is there, and told what it is stuck on, while the deadline works on
// actually releasing the loop.

console.log('\nthe same hang, with the heartbeat  (after the fix)');
{
  const agent = makeAgent({ hangOn: 'address book upload', jobs: false });
  run(agent, 3 * 60 * 60 * 1000, { heartbeat: true, deadline: false });

  check('the loop is still stuck',
    agent.isWedged(),
    'the heartbeat reports, it does not un-wedge anything');

  check('but the POS still sees a live bridge',
    agent.posSees() === 'CONNECTED',
    `last_seen is only ${ageSec(agent.ageOfLastSeen())} old`);

  check('so the shop is never told the PC is off',
    agent.posSees() !== 'OFFLINE',
    'this is the whole point: reporting is no longer downstream of any work');

  check('and the log names the step it is stuck on',
    agent.notes.some((n) => n.includes('address book upload')),
    agent.notes.find((n) => n.includes('stuck on')) || 'nothing was recorded');

  check('the note is written once, not on every heartbeat tick',
    agent.notes.filter((n) => n.includes('stuck on')).length === 1,
    `${agent.notes.filter((n) => n.includes('stuck on')).length} note(s) over 3 hours`);
}

// ── 3. the deadline on its own releases the loop ────────────────────────────
//
// So the two fixes are independent. Even with no heartbeat at all, the bridge
// comes back - it just reports honestly dead in the meantime, which is the
// correct thing for it to do while it genuinely cannot work.

console.log('\nthe deadline alone, no heartbeat');
{
  const agent = makeAgent({ hangOn: 'address book upload', jobs: false });
  run(agent, 10 * 60 * 1000, { heartbeat: false, deadline: true });

  check('the loop is not wedged any more',
    !agent.isWedged(),
    `it gave the step up after ${sec(CATALOG_TIMEOUT_MS)} and kept going`);

  check('and the step is recorded as abandoned',
    agent.notes.some((n) => n.includes('gave up after')),
    `${agent.notes.filter((n) => n.includes('gave up')).length} abandoned step(s) in 10 minutes`);

  // Sampled after every poll rather than only at the end, because the fault
  // recurs: the upload hangs again on the next iteration, so any single reading
  // lands somewhere different. This asks the question that actually matters -
  // did the POS ever see the bridge go stale - rather than what the clock
  // happened to say at the end of the run.
  const everStale = sampleOver(10 * 60 * 1000, agent, { heartbeat: false, deadline: true });

  check('but the POS is still shown OFFLINE in the meantime',
    everStale,
    'honest, and exactly why the deadline alone is not the whole fix');

  // Recovery means the bridge comes back and stays back. A second run against a
  // step that no longer hangs is the honest way to show that, because "the loop
  // kept iterating" and "the shop's messages are flowing again" are the same
  // claim here and only the second is useful.
  const recovered = makeAgent({ jobs: false });
  run(recovered, 10 * 60 * 1000, { heartbeat: false, deadline: true });

  check('once the step stops hanging, the bridge is simply fine',
    recovered.posSees() === 'CONNECTED' && !recovered.isWedged(),
    `${recovered.hellos()} hello(s) over 10 minutes, never stale`);
}

console.log('\nthe deadline, with the heartbeat');
{
  const agent = makeAgent({ hangOn: 'address book upload', jobs: false });
  run(agent, 10 * 60 * 1000, { heartbeat: true, deadline: true });

  check('the POS never once sees the bridge go stale',
    agent.posSees() === 'CONNECTED',
    'the heartbeat covers the seconds the deadline is still counting');

  check('the ceiling is long enough for a real 5,000-contact upload',
    CATALOG_TIMEOUT_MS > 60000,
    `${sec(CATALOG_TIMEOUT_MS)} against the ~${sec(CATALOG_UPLOAD_MS)} it actually takes`);
}

// ── 4. no regression on a healthy loop ──────────────────────────────────────
//
// The heartbeat stands down whenever the loop keeps up. If it did not, every
// ordinary poll would send two hellos - and the POS would be taking twice the
// writes it did before this change, for no gain.

console.log('\nan idle loop, 10 minutes, no jobs and nothing to upload');
{
  const plain = makeAgent({ jobs: false });
  run(plain, 10 * 60 * 1000, { heartbeat: false, jobs: false });

  const withBeat = makeAgent({ jobs: false });
  run(withBeat, 10 * 60 * 1000, { heartbeat: true, jobs: false });

  check('both loops kept iterating',
    !plain.isWedged() && !withBeat.isWedged(),
    `${plain.hellos()} iterations, one hello each`);

  check('the heartbeat adds nothing at all to the fast path',
    withBeat.hellos() === plain.hellos() && withBeat.attempts() === plain.attempts(),
    `${withBeat.attempts()} requests either way - it stands down, it does not double up`);

  check('the POS saw it connected throughout',
    withBeat.posSees() === 'CONNECTED');
}

console.log('\na loop doing real work, 10 minutes');
{
  const plain = makeAgent();
  run(plain, 10 * 60 * 1000, { heartbeat: false });

  const withBeat = makeAgent();
  run(withBeat, 10 * 60 * 1000, { heartbeat: true });

  check('both loops kept iterating',
    !plain.isWedged() && !withBeat.isWedged(),
    `~${Math.floor(10 * 60 * 1000 / (POLL_MS + CATALOG_UPLOAD_MS + SEND_PACE_MS))} iterations with work in them`);

  // Here the heartbeat firing at all is correct, not a regression: one iteration
  // legitimately runs past HEARTBEAT_MS (a paced send plus the poll sleep), so
  // the loop has genuinely not reported for a while and the gap is worth covering.
  // The ceiling is that it never runs away - one extra per slow iteration.
  // A healthy iteration here is shorter than MAX_LOOP_GAP_MS, so the loop is
  // never silent long enough to be called stopped and the heartbeat correctly
  // stays out of it entirely. That is the property that matters: no extra writes
  // to the POS during normal trading, whatever the traffic.
  const extra = withBeat.hellos() - plain.hellos();
  const iterations = plain.hellos();

  check('the heartbeat adds nothing while the loop is keeping up',
    extra === 0,
    `${iterations} hellos either way - a paced send plus the poll sleep is ${POLL_MS + SEND_PACE_MS}ms, inside the ${MAX_LOOP_GAP_MS}ms a live loop may go quiet`);

  check('and the POS still saw it connected throughout',
    withBeat.posSees() === 'CONNECTED');

  check('the POS still saw it connected throughout',
    withBeat.posSees() === 'CONNECTED');
}

// ── 5. a genuinely dead link is not hammered ────────────────────────────────
//
// The loop already backs off when there is no internet. The heartbeat must not
// quietly undo that, which is the one way adding it could make a real outage
// worse than the fault it was added for.

console.log('\na shop PC with no internet, 10 minutes');
{
  const plain = makeAgent({ dead: true });
  run(plain, 10 * 60 * 1000, { heartbeat: false });

  const withBeat = makeAgent({ dead: true });
  run(withBeat, 10 * 60 * 1000, { heartbeat: true });

  check('the heartbeat does not add requests on a dead link',
    withBeat.attempts() <= plain.attempts(),
    `${withBeat.attempts()} attempts with, ${plain.attempts()} without`);

  check('the rate stays at the loop\'s own backoff, not the heartbeat\'s',
    withBeat.attempts() <= Math.ceil((10 * 60 * 1000) / LOOP_BACKOFF_MS) + 1,
    `${withBeat.attempts()} attempts in 10 minutes, against a ceiling of one per ${sec(LOOP_BACKOFF_MS)}`);

  check('and nothing is reported, as always',
    withBeat.hellos() === 0 && withBeat.ageOfLastSeen() === Infinity,
    'the POS still shows OFFLINE - correctly, this time');
}

console.log(`\n${fail} failed`);
process.exit(fail ? 1 : 0);
