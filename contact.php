<?php
/**
 * Contact - public page with a working enquiry form.
 *
 * The landing page's "Contact" link only ever jumped to the footer, where a
 * phone number and a mail address lived. This is the page that link was meant
 * to open.
 *
 * The form posts to this page and sends email through the server, and the
 * support channels are listed beside it. If the server cannot send mail (it
 * happens on shared hosting, and it always happens on a local machine), the
 * visitor is told so plainly and pointed at the email address rather than
 * being shown a fake "sent".
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/legal-page.php';

// The throttle below remembers when this browser last sent a message, so the page
// needs a session of its own - it is a public page and nobody is signed in.
startSecureSession();

$s   = legalSupport();
$to  = $s['email'];
// Envelopes go out from the site's own domain: a From address that does not belong
// here is what gets a host to refuse the message or drop it in spam.
$domain = substr(strrchr($s['email'], '@'), 1) ?: $s['site'];
$from   = 'noreply@' . $domain;

$sent    = false;
$sentVia = '';
$error   = '';
$values  = array('name' => '', 'email' => '', 'phone' => '', 'topic' => 'General enquiry', 'message' => '');

$topics = array(
    'General enquiry',
    'Sales / demo',
    'Start a plan or renew',
    'Payment or billing problem',
    'Technical problem',
    'Staff app',
    'SMS messaging',
    'Report or data request',
    'Privacy or account closure',
);

/** Header lines must not be smuggled through a form field. */
function contactHeaderSafe($value)
{
    return str_replace(array("\r", "\n", '%0a', '%0d'), '', (string)$value);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Present on every real visit, empty on every bot that simply posts the fields
    // it found in the markup.
    if (trim((string)($_POST['company_website'] ?? '')) !== '') {
        // Say nothing about why, so a bot cannot tell a filter from a broken form.
        $error = 'Your message could not be sent. Please use email instead.';
    } else {
        $values['name']    = trim((string)($_POST['name'] ?? ''));
        $values['email']   = trim((string)($_POST['email'] ?? ''));
        $values['phone']   = trim((string)($_POST['phone'] ?? ''));
        $values['topic']   = trim((string)($_POST['topic'] ?? ''));
        $values['message'] = trim((string)($_POST['message'] ?? ''));

        // One message a minute per browser, so a scripted loop cannot fill the
        // support mailbox from a single session.
        $last = isset($_SESSION['contact_last_at']) ? (int)$_SESSION['contact_last_at'] : 0;

        if (mb_strlen($values['name']) < 2) {
            $error = 'Please tell us your name.';
        } elseif (mb_strlen($values['message']) < 10) {
            $error = 'Please write a message of at least a few words.';
        } elseif (mb_strlen($values['message']) > 4000) {
            $error = 'That message is too long for this form. Please send it by email instead.';
        } elseif ($values['email'] !== '' && !filter_var($values['email'], FILTER_VALIDATE_EMAIL)) {
            $error = 'That email address does not look right.';
        } elseif ($values['email'] === '' && !preg_match('/^[0-9+\-\s()]{6,20}$/', $values['phone'])) {
            $error = 'Give us an email address or a phone number so we can reply.';
        } elseif (!in_array($values['topic'], $topics, true)) {
            $error = 'Please choose one of the listed topics.';
        } elseif ($last && (time() - $last) < 60) {
            $error = 'A message was just sent from this browser. Please wait a moment before sending another.';
        } else {
            $lines = array(
                'From: '     . $values['name'],
                'Email: '    . ($values['email'] !== '' ? $values['email'] : '(not given)'),
                'Phone: '    . ($values['phone'] !== '' ? $values['phone'] : '(not given)'),
                'Topic: '    . $values['topic'],
                'Page: '     . (isset($_SERVER['HTTP_REFERER']) ? 'referred' : 'contact.php'),
                'Time: '     . date('Y-m-d H:i') . ' (server)',
                '',
                '------------------------------------------------------------',
                $values['message'],
            );
            $body = implode("\r\n", $lines);

            $headers = array(
                'From: ' . contactHeaderSafe($s['company']) . ' <' . $from . '>',
                'Reply-To: ' . ($values['email'] !== ''
                    ? contactHeaderSafe($values['name'] . ' <' . $values['email'] . '>')
                    : $from),
                'Subject: [' . contactHeaderSafe($s['site']) . ' enquiry] ' . contactHeaderSafe($values['topic']),
                'Content-Type: text/plain; charset=UTF-8',
                'X-Contact-Form: 1',
            );

            // mail() takes UTF-8 in the body; the subject was made header-safe above.
            if (@mail($to, '=?UTF-8?B?' . base64_encode('[enquiry] ' . $values['topic']) . '?=', $body, implode("\r\n", $headers))) {
                $sent = true;
                $sentVia = 'email';
                $_SESSION['contact_last_at'] = time();
            } else {
                // The host refused it. Saying so is better than a fake "sent" -
                // the visitor can email the address below directly.
                $error = 'The message could not be sent from this server right now. Please email ' . $to . ' directly, or try again shortly.';
            }
        }
    }
}

legalHead('Contact', 'contact', array(
    'eyebrow' => 'Support & sales',
    'title'   => 'Contact us',
    'desc'    => 'Talk to a person about a demo, a plan, a payment, or something that has stopped working. Phone is the fastest way to reach the team.',
));
?>

