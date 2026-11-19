<?php
/**
 * Privacy Policy - public page.
 *
 * Written against what this application actually does, not a generic template:
 * every third party named here is one the code really talks to (UddoktaPay for
 * payment, BulkSMSBD for invoice and marketing SMS, Meta's WhatsApp Cloud API,
 * Google Contacts through OAuth, Expo for push), because a policy that describes a
 * different product is worse than no policy.
 */

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/legal-page.php';

$s = legalSupport();

legalHead('Privacy Policy', 'privacy', array(
    'eyebrow' => 'Legal',
    'title'   => 'Privacy Policy',
    'desc'    => 'How Smart Collection POS handles the data of a shop, its staff, and the customers those staff serve.',
    'stamp'   => $s['updated'],
));
?>

<div class="toc">
    <span>On this page</span>
    <a href="#who">1. Who is responsible</a>
    <a href="#collect">2. What we collect</a>
    <a href="#use">3. How it is used</a>
    <a href="#shop-customers">4. Your customers' data</a>
    <a href="#storage">5. Where it is stored</a>
    <a href="#third-party">6. Third parties</a>
    <a href="#cookies">7. Cookies &amp; sessions</a>
    <a href="#security">8. How it is protected</a>
    <a href="#retention">9. Retention &amp; deletion</a>
    <a href="#rights">10. Your choices</a>
    <a href="#changes">11. Changes</a>
    <a href="#contact">12. Contact</a>
</div>

