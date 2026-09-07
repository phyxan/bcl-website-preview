# Free Case Review form — setup & operations

The `/free-case-review/` form is handled first-party by `form.php`. Each lead is
**written to disk** (so it can never be lost) and **emailed to the firm** through
the **Mailgun HTTPS API**.

> Why Mailgun's API and not SMTP? GoDaddy shared hosting **blocks outbound SMTP**
> (ports 25/465/587 time out), and your inbox is on Google Workspace — sending
> straight from GoDaddy's IP would spam-file. Mailgun sends over HTTPS (port 443,
> which works) with proper SPF/DKIM, so mail authenticates and lands in the inbox.

## How it's wired

```
Browser ──POST──▶ /form.php ──┬─▶ append lead to  ~/bcl-secure/leads/leads-YYYY-MM.jsonl
                              └─▶ POST to Mailgun API (HTTPS 443) ─▶ firm inbox
```

- **Handler:** `form.php` (site root) — validation, anti-spam, storage, email.
- **Secrets & leads:** `~/bcl-secure/` — **outside** `public_html`, never web-reachable or in git.
- **Config path:** `form.php` reads `dirname(DOCUMENT_ROOT)/bcl-secure/mail-config.php`,
  so one config serves both `/preview/` and the live root.

## One-time setup

### 1. Create the Mailgun sending domain
1. Sign up at mailgun.com. Pick the **US** or **EU** region (remember which — it goes in the config).
2. **Sending → Domains → Add New Domain** → `mg.barrettcrimelaw.com` (a subdomain is best
   practice: it isolates the website's sending reputation from your normal Google mail).

### 2. Add Mailgun's DNS records in GoDaddy
Your DNS is at GoDaddy (`domaincontrol.com`). In **GoDaddy → Domain → DNS**, add the exact
records Mailgun shows for the domain. They look like this (copy the real values from Mailgun —
the DKIM key and selector are unique to your domain):

| Type  | Host (name)                         | Value                                   |
|-------|-------------------------------------|-----------------------------------------|
| TXT   | `mg`                                | `v=spf1 include:mailgun.org ~all`       |
| TXT   | `<selector>._domainkey.mg`          | `k=rsa; p=<long DKIM public key>`       |
| MX    | `mg`                                | `mxa.mailgun.org` (priority 10)         |
| MX    | `mg`                                | `mxb.mailgun.org` (priority 10)         |
| CNAME | `email.mg`                          | `mailgun.org` (optional; click tracking)|

Then click **Verify** in Mailgun. DNS can take up to a few hours to propagate.

### 3. Get the Sending API key
Mailgun → **Sending → Domain settings → API keys** (or your account's API security page).
Copy the **Sending key** for `mg.barrettcrimelaw.com`.

### 4. Fill in the server config
Edit `~/bcl-secure/mail-config.php` (already scaffolded on the server) and set:

```php
'to'              => 'intake@barrettcrimelaw.com',     // your Google Workspace inbox
'from'            => 'no-reply@mg.barrettcrimelaw.com', // on the sending subdomain
'transport'       => 'mailgun',
'mailgun_domain'  => 'mg.barrettcrimelaw.com',
'mailgun_api_key' => '<the Sending API key>',
'mailgun_region'  => 'us',                              // or 'eu'
```

That's it. Until this is filled, the handler still **stores every lead to disk**, so
nothing is lost in the meantime.

## Recommended (separate from the form): fix the root domain's email auth
`barrettcrimelaw.com` currently publishes **no SPF and no DMARC**. Regardless of the form,
add these in GoDaddy DNS to protect your Google Workspace mail from spoofing:

| Type | Host     | Value                                                          |
|------|----------|----------------------------------------------------------------|
| TXT  | `@`      | `v=spf1 include:_spf.google.com ~all`                          |
| TXT  | `_dmarc` | `v=DMARC1; p=none; rua=mailto:postmaster@barrettcrimelaw.com`  |

## Security features

- POST + HTTPS only; same-origin enforced when an `Origin`/`Referer` is present.
- Full server-side validation; dropdown values are allow-listed (tamper-proof).
- Header-injection safe: CRLF stripped from every header-bound field; the visitor's
  address goes only in `Reply-To`, never `From`.
- Anti-spam: hidden honeypot, submit-time trap, per-IP sliding-window rate limit.
- Secrets/leads live outside the web root; `display_errors` is off; API key sent only
  over TLS to Mailgun (cert verification on).

## Where the leads are

`~/bcl-secure/leads/leads-YYYY-MM.jsonl` — one JSON object per line (name, phone, email,
county, charge, status, message, IP, timestamp). Download over SFTP/SSH or `cat` it.
Email/API failures are logged to `~/bcl-secure/handler-errors.log`.

## Testing

Submit the form at `https://barrettcrimelaw.com/preview/free-case-review/`. Confirm a new
line appears in the leads file and (once Mailgun is verified + keyed) an email arrives.
Before the key is set, submissions still succeed and are stored — the log will show
`mailgun ... missing — lead stored only`.
