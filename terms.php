<?php
/**
 * Terms & Conditions - public page.
 *
 * Linked from the footer and ticked at sign-up, so the clauses below are written
 * against the service as it actually behaves: prices and the 7-day trial come from
 * config/plans.php, renewal is manual rather than automatic, and a lapsed package
 * warns the shop instead of switching it off. Terms that describe a different
 * product are the ones a customer can hold us to.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/legal-page.php';

$s      = legalSupport();
$plans  = include __DIR__ . '/config/plans.php';

// Money and trial lengths are read from the same file the checkout charges from,
// so this page cannot disagree with what a shop is actually billed.
$tiers = array();
foreach ($plans as $p) {
    if (!empty($p['hidden'])) { continue; }
    $tiers[] = $p;
}
$lowest = $tiers ? (int)$tiers[0]['monthly'] : 0;
$trialDays = isset($plans['free']['trial_days']) ? (int)$plans['free']['trial_days'] : 7;

legalHead('Terms & Conditions', 'terms', array(
    'eyebrow' => 'Legal',
    'title'   => 'Terms & Conditions',
    'desc'    => 'The agreement between a shop and ' . $s['company'] . ' for the use of Smart Collection POS.',
    'stamp'   => $s['updated'],
));
?>

<div class="toc">
    <span>On this page</span>
    <a href="#accept">1. Accepting these terms</a>
    <a href="#eligibility">2. Who may use the service</a>
    <a href="#service">3. What you are buying</a>
    <a href="#accounts">4. Accounts and staff access</a>
    <a href="#your-data">5. Your data</a>
    <a href="#acceptable-use">6. Acceptable use</a>
    <a href="#payment">7. Payment, renewal and lapse</a>
    <a href="#refunds">8. Refunds</a>
    <a href="#support">9. Support and availability</a>
    <a href="#third-party">10. Third-party services</a>
    <a href="#ending">11. Ending the agreement</a>
    <a href="#ip">12. Ownership</a>
    <a href="#disclaimer">13. Disclaimer and liability</a>
    <a href="#changes">14. Changes</a>
    <a href="#law">15. Governing law</a>
    <a href="#contact">16. Contact</a>
</div>

<div class="prose">

    <h2 id="accept">1. Accepting these terms</h2>
    <p>
        By creating an account, using the admin panel, installing the staff app, or paying for a
        package, you agree to these terms and to the
        <a href="privacy.php">Privacy Policy</a>, which forms part of them. The sign-up page asks you
        to confirm this before an account is created, and an account is not created until you do.
    </p>
    <p>
        If you are opening the account for a shop you do not own, you confirm that the owner has asked
        you to do so and that you can bind that business.
    </p>

    <h2 id="eligibility">2. Who may use the service</h2>
    <ul>
        <li>You must be at least 16 years old, and able to enter a contract where you live.</li>
        <li>The service is for running a lawful business. It is not for goods or services whose sale is
            restricted where you trade.</li>
        <li>One shop account covers one business. A chain or a company running several separate shops
            should use the multi-store package rather than sharing one login between businesses.</li>
    </ul>

    <h2 id="service">3. What you are buying</h2>
    <p>
        Smart Collection POS is a point-of-sale and shop management service: billing, stock, products
        with barcodes and variants, customers, suppliers and transfers, expenses, reports, staff payroll
        records, SMS and WhatsApp messaging, an Android staff app, and printed or emailed invoices.
    </p>
    <p>
        Access is sold in packages. Each package includes a defined set of those modules, and the price
        of the package you choose is what is charged &mdash; never an amount sent from your browser.
        The current packages start at <strong>&#2547;<?php echo $lowest; ?> a month</strong>, and every
        package's inclusions and price are listed on the pricing page of the site at the time you order.
    </p>
    <p>
        A new shop is given a <strong><?php echo $trialDays; ?>-day free trial</strong>. A trial is a
        normal account with the modules of the trial package; it is not a promise that a paid package
        will keep every feature you used during it.
    </p>
    <div class="callout">
        <i class="fas fa-box-open"></i>
        <div>
            <strong>Your package decides what your staff can use; your own Roles &amp; Permissions
            screen decides which of them uses it.</strong> A module that is not in your package cannot be
            opened by any role in the shop, however it is ticked &mdash; upgrading is what unlocks it.
        </div>
    </div>

    <h2 id="accounts">4. Accounts and staff access</h2>
    <ul>
        <li>You choose a password; keep it safe. Anyone who signs in with your credentials is treated as
            you, and their actions are yours.</li>
        <li>You may create staff accounts and give each a role. You are responsible for what your staff do
            with the access you give them, and for removing an account when a person leaves.</li>
        <li>Do not share logins between people, and do not sell, transfer or sub-license access to the
            service without our written agreement.</li>
        <li>You may ask us to reset or disable an account for someone who has left your business, provided
            the request comes from the account owner on file.</li>
    </ul>

    <h2 id="your-data">5. Your data</h2>
    <ul>
        <li>The records you enter &mdash; products, sales, customers, expenses, staff details &mdash; remain
            yours. We claim no ownership of them.</li>
        <li>You are responsible for the accuracy and lawfulness of that data, including having the right to
            contact the customer numbers you store and to message them.</li>
        <li>You can export your sales, products and customer lists from the admin panel at any time. Keep
            your own copy if you rely on the records for accounting; we hold them for you, but an export
            costs you nothing and takes a minute.</li>
        <li>Deleting a record removes it. Deletion by a staff member with the right permission is treated as
            an instruction from the shop.</li>
    </ul>

    <h2 id="acceptable-use">6. Acceptable use</h2>
    <p>You agree not to:</p>
    <ul>
        <li>use the service for anything unlawful, or for sending messages that are not permitted under
            Bangladeshi telecom rules on commercial SMS;</li>
        <li>send bulk marketing without the consent of the people receiving it, or ignore an opt-out;</li>
        <li>probe, scan, overload or attempt to break into the service or another shop's account;</li>
        <li>interfere with the service, forge requests, or use another shop's session or token;</li>
        <li>resell access, or offer the service to third parties under your own brand without agreement;</li>
        <li>upload malware, or store content that infringes someone else's rights.</li>
    </ul>

    <h2 id="payment">7. Payment, renewal and lapse</h2>
    <ul>
        <li>Prices are shown in Bangladeshi taka and are charged through the payment gateway named on the
            checkout page. The amount charged is the amount published on the pricing page when you pay.</li>
        <li><strong>Renewal is manual.</strong> We do not store your card or wallet credentials and we do
            not take a payment automatically when a period ends. You will be reminded before and after a
            package expires.</li>
        <li>If a package lapses, the shop's data is kept and the shop is asked to renew. Modules that
            belong to a higher package stop being available until it is renewed or upgraded; a lapsed
            account is not deleted outright.</li>
        <li>Where an invoice is issued, it is payable within 7 days unless agreed otherwise.</li>
        <li>Prices may change. A change never applies to a period you have already paid for.</li>
    </ul>

    <h2 id="refunds">8. Refunds</h2>
    <p>
        Package payments buy access for a period beginning when you pay, and are not refundable once the
        period has begun, except where the law requires it or where we agree. If a payment is taken twice
        in error, or a package is clearly not delivered and we cannot fix it, the amount is refunded or
        credited to the next period. Tell us on the
        <a href="contact.php">contact page</a> with the payment reference.
    </p>

    <h2 id="support">9. Support and availability</h2>
    <ul>
        <li>Support is by phone, WhatsApp and email during business hours in Bangladesh; the numbers are on
            the <a href="contact.php">contact page</a>.</li>
        <li>The service is provided on a commercially reasonable basis. It needs an internet connection,
            and it depends on hosting, payment, SMS and messaging providers that are not under our control.</li>
        <li>We may take the service down for maintenance, and we may change or discontinue features. Where a
            change removes something a paid shop relies on, we give notice first.</li>
        <li>Reported faults are prioritised by how many shops they affect. We do not offer a written uptime
            guarantee or service credits unless one is agreed in writing for a specific contract.</li>
    </ul>

    <h2 id="third-party">10. Third-party services</h2>
    <p>
        Payment (UddoktaPay), SMS delivery, WhatsApp messaging through Meta, Google Contacts and Android
        push notifications are supplied by other companies under their own terms and pricing. Where a
        third party suspends or changes its service, that affects what the POS can do, and we are not
        responsible for the third party's own acts or outages. Message credits, where used, are consumed
        per message and are governed by the same rules as the package you bought.
    </p>

    <h2 id="ending">11. Ending the agreement</h2>
    <ul>
        <li>You may stop using the service at any time by letting a package lapse. Ask us to close the
            account and we will remove the logins and settings as described in the Privacy Policy.</li>
        <li>We may suspend or close an account that breaches these terms, is used unlawfully, does not pay,
            or puts other shops' data or the service at risk.</li>
        <li>Before closing a paying account for anything other than abuse we give notice and a reasonable
            chance to export your records.</li>
    </ul>

    <h2 id="ip">12. Ownership</h2>
    <p>
        The software, the <?php echo legalE($s['company']); ?> and <?php echo legalE($s['product']); ?>
        names and logos, the screens, the staff app and the documentation are ours or our licensors'. You
        get a limited, non-exclusive, non-transferable right to use them while your account is active. That
        does not include the right to copy, reverse-engineer, re-brand or offer the service as your own.
    </p>

    <h2 id="disclaimer">13. Disclaimer and liability</h2>
    <p>
        The service is provided "as is". To the maximum extent Bangladeshi law allows, we are not
        responsible for indirect or consequential loss &mdash; lost profit, lost custom, a lost customer
        list, or business interruption &mdash; and our total liability for any claim arising out of the
        service is limited to the fees you paid for the package in the six months before the claim.
    </p>
    <p>
        Nothing in these terms limits liability that cannot lawfully be limited, including for fraud or for
        a death or personal injury caused by our negligence. You remain responsible for the accuracy of your
        own accounts, tax returns and receipts.
    </p>

    <h2 id="changes">14. Changes to these terms</h2>
    <p>
        We may update these terms as the service changes. The date at the top of this page is the version in
        force. Where a change materially reduces what a paying shop gets, the registered email is told
        before it applies; continuing to use the service afterwards means the new terms apply.
    </p>

    <h2 id="law">15. Governing law</h2>
    <p>
        These terms are governed by the laws of Bangladesh, and the courts of Dhaka have jurisdiction,
        without affecting any consumer protection you may have where you live.
    </p>

    <h2 id="contact">16. Contact</h2>
    <p>
        <?php echo legalE($s['company']); ?> &mdash; <?php echo legalE($s['site']); ?>.
        Phone <a href="tel:<?php echo legalE($s['tel']); ?>"><?php echo legalE($s['tel_display']); ?></a>,
        WhatsApp <?php echo legalE($s['wa_display']); ?>,
        email <a href="mailto:<?php echo legalE($s['email']); ?>"><?php echo legalE($s['email']); ?></a>,
        or use the <a href="contact.php">contact page</a>.
    </p>

</div>

<?php legalFoot(); ?>