<div class="prose">

    <h2 id="who">1. Who is responsible</h2>
    <p>
        <strong><?php echo legalE($s['company']); ?></strong> operates the
        <?php echo legalE($s['product']); ?> point-of-sale and business management service at
        <strong><?php echo legalE($s['site']); ?></strong> and the Android staff app that goes with it.
        This policy covers that service: the website, the admin panel, the staff app and the support
        channels listed at the end.
    </p>
    <p class="bn">
        এই সার্ভিসটি ব্যবহার করলে আপনি এই নীতিমালার সাথে সম্মত হচ্ছেন। সার্ভিসটি ব্যবহারের জন্য বয়সে ১৬ বছরের বেশি হতে হবে।
    </p>

    <h2 id="collect">2. What we collect</h2>
    <h3>Account and shop details you type in</h3>
    <p>
        Name, email address, mobile number, and the business or store name you register. When you add
        more than one store, the store names and their addresses. Under
        <em>Admin &rarr; Settings</em> a shop may also save its trade licence number, TIN, VAT
        registration, address, logo and invoice footer &mdash; whatever the shop chooses to print on a
        receipt is whatever it enters there.
    </p>
    <h3>Staff accounts</h3>
    <p>
        For each person who signs in: name, email or phone, a password (stored only as a cryptographic
        hash, never as readable text), their role, and which store they belong to. Salary records a shop
        keeps under <em>Staff</em> are stored the same way.
    </p>
    <h3>Sales and stock records</h3>
    <p>
        Items sold, quantities, prices, discounts, payments received, returns, expenses, supplier and
        transfer records, and the barcode or product identifiers a shop maintains.
    </p>
    <h3>Customer records a shop creates</h3>
    <p>
        Shop name or number, mobile number and any address or balance a shop records against it. These
        belong to the shop &mdash; see section 4.
    </p>
    <h3>Messages and contact attempts</h3>
    <p>
        Invoice and marketing SMS or WhatsApp messages sent through the service, and a delivery status
        for each. Anything sent to us by phone, WhatsApp or the contact form, including the number or
        address it came from, so we can reply.
    </p>
    <h3>Technical information</h3>
    <p>
        Server logs (request time, address, errors) used to keep the service running and find faults;
        the app version and device platform, so a broken build can be traced; and a push-notification
        token if a staff member allows notifications on the Android app.
    </p>
    <p class="muted">
        We do not buy personal data, and we do not run advertising or social-media tracking scripts on
        these pages.
    </p>

    <h2 id="use">3. How it is used</h2>
    <ul>
        <li>to provide the service you signed up for &mdash; billing, stock, reports, staff access;</li>
        <li>to send an invoice or a receipt to a buyer when the shop asks for it;</li>
        <li>to authenticate a sign-in and to show each person only the pages their role allows;</li>
        <li>to bill the shop for its package and to tell it when that package is about to expire;</li>
        <li>to provide support, and to diagnose and fix faults;</li>
        <li>to meet a legal or accounting obligation, for example when a tax authority asks for records.</li>
    </ul>
    <p>
        We do not use a shop's sales data to train a model, and we do not build a profile of one shop
        out of another shop's data.
    </p>

    <h2 id="shop-customers">4. The data of your own customers</h2>
    <div class="callout">
        <i class="fas fa-store"></i>
        <div>
            Where the service holds records about <em>your</em> buyers, the shop is responsible for that
            data and we process it only on the shop's instructions. That includes asking permission
            before sending a customer marketing SMS, keeping a number accurate, and removing a customer
            who asks to be removed. The shop's staff log in under the shop's account and can export or
            delete those records at any time.
        </div>
    </div>

    <h2 id="storage">5. Where it is stored</h2>
    <p>
        The application and its database run on hosting located in Bangladesh. Backups are made on the
        same infrastructure. Some of the delivery services listed in section 6 operate from outside
        Bangladesh, so a message or a payment that passes through them is processed on their servers
        under their own policies.
    </p>

    <h2 id="third-party">6. Third parties</h2>
    <p>Only these, and only for the part named:</p>
    <ul>
        <li><strong>UddoktaPay</strong> &mdash; takes card, bKash, Nagad and similar payments when a shop
            buys or renews a package. Card and wallet credentials go to them directly; we keep only the
            amount, the date, the method and a reference. We never see or store a full card number.</li>
        <li><strong>BulkSMSBD</strong> &mdash; delivers SMS. A message carries the destination number and
            the text.</li>
        <li><strong>Meta (WhatsApp Cloud API)</strong> &mdash; delivers WhatsApp messages from a shop's
            own connected number, and receives replies into the shop's inbox.</li>
        <li><strong>Google</strong> &mdash; only if a shop connects Google Contacts. That uses Google's
            OAuth sign-in; the shop authorises it, can revoke it in its Google account at any time, and we
            read only the contacts scope.</li>
        <li><strong>Expo / the Android app store channel</strong> &mdash; push notification delivery and
            app update checks.</li>
        <li><strong>The hosting and DNS provider</strong> &mdash; runs the server and the domain, and keeps
            ordinary web logs.</li>
    </ul>
    <p>
        Outside that list, personal data is disclosed only to a regulator, court or law-enforcement body
        when we are required to, or to the buyer of the business if it is sold as a going concern and the
        notice below is given first.
    </p>

    <h2 id="cookies">7. Cookies, tokens and local storage</h2>
    <ul>
        <li><strong>One session cookie</strong> per browser, which keeps you signed in. Signing out or
            clearing cookies ends it. Session data is held on our server, not in the cookie.</li>
        <li><strong>A sign-in token</strong> stored inside the Android app, sent with each request to the
            app's own API.</li>
        <li><strong>Local storage in the browser</strong> for two preferences only: the light/dark theme
            choice and which menu groups were left open. Nothing is read back by a third party.</li>
        <li>No advertising, analytics or cross-site tracking cookies are set by these pages.</li>
    </ul>

    <h2 id="security">8. How it is protected</h2>
    <ul>
        <li>Passwords are hashed; nobody, including support staff, can read a stored password.</li>
        <li>The site is served over HTTPS, and the app's API requires a sign-in token on every call.</li>
        <li>Each page and each report is checked against the signed-in person's role, so a cashier cannot
            open reports or settings by typing an address, and each account only ever sees its own shop's
            records.</li>
        <li>Session files for the app are kept apart from the website's so one cannot disturb the other.</li>
        <li>Payment callbacks are verified against the gateway's own records rather than trusted from the
            browser, and prices are taken from the server's own plan list.</li>
    </ul>
    <p class="muted">
        No method of storage is absolute. If a breach does affect your shop's data we will tell the
        account email on file what happened and what was involved.
    </p>

    <h2 id="retention">9. Retention and deletion</h2>
    <ul>
        <li>A shop can delete a sale, product, staff account or customer record at any time from its own
            admin panel; deletion takes effect immediately.</li>
        <li>Deleting a staff account removes their login and role assignment. Sales they made stay with
            the shop, because they are the shop's business record.</li>
        <li>Close an account by asking support, and the shop's login, staff logins and stored settings are
            removed. Records a shop exported or printed earlier are of course outside our reach.</li>
        <li>Backups are kept for a limited period and then overwritten, so a deleted record disappears
            from backups within that window as well.</li>
        <li>Payment records are kept as long as accounting and tax rules require them.</li>
    </ul>

    <h2 id="rights">10. Your choices</h2>
    <p>
        You may ask us for a copy of the personal data we hold about you, ask for a wrong detail to be
        corrected, ask for your own account to be deleted, or ask not to be contacted except about a
        service you use. Use any channel in section 12. We may need to confirm that the request comes
        from the account holder, and we may keep the minimum needed to prove a payment was made or to
        stop a deleted account being re-created by the same person.
    </p>

    <h2 id="changes">11. Changes to this policy</h2>
    <p>
        If this policy changes in a way that matters, the date at the top changes with it and the
        registered email of each active shop is told before it takes effect. Material changes to the
        price of a package, or to what a package includes, are also shown on the pricing page.
    </p>

    <h2 id="contact">12. Contact</h2>
    <p>
        Questions about this policy, or a request about your own data, can go to
        <a href="contact.php">the contact page</a>, to
        <a href="mailto:<?php echo legalE($s['email']); ?>"><?php echo legalE($s['email']); ?></a>,
        to <a href="tel:<?php echo legalE($s['tel']); ?>"><?php echo legalE($s['tel_display']); ?></a>,
        or on WhatsApp to <?php echo legalE($s['wa_display']); ?>.
    </p>

</div>

<?php legalFoot(); ?>