<div class="contact-grid">

    <div class="card">
        <?php if ($sent): ?>
        <div class="alert-ok">
            <i class="fas fa-circle-check"></i>
            <div>
                <strong>Thank you &mdash; your message has been sent.</strong>
                <div class="muted">We reply on the number or email you gave, usually the same working day.</div>
            </div>
        </div>
        <?php elseif ($error): ?>
        <div class="alert-err">
            <i class="fas fa-triangle-exclamation"></i>
            <div><?php echo legalE($error); ?></div>
        </div>
        <?php endif; ?>

        <h2 style="margin-top:0;">Send a message</h2>
        <p class="muted" style="font-size:.9rem; margin-bottom:18px;">
            Name and a way to reply are all we need. If you are an existing shop, include your shop name
            or the email you log in with &mdash; it saves a round trip.
        </p>

        <form method="POST" action="contact.php" id="contactForm" novalidate>
            <div class="hp" aria-hidden="true">
                <label>Leave this field empty <input type="text" name="company_website" tabindex="-1" autocomplete="off"></label>
            </div>

            <div class="field">
                <label for="cf-name">Your name <span class="req">*</span></label>
                <input class="input" type="text" id="cf-name" name="name" maxlength="80" autocomplete="name"
                       value="<?php echo legalE($values['name']); ?>" required>
            </div>

            <div class="field">
                <label for="cf-email">Email</label>
                <input class="input" type="email" id="cf-email" name="email" maxlength="120" autocomplete="email"
                       value="<?php echo legalE($values['email']); ?>"
                       placeholder="you@example.com">
                <div class="hint">Give an email or a mobile number &mdash; one of the two is enough.</div>
            </div>

            <div class="field">
                <label for="cf-phone">Mobile number</label>
                <input class="input" type="tel" id="cf-phone" name="phone" maxlength="20" autocomplete="tel"
                       value="<?php echo legalE($values['phone']); ?>" placeholder="01XXXXXXXXX">
            </div>

            <div class="field">
                <label for="cf-topic">What is it about?</label>
                <select class="input" id="cf-topic" name="topic">
                    <?php foreach ($topics as $t): ?>
                    <option value="<?php echo legalE($t); ?>" <?php echo $values['topic'] === $t ? 'selected' : ''; ?>>
                        <?php echo legalE($t); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="field">
                <label for="cf-message">Message <span class="req">*</span></label>
                <textarea class="textarea" id="cf-message" name="message" maxlength="4000" required
                          placeholder="Tell us what you need. For a fault, what you did, what happened, and which shop."><?php echo legalE($values['message']); ?></textarea>
            </div>

            <button type="submit" class="btn btn-green"><i class="fas fa-paper-plane"></i> Send message</button>

            <p class="form-note">
                By sending this you agree to being contacted back about your enquiry, as described in the
                <a href="privacy.php">Privacy Policy</a>. This form is for enquiries, not for sharing a
                password or a card number &mdash; please never send either.
            </p>
        </form>
    </div>

    <div>
        <div class="chan">
            <a class="tel" href="tel:<?php echo legalE($s['tel']); ?>">
                <i class="fas fa-phone-alt"></i>
                <span><?php echo legalE($s['tel_display']); ?><small>Call the support line</small></span>
            </a>
            <a class="mail" href="mailto:<?php echo legalE($s['email']); ?>">
                <i class="fas fa-envelope"></i>
                <span><?php echo legalE($s['email']); ?><small>Email &mdash; documents and long threads</small></span>
            </a>
        </div>

        <div class="card" style="margin-top:16px;">
            <h3 style="font-size:.95rem; margin-bottom:8px;">Hours</h3>
            <p style="font-size:.9rem; margin:0;">
                Saturday to Thursday, 9:00&ndash;20:00 (Bangladesh time).<br>
                Friday: 15:00&ndash;20:00.<br>
                <span class="muted">Messages left outside those hours are answered the next working day.</span>
            </p>
        </div>

        <div class="card" style="margin-top:16px;">
            <h3 style="font-size:.95rem; margin-bottom:8px;"><i class="fas fa-circle-question" style="color:var(--blue);"></i> Before you write</h3>
            <ul style="font-size:.9rem; margin-left:18px;">
                <li>A page that refuses to open for a staff member is nearly always a permission or a
                    package question &mdash; the shop owner can change it under
                    <strong>Roles &amp; Permissions</strong>.</li>
                <li>Forgot a password? Ask the shop owner to set a new one from
                    <strong>Admin &rarr; Users</strong>; we cannot read a stored password to tell it you.</li>
                <li>An invoice SMS that did not arrive: include the buyer's number and the approximate time.</li>
                <li>Payment taken but the plan did not update: include the gateway reference from the
                    payment page.</li>
            </ul>
        </div>

        <div class="card" style="margin-top:16px;">
            <h3 style="font-size:.95rem; margin-bottom:8px;">Business &amp; legal</h3>
            <p style="font-size:.9rem; margin:0;">
                Operated by <?php echo legalE($s['company']); ?>, <?php echo legalE($s['site']); ?>.<br>
                <a href="terms.php">Terms &amp; Conditions</a> &middot;
                <a href="privacy.php">Privacy Policy</a>
            </p>
        </div>
    </div>

</div>

<?php legalFoot(); ?>
