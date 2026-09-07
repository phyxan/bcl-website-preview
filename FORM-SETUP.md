# Free Case Review form — setup & operations

The `/free-case-review/` form is handled first-party by `form.php`. No third-party
service ever receives a submission. Each lead is **written to disk** (so it can never
be lost) and **emailed to the firm** through the firm's own mail server.

## How it's wired

```
Browser ──POST──▶ /form.php ──┬─▶ append lead to  ~/bcl-secure/leads/leads-YYYY-MM.jsonl
                              └─▶ email the firm via PHPMailer (SMTP or sendmail)
```

- **Handler:** `form.php` (site root) — validation, anti-spam, storage, email.
- **Library:** `lib/PHPMailer/` (vendored, v6.9.1; web access denied via `lib/.htaccess`).
- **Secrets & leads:** `~/bcl-secure/` — **outside** `public_html`, so never web-reachable or in git.
- **Config path:** `form.php` reads `dirname(DOCUMENT_ROOT)/bcl-secure/mail-config.php`, so the
  same config serves both `/preview/` and the live root.

## One-time setup on the server

1. **Create the sending mailbox** in cPanel → *Email Accounts*:
   `no-reply@barrettcrimelaw.com` (any password you like). Optionally create
   `intake@barrettcrimelaw.com` to receive leads, or point `to` at an existing inbox.

2. **Create the secure directory and config** (already scaffolded by the deploy;
   fill in the mailbox password):

   ```
   ~/bcl-secure/mail-config.php     # chmod 600 — recipient + SMTP credentials
   ~/bcl-secure/leads/              # chmod 700 — captured leads (JSON lines)
   ~/bcl-secure/ratelimit/          # chmod 700 — per-IP counters
   ```

   Edit `~/bcl-secure/mail-config.php` and set `to`, `from`, and `smtp_pass`
   (see `mail-config.sample.php` for every option). Nothing else is required.

3. **No password?** Set `'transport' => 'sendmail'` instead and the server's local
   mailer sends it with no credentials (cPanel DKIM-signs outgoing domain mail).

That's it. Until email is configured the handler still **stores every lead to disk**,
so nothing is lost in the meantime.

## Security features

- POST + HTTPS only; same-origin enforced when an `Origin`/`Referer` is present.
- Full server-side validation; dropdown values are allow-listed (tamper-proof).
- Header-injection safe: CRLF stripped from every header-bound field, and PHPMailer
  rejects malformed addresses/subjects as a second layer. Visitor's address goes in
  `Reply-To`, never `From` (keeps SPF/DKIM valid).
- Anti-spam: hidden honeypot, submit-time trap, and a per-IP sliding-window rate limit.
- Secrets and leads live outside the web root; `display_errors` is off.

## Where the leads are

`~/bcl-secure/leads/leads-YYYY-MM.jsonl` — one JSON object per line
(name, phone, email, county, charge, status, message, IP, timestamp). Download it
over SFTP/SSH, or `cat` it. Email failures are logged to `~/bcl-secure/handler-errors.log`.

## Testing

Submit the form on `https://barrettcrimelaw.com/preview/free-case-review/` and confirm a
new line appears in the leads file and (once SMTP is set) an email arrives. To verify
validation/anti-spam without a browser, POST to `/preview/form.php` with an `Origin`
header (see the handler's rules).

## Turning it off / rollback

The form degrades safely: if `form.php` is removed, the form still shows the JS success
UI is gone and it falls back to a normal POST (404 until the handler returns). To disable
intake entirely, restore the previous static form. Leads already captured remain in
`~/bcl-secure/leads/`.
