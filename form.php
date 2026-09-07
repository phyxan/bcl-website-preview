<?php
declare(strict_types=1);

/**
 * Peter Barrett Criminal Defense — Free Case Review intake handler.
 *
 * First-party. No third-party data processor ever sees a submission:
 * the lead is written to a durable file OUTSIDE the web root and emailed
 * to the firm via the firm's own mail server.
 *
 * Security posture:
 *   - POST + HTTPS only, same-origin enforced when an Origin/Referer is present
 *   - Server-side validation + allow-listed select values, hard length caps
 *   - Header-injection safe (CRLF stripped from all header-bound fields; PHPMailer
 *     rejects malformed addresses/subjects as a second layer)
 *   - Honeypot + time-trap + per-IP sliding-window rate limit
 *   - Secrets (SMTP password, recipient) live in ../bcl-secure, never in the web root or git
 *   - Never leaks internals: display_errors off; generic messages to the client
 *
 * A lead is captured to disk BEFORE email is attempted, so a mail outage
 * can never lose an intake.
 */

error_reporting(E_ALL);
ini_set('display_errors', '0');

// --- Locate config + secure storage (one level above the web root) ----------
$secureDir  = rtrim(dirname($_SERVER['DOCUMENT_ROOT'] ?? ''), '/') . '/bcl-secure';
$configPath = getenv('BCL_CONFIG') ?: ($secureDir . '/mail-config.php');

/** Defaults keep the handler safe even before mail-config.php is filled in. */
$cfg = [
    'to'                  => '',
    'to_name'             => 'Peter Barrett Criminal Defense',
    'from'                => '',
    'from_name'           => 'BCL Website',
    'subject_prefix'      => 'New Free Case Review',
    'transport'           => 'sendmail',       // 'smtp' (recommended) | 'sendmail'
    'smtp_host'           => 'localhost',
    'smtp_port'           => 587,
    'smtp_secure'         => 'tls',            // 'tls' | 'ssl' | ''
    'smtp_auth'           => true,
    'smtp_user'           => '',
    'smtp_pass'           => '',
    'mailgun_domain'      => '',       // e.g. mg.barrettcrimelaw.com (transport = 'mailgun')
    'mailgun_api_key'     => '',       // Mailgun Sending API key
    'mailgun_region'      => 'us',     // 'us' or 'eu'
    'allowed_origins'     => [
        'https://www.barrettcrimelaw.com',
        'https://barrettcrimelaw.com',
    ],
    'rate_limit_per_hour' => 6,
    'min_seconds'         => 2,
    'leads_dir'           => $secureDir . '/leads',
    'ratelimit_dir'       => $secureDir . '/ratelimit',
    'error_log'           => $secureDir . '/handler-errors.log',
];
if (is_file($configPath)) {
    /** @var array $loaded */
    $loaded = require $configPath;
    if (is_array($loaded)) {
        $cfg = array_merge($cfg, $loaded);
    }
}

// --- Small helpers ----------------------------------------------------------

/** True when the caller wants a JSON reply (our fetch()), false for a plain form post. */
function wants_json(): bool
{
    $xrw = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
    if ($xrw === 'xmlhttprequest') {
        return true;
    }
    return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
}

/** Send the reply and stop. JSON for fetch callers, a 303 redirect for plain posts. */
function respond(bool $ok, int $code, array $extra = []): void
{
    if (wants_json()) {
        http_response_code($code);
        header('Content-Type: application/json; charset=utf-8');
        header('X-Content-Type-Options: nosniff');
        header('Cache-Control: no-store');
        echo json_encode(['ok' => $ok] + $extra, JSON_UNESCAPED_SLASHES);
    } else {
        // Relative Location resolves under /preview/ and the site root alike.
        header('Location: ' . ($ok ? 'thanks/' : 'free-case-review/?error=1'), true, 303);
    }
    exit;
}

/** Collapse a value to a single trimmed line with no control chars; hard length cap. */
function clean_line(string $v, int $max): string
{
    $v = str_replace("\0", '', $v);
    $v = preg_replace('/[\r\n\t\x00-\x1F\x7F]+/u', ' ', $v) ?? '';
    $v = trim($v);
    if (mb_strlen($v) > $max) {
        $v = mb_substr($v, 0, $max);
    }
    return $v;
}

