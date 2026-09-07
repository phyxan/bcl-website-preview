<?php
/**
 * SAMPLE mail configuration for the Free Case Review handler (form.php).
 *
 * DO NOT put real secrets in this file — it lives in the repo.
 * The real copy lives OUTSIDE the web root on the server at:
 *
 *     /home/<cpanel-user>/bcl-secure/mail-config.php      (chmod 600)
 *
 * See FORM-SETUP.md for the full, step-by-step setup.
 * form.php loads the file above via  dirname(DOCUMENT_ROOT).'/bcl-secure/mail-config.php'
 * so the same config serves both the /preview/ staging copy and the live root.
 */

return [
    // --- Where leads are delivered ------------------------------------------
    'to'        => 'intake@barrettcrimelaw.com',   // REQUIRED: firm inbox that receives leads
    'to_name'   => 'Peter Barrett Criminal Defense',

    // Envelope sender. MUST be a mailbox on barrettcrimelaw.com so SPF/DKIM pass.
    'from'      => 'no-reply@barrettcrimelaw.com',
    'from_name' => 'BCL Website',

    'subject_prefix' => 'New Free Case Review',

    // --- Mail transport -----------------------------------------------------
    // 'smtp'     = authenticated SMTP to your own mail server (recommended).
    // 'sendmail' = hand off to the server's local mailer; NO password needed.
    'transport'   => 'smtp',

    // Used only when transport = 'smtp'. Create the mailbox in cPanel first,
    // then paste its address + password here.
    'smtp_host'   => 'localhost',   // or 'mail.barrettcrimelaw.com'
    'smtp_port'   => 587,           // 587 = STARTTLS, 465 = SSL
    'smtp_secure' => 'tls',         // 'tls' for 587, 'ssl' for 465
    'smtp_auth'   => true,
    'smtp_user'   => 'no-reply@barrettcrimelaw.com',
    'smtp_pass'   => 'PUT-THE-MAILBOX-PASSWORD-HERE',

    // --- Security / anti-abuse ---------------------------------------------
    'allowed_origins' => [
        'https://www.barrettcrimelaw.com',
        'https://barrettcrimelaw.com',           // covers the /preview/ staging origin too
    ],
    'rate_limit_per_hour' => 6,     // max submissions per IP per rolling hour
    'min_seconds'         => 2,     // reject submissions faster than this (bots)
];
