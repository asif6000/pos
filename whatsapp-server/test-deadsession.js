/**
 * Decision table for isDeadSession() - the one thing that decides whether auth/
 * is deleted. Requiring lib/client does not touch the network or the session.
 */
require('dotenv').config();
const { isDeadSession } = require('./lib/client');

const cases = [
  // [code, reason, expectedDead, why]
  [401, 'stream errored', true, 'revoked registration - must re-pair'],
  [401, 'logout', true, 'logged out - must re-pair'],
  [null, 'unpaired', true, 'phone unlinked this device'],
  [null, 'DEVICE_REMOVED', true, 'device removed from linked devices'],
  [null, 'UNPAIRED_ID', true, 'UNPAIRED still matches UNPAIRED'],

  [440, 'connection lost', false, 'transient - must reconnect, NOT unlink'],
  [440, 'connection replaced', false, '2nd session on the account - reconnect'],
  [null, 'connection closed', false, 'ordinary blip'],
  [null, 'connection lost', false, 'ordinary blip'],
  [null, 'timed out', false, 'ordinary blip'],
  [null, 'restart required', false, 'WhatsApp restarting the stream'],
  [515, 'restart required', false, 'restart required is not a dead session'],
  [428, 'Connection Terminated', false, 'not a revocation'],
  [500, 'something went wrong', false, 'server side error - reconnect'],

  // the substring trap the old list fell into
  [null, 'chat id 4401551234 timed out', false, '"440" inside a JID must not wipe creds'],
  [null, 'message 40122 failed', false, '"401" inside a message id must not wipe creds'],
];

let pass = 0, fail = 0;
for (const [code, reason, expected, why] of cases) {
  const got = isDeadSession(code, reason);
  const ok = got === expected;
  ok ? pass++ : fail++;
  console.log(
    `${ok ? 'PASS' : 'FAIL'}  code=${String(code).padEnd(4)} dead=${String(got).padEnd(5)} (want ${String(expected).padEnd(5)})  ${JSON.stringify(reason)}  - ${why}`
  );
}
console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);