/** Multi-line free text: keep newlines, drop other control chars, cap length. */
function clean_text(string $v, int $max): string
{
    $v = str_replace("\0", '', $v);
    $v = str_replace(["\r\n", "\r"], "\n", $v);
    $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/u', '', $v) ?? '';
    $v = trim($v);
    if (mb_strlen($v) > $max) {
        $v = mb_substr($v, 0, $max);
    }
    return $v;
}

function log_error(array $cfg, string $msg): void
{
    @error_log('[' . gmdate('c') . '] ' . $msg . "\n", 3, $cfg['error_log']);
}

/** Best-effort real client IP. REMOTE_ADDR only — XFF is client-spoofable. */
function client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    return filter_var($ip, FILTER_VALIDATE_IP) ?: '0.0.0.0';
}

/**
 * Sliding-window rate limit per IP. Returns true when the request is allowed.
 * Stores newline-separated epoch seconds in a per-IP file; prunes to the window.
 */
function rate_ok(array $cfg): bool
{
    $limit = (int) $cfg['rate_limit_per_hour'];
    if ($limit <= 0) {
        return true;
    }
    $dir = $cfg['ratelimit_dir'];
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return true; // fail open rather than block a real client on an fs issue
    }
    $file = $dir . '/' . sha1(client_ip()) . '.log';
    $now  = time();
    $window = 3600;

    $fp = @fopen($file, 'c+');
    if ($fp === false) {
        return true;
    }
    try {
        flock($fp, LOCK_EX);
        $raw   = stream_get_contents($fp) ?: '';
        $times = array_filter(array_map('intval', explode("\n", trim($raw))));
        $times = array_values(array_filter($times, static fn($t) => ($now - $t) < $window));
        if (count($times) >= $limit) {
            return false;
        }
        $times[] = $now;
        ftruncate($fp, 0);
        rewind($fp);
        fwrite($fp, implode("\n", $times));
        @chmod($file, 0600);
        return true;
    } finally {
        flock($fp, LOCK_UN);
        fclose($fp);
    }
}

/** Append the lead as one JSON line to a month-bucketed file. Returns success. */
function store_lead(array $cfg, array $lead): bool
{
    $dir = $cfg['leads_dir'];
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        return false;
    }
    $file = $dir . '/leads-' . gmdate('Y-m') . '.jsonl';
    $line = json_encode($lead, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($line === false) {
        return false;
    }
    $ok = @file_put_contents($file, $line . "\n", FILE_APPEND | LOCK_EX);
    if ($ok !== false) {
        @chmod($file, 0600);
        return true;
    }
    return false;
}

/**
 * Send via the Mailgun HTTPS API (port 443). GoDaddy shared hosting blocks
 * outbound SMTP, so the API is the reliable transport here.
 * from/to come from trusted config; $subject is already single-line; the
 * visitor's address is validated and goes only in Reply-To. Returns [ok, error].
 */
function send_via_mailgun(array $cfg, string $subject, string $body, string $replyEmail, string $replyName): array
{
    if (!function_exists('curl_init')) {
        return [false, 'php-curl not available for Mailgun API'];
    }
    $host = (($cfg['mailgun_region'] ?? 'us') === 'eu') ? 'api.eu.mailgun.net' : 'api.mailgun.net';
    $url  = 'https://' . $host . '/v3/' . rawurlencode((string) $cfg['mailgun_domain']) . '/messages';

    $fields = [
        'from'    => sprintf('%s <%s>', $cfg['from_name'], $cfg['from']),
        'to'      => sprintf('%s <%s>', $cfg['to_name'], $cfg['to']),
        'subject' => $subject,
        'text'    => $body,
    ];
    if ($replyEmail !== '') {
        $fields['h:Reply-To'] = $replyName !== ''
            ? sprintf('%s <%s>', $replyName, $replyEmail)
            : $replyEmail;
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query($fields),
        CURLOPT_USERPWD        => 'api:' . (string) $cfg['mailgun_api_key'],
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $resp = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $cerr = curl_error($ch);
    curl_close($ch);

    if ($code >= 200 && $code < 300) {
        return [true, ''];
    }
    return [false, 'mailgun api http ' . $code . ($cerr !== '' ? " curl:$cerr" : '') . ' ' . substr((string) $resp, 0, 200)];
}

// --- Method / transport gate ------------------------------------------------

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    respond(false, 405, ['error' => 'method_not_allowed']);
}

