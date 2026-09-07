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
 * form.php loads the file above via dirname(DOCUMENT_ROOT).'/bcl-secure/mail-config.php'
 * so the same config serves both the /preview/ staging copy and the live root.
 *
 * NOTE: GoDaddy shared hosting blocks outbound SMTP (ports 25/465/587), so mail
 * is sent through the Mailgun HTTPS API (port 443). 'smtp' won't work on GoDaddy.
 */

return [
    // --- Where leads are delivered ------------------------------------------
    'to'        => 'intake@barrettcrimelaw.com',   // REQUIRED: firm inbox (your Google Workspace address)
    'to_name'   => 'Peter Barrett Criminal Defense',

    // Envelope sender — use an address on the Mailgun sending subdomain so
    // Mailgun's DKIM signature aligns.
    'from'      => 'no-reply@mg.barrettcrimelaw.com',
    'from_name' => 'BCL Website',

    'subject_prefix' => 'New Free Case Review',

    // --- Transport ----------------------------------------------------------
    // 'mailgun'  = Mailgun HTTPS API (required on GoDaddy — SMTP is blocked)
    // 'smtp' / 'sendmail' also supported, but only on hosts that allow them.
    'transport' => 'mailgun',

    // Mailgun (used when transport = 'mailgun'):
    'mailgun_domain'  => 'mg.barrettcrimelaw.com',        // the verified sending subdomain
    'mailgun_api_key' => 'PASTE-MAILGUN-SENDING-API-KEY', // Mailgun → Sending → Domain settings → API keys
    'mailgun_region'  => 'us',                            // 'us' or 'eu' — match where you created the domain

    // --- Database -----------------------------------------------------------
    // Every lead is stored to a SQL database too (in addition to the JSONL file).
    // Default (db_dsn empty) = a first-party SQLite file at ~/bcl-secure/leads.sqlite,
    // which needs no cPanel setup. To use MySQL instead (browse it in phpMyAdmin),
    // create a DB + user in cPanel and set:
    //   'db_dsn' => 'mysql:host=localhost;dbname=USER_bcl_leads;charset=utf8mb4',
    //   'db_user' => 'USER_bclmail', 'db_pass' => '...'
    'db_enabled' => true,
    'db_dsn'     => '',
    'db_user'    => '',
    'db_pass'    => '',

    // --- CAPTCHA (Cloudflare Turnstile) ------------------------------------
    // Free. Create a widget at dash.cloudflare.com → Turnstile. Put the SITE key
    // in assets/js/site.js (TURNSTILE_SITEKEY) and the SECRET key here. Empty = off.
    'turnstile_secret' => '',

    // --- Security / anti-abuse ---------------------------------------------
    'allowed_origins' => [
        'https://www.barrettcrimelaw.com',
        'https://barrettcrimelaw.com',   // covers the /preview/ staging origin too
    ],
    'rate_limit_per_hour' => 6,     // max submissions per IP per rolling hour
    'min_seconds'         => 2,     // reject submissions faster than this (bots)
];