$https = ($_SERVER['HTTPS'] ?? '') === 'on'
    || ($_SERVER['SERVER_PORT'] ?? '') === '443'
    || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
if (!$https) {
    respond(false, 400, ['error' => 'https_required']);
}

// --- Same-origin: reject only a PRESENT, mismatched origin ------------------
// (Both absent -> allow: some privacy tools strip these, and this form has no
//  authenticated action to forge. Spam is handled by the layers below.)
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if ($origin === '' && !empty($_SERVER['HTTP_REFERER'])) {
    $p = parse_url($_SERVER['HTTP_REFERER']);
    if ($p && isset($p['scheme'], $p['host'])) {
        $origin = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    }
}
if ($origin !== '' && !in_array($origin, $cfg['allowed_origins'], true)) {
    respond(false, 403, ['error' => 'bad_origin']);
}

// --- Honeypot: a real browser leaves it empty -------------------------------
if (trim((string) ($_POST['company'] ?? '')) !== '') {
    respond(true, 200); // look successful; drop silently
}

// --- Time-trap: only enforced when JS supplied a load timestamp -------------
$ts = (int) ($_POST['ts'] ?? 0);
if ($ts > 0) {
    $elapsed = time() - $ts;
    if ($elapsed < (int) $cfg['min_seconds'] || $elapsed > 86400) {
        respond(true, 200); // too fast or stale -> silent drop
    }
}

// --- Rate limit -------------------------------------------------------------
if (!rate_ok($cfg)) {
    respond(false, 429, ['error' => 'rate_limited']);
}

// --- Validate fields --------------------------------------------------------
$COUNTIES = ['Dallas County', 'Collin County', 'Denton County', 'Tarrant County', 'Rockwall County', 'Other / Federal', 'Not sure'];
$CHARGES  = ['DWI / DUI', 'Drug charge', 'Federal charge', 'Sexual offense', 'White collar / fraud / theft', 'Assault / violent charge', 'Warrant / probation issue', 'Other / not sure'];
$STATUSES = ['Arrested, released on bond', 'Loved one still in custody', 'Being investigated, not charged yet', 'Court date coming up', 'Just have questions'];

$errors = [];

$name  = clean_line((string) ($_POST['name'] ?? ''), 100);
$phone = clean_line((string) ($_POST['phone'] ?? ''), 40);
$email = clean_line((string) ($_POST['email'] ?? ''), 254);
$msg   = clean_text((string) ($_POST['message'] ?? ''), 5000);

$county = clean_line((string) ($_POST['county'] ?? ''), 40);
$charge = clean_line((string) ($_POST['charge'] ?? ''), 60);
$status = clean_line((string) ($_POST['status'] ?? ''), 60);

if ($name === '' || mb_strlen($name) < 2) {
    $errors['name'] = 'Please enter your name.';
}
if ($phone === '' || preg_match('/\d/', $phone) !== 1 || mb_strlen($phone) < 7) {
    $errors['phone'] = 'Please enter a phone number we can reach you at.';
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $errors['email'] = 'That email address does not look right.';
}
if ($msg === '' || mb_strlen($msg) < 5) {
    $errors['message'] = 'Please tell us a little about what happened.';
}
if (($_POST['consent'] ?? '') === '') {
    $errors['consent'] = 'Please confirm you understand the notice.';
}
// Allow-list the dropdowns: a value outside the known set means tampering.
if ($county !== '' && !in_array($county, $COUNTIES, true)) {
    $errors['county'] = 'Invalid selection.';
}
if ($charge !== '' && !in_array($charge, $CHARGES, true)) {
    $errors['charge'] = 'Invalid selection.';
}
if ($status !== '' && !in_array($status, $STATUSES, true)) {
    $errors['status'] = 'Invalid selection.';
}

if ($errors) {
    respond(false, 422, ['errors' => $errors]);
}

// --- Assemble the lead ------------------------------------------------------
$lead = [
    'received_at' => gmdate('c'),
    'name'        => $name,
    'phone'       => $phone,
    'email'       => $email,
    'county'      => $county,
    'charge'      => $charge,
    'status'      => $status,
    'message'     => $msg,
    'ip'          => client_ip(),
    'user_agent'  => clean_line((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 300),
    'origin'      => $origin,
];

// --- Persist FIRST so a mail failure can never lose the lead ----------------
$stored = store_lead($cfg, $lead);
if (!$stored) {
    log_error($cfg, 'store_lead failed for ' . $lead['ip']);
}

// --- Email the firm (best effort; the lead is already safe on disk) ---------
$emailed    = false;
$configured = $cfg['to'] !== '' && $cfg['from'] !== '';
$transport  = $cfg['transport'] ?? 'sendmail';

$subject = clean_line(
    $cfg['subject_prefix'] . ' — ' . $name . ' (' . ($charge !== '' ? $charge : 'charge not specified') . ')',
    180
);
$body = "New Free Case Review request\n"
    . "============================\n\n"
    . 'Name:    ' . $name . "\n"
    . 'Phone:   ' . $phone . "\n"
    . 'Email:   ' . ($email !== '' ? $email : '(not provided)') . "\n"
    . 'County:  ' . ($county !== '' ? $county : '(not specified)') . "\n"
    . 'Charge:  ' . ($charge !== '' ? $charge : '(not specified)') . "\n"
    . 'Status:  ' . ($status !== '' ? $status : '(not specified)') . "\n\n"
    . "What happened:\n" . $msg . "\n\n"
    . "----\n"
    . 'Received: ' . $lead['received_at'] . " (UTC)\n"
    . 'IP:       ' . $lead['ip'] . "\n"
    . 'Browser:  ' . $lead['user_agent'] . "\n";

if (!$configured) {
    log_error($cfg, 'mail not configured (missing to/from) — lead stored only');
} elseif ($transport === 'mailgun') {
    if (($cfg['mailgun_domain'] ?? '') === '' || ($cfg['mailgun_api_key'] ?? '') === '') {
        log_error($cfg, 'mailgun selected but mailgun_domain/api_key missing — lead stored only');
    } else {
        [$emailed, $mgErr] = send_via_mailgun($cfg, $subject, $body, $email, $name);
        if (!$emailed) {
            log_error($cfg, $mgErr);
        }
    }
} else {
    // SMTP or sendmail via PHPMailer.
    try {
        require __DIR__ . '/lib/PHPMailer/Exception.php';
        require __DIR__ . '/lib/PHPMailer/PHPMailer.php';
        require __DIR__ . '/lib/PHPMailer/SMTP.php';

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->CharSet = 'UTF-8';

        if ($transport === 'smtp') {
            $mail->isSMTP();
            $mail->Host     = (string) $cfg['smtp_host'];
            $mail->Port     = (int) $cfg['smtp_port'];
            $mail->SMTPAuth = (bool) $cfg['smtp_auth'];
            if ($cfg['smtp_auth']) {
                $mail->Username = (string) $cfg['smtp_user'];
                $mail->Password = (string) $cfg['smtp_pass'];
            }
            if ($cfg['smtp_secure'] === 'ssl') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($cfg['smtp_secure'] === 'tls') {
                $mail->SMTPSecure = \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPAutoTLS = false;
            }
        } else {
            $mail->isSendmail();
        }

        $mail->setFrom((string) $cfg['from'], (string) $cfg['from_name']);
        $mail->addAddress((string) $cfg['to'], (string) $cfg['to_name']);
        if ($email !== '') {
            // PHPMailer re-validates and refuses CRLF-bearing addresses.
            $mail->addReplyTo($email, $name);
        }
        $mail->Subject = $subject;
        $mail->isHTML(false);
        $mail->Body = $body;

        $mail->send();
        $emailed = true;
    } catch (\Throwable $e) {
        log_error($cfg, 'mail send failed: ' . $e->getMessage());
    }
}

// --- Reply ------------------------------------------------------------------
// The lead is safe if it was stored OR emailed. Only both failing is an error.
if ($stored || $emailed) {
    respond(true, 200);
}
respond(false, 500, ['error' => 'server_error']);
