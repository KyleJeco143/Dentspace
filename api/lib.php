<?php
// Shared helpers. Blocked from direct web access by the nginx config.
declare(strict_types=1);

const CLINIC_TZ = 'Asia/Manila';
const SERVICES = [
    'svc-checkup' => 30, 'svc-clean' => 45, 'svc-fluoride' => 30, 'svc-sealant' => 30, 'svc-filling' => 60,
    'svc-veneers' => 120, 'svc-extraction' => 45, 'svc-root-canal' => 120, 'svc-crown' => 120, 'svc-denture' => 45,
    'svc-whitening' => 120, 'svc-braces-adjust' => 45, 'svc-braces-install' => 120, 'svc-xray' => 15, 'svc-emergency' => 60,
];
const SERVICE_NAMES = [
    'svc-checkup' => 'Check-up', 'svc-clean' => 'Cleaning', 'svc-fluoride' => 'Fluoride', 'svc-sealant' => 'Sealant',
    'svc-filling' => 'Filling (Pasta)', 'svc-veneers' => 'Veneers', 'svc-extraction' => 'Extraction (Bunot)',
    'svc-root-canal' => 'Root canal', 'svc-crown' => 'Crown', 'svc-denture' => 'Denture', 'svc-whitening' => 'Whitening',
    'svc-braces-adjust' => 'Braces adjustment', 'svc-braces-install' => 'Braces installation', 'svc-xray' => 'X-ray',
    'svc-emergency' => 'Emergency',
];
const ACTIVE = ['SCHEDULED', 'CONFIRMED', 'ARRIVED'];
const NEXT = [
    'SCHEDULED' => ['CONFIRMED', 'CANCELLED', 'NO_SHOW'], 'CONFIRMED' => ['ARRIVED', 'CANCELLED', 'NO_SHOW'],
    'ARRIVED' => ['COMPLETED'], 'COMPLETED' => [], 'CANCELLED' => [], 'NO_SHOW' => [],
];
const LEAD_MIN = 60;
const DAYS_AHEAD = 21;

class ApiError extends Exception {
    public int $status;
    public string $errCode;
    public function __construct(int $status, string $message, string $errCode = '') {
        parent::__construct($message);
        $this->status = $status;
        $this->errCode = $errCode;
    }
}

function json_out(array $data, int $status = 200): void {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function fail(int $status, string $message, string $code = ''): void {
    json_out(['error' => $message, 'code' => $code], $status);
}
function boot_api(): void {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    set_exception_handler(function (Throwable $e) {
        if ($e instanceof ApiError) fail($e->status, $e->getMessage(), $e->errCode);
        error_log('Dentspace: ' . $e);
        fail(500, 'Something went wrong on the server. Please try again.');
    });
}
/** POSTs must be JSON with a custom header; browsers can't send that cross-site without a CORS preflight. */
function require_post(): array {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') throw new ApiError(405, 'Method not allowed');
    if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') !== 'dentspace') throw new ApiError(400, 'Bad request');
    if (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== 0) throw new ApiError(400, 'Bad request');
    $raw = file_get_contents('php://input');
    if (strlen($raw) > 1000000) throw new ApiError(413, 'Request too large');
    $data = json_decode($raw ?: '', true);
    if (!is_array($data)) throw new ApiError(400, 'Invalid JSON');
    return $data;
}

function cfg(): array {
    static $c = null;
    if ($c === null) {
        $f = __DIR__ . '/config.php';
        if (!is_file($f)) throw new ApiError(500, 'Server is not configured yet (missing api/config.php).');
        $c = require $f;
    }
    return $c;
}
function pdo(): PDO {
    static $p = null;
    if ($p === null) {
        $c = cfg();
        $p = new PDO("mysql:host={$c['db_host']};dbname={$c['db_name']};charset=utf8mb4", $c['db_user'], $c['db_pass'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    }
    return $p;
}
function q(string $sql, array $args = []): PDOStatement {
    $st = pdo()->prepare($sql);
    $st->execute($args);
    return $st;
}
function with_lock(callable $fn) {
    $got = (int)q("SELECT GET_LOCK('dentspace_appts', 10)")->fetchColumn();
    if (!$got) throw new ApiError(503, 'The system is busy. Please try again in a moment.');
    try { return $fn(); } finally { q("SELECT RELEASE_LOCK('dentspace_appts')")->fetchColumn(); }
}

/* ---- time ---- */
function parse_iso(string $iso): ?DateTimeImmutable {
    try { return (new DateTimeImmutable($iso))->setTimezone(new DateTimeZone('UTC')); } catch (Throwable $e) { return null; }
}
function db_dt(DateTimeImmutable $d): string { return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'); }
function to_iso(?string $dt): ?string {
    if ($dt === null || $dt === '') return null;
    return (new DateTimeImmutable($dt, new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.000\Z');
}
function now_utc(): DateTimeImmutable { return new DateTimeImmutable('now', new DateTimeZone('UTC')); }
/** Start on a 15-minute grid, inside Manila opening hours (Mon-Sat 9-5, Sun 9-12). */
function within_hours(DateTimeImmutable $start, int $dur): bool {
    $l = $start->setTimezone(new DateTimeZone(CLINIC_TZ));
    if ((int)$l->format('s') !== 0) return false;
    $m = (int)$l->format('G') * 60 + (int)$l->format('i');
    $close = $l->format('w') === '0' ? 720 : 1020;
    return $m % 15 === 0 && $m >= 540 && $m + $dur <= $close;
}
function overlaps(string $startDb, string $endDb, string $exceptId = ''): bool {
    $in = "'" . implode("','", ACTIVE) . "'";
    return (int)q("SELECT COUNT(*) FROM appointments WHERE status IN ($in) AND start_at < ? AND end_at > ? AND id <> ?",
        [$endDb, $startDb, $exceptId])->fetchColumn() > 0;
}

/* ---- validation ---- */
function str($v, int $max): string {
    if (!is_string($v) && !is_numeric($v)) return '';
    $s = trim(preg_replace('/\s+/u', ' ', (string)$v) ?? '');
    return mb_substr($s, 0, $max);
}
function norm_mobile($v): string {
    $s = preg_replace('/[\s\-()]/', '', (string)$v) ?? '';
    if (str_starts_with($s, '+63')) $s = '0' . substr($s, 3);
    elseif (str_starts_with($s, '63') && strlen($s) === 12) $s = '0' . substr($s, 2);
    return preg_match('/^09\d{9}$/', $s) ? $s : '';
}
function valid_id($v): bool { return is_string($v) && preg_match('/^[A-Za-z0-9_-]{1,40}$/', $v) === 1; }
function new_id(): string { return bin2hex(random_bytes(12)); }
function client_ip(): string { return substr($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0', 0, 45); }

/* ---- throttling ---- */
function throttle_count(string $k, int $secs): int {
    return (int)q('SELECT COUNT(*) FROM throttle WHERE k = ? AND at > ?', [$k, gmdate('Y-m-d H:i:s', time() - $secs)])->fetchColumn();
}
function throttle_hit(string $k): void {
    q('INSERT INTO throttle (k, at) VALUES (?, ?)', [$k, gmdate('Y-m-d H:i:s')]);
    if (random_int(1, 50) === 1) q('DELETE FROM throttle WHERE at < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
}

/* ---- sessions ---- */
function start_session(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    session_name('ds_sess');
    session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
    session_start();
}
function current_user(): ?array {
    start_session();
    if (empty($_SESSION['uid'])) return null;
    if (time() - ($_SESSION['last'] ?? 0) > 8 * 3600) { $_SESSION = []; session_destroy(); return null; }
    $_SESSION['last'] = time();
    return ['id' => $_SESSION['uid'], 'role' => $_SESSION['role'], 'person' => $_SESSION['person']];
}
function require_user(): array {
    $u = current_user();
    if (!$u) throw new ApiError(401, 'Please sign in again.');
    return $u;
}
function audit(array $u, string $action, string $detail): void {
    q('INSERT INTO audit (at, user, role, action, detail) VALUES (?, ?, ?, ?, ?)',
        [gmdate('Y-m-d H:i:s'), $u['person'], $u['role'], $action, mb_substr($detail, 0, 500)]);
}

/* ---- schema ---- */
function migrate(): void {
    $t = 'ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
    $sql = [
        "CREATE TABLE IF NOT EXISTS users (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(40) NOT NULL UNIQUE, pass_hash VARCHAR(255) NOT NULL, role VARCHAR(12) NOT NULL, person VARCHAR(80) NOT NULL) $t",
        "CREATE TABLE IF NOT EXISTS patients (id VARCHAR(40) PRIMARY KEY, name VARCHAR(80) NOT NULL, mobile VARCHAR(16) NOT NULL DEFAULT '', email VARCHAR(120) NOT NULL DEFAULT '', birth_date VARCHAR(10) NOT NULL DEFAULT '', address VARCHAR(255) NOT NULL DEFAULT '', allergies TEXT NULL, conditions TEXT NULL, consent TINYINT(1) NOT NULL DEFAULT 0, created_at DATETIME NOT NULL, updated_at DATETIME NULL, INDEX (mobile)) $t",
        "CREATE TABLE IF NOT EXISTS appointments (id VARCHAR(40) PRIMARY KEY, patient_id VARCHAR(40) NOT NULL, service_id VARCHAR(30) NOT NULL, start_at DATETIME NOT NULL, end_at DATETIME NOT NULL, status VARCHAR(12) NOT NULL, source VARCHAR(16) NOT NULL, ref VARCHAR(8) NULL, created_at DATETIME NOT NULL, updated_at DATETIME NULL, INDEX (start_at), INDEX (patient_id)) $t",
        "CREATE TABLE IF NOT EXISTS records (kind VARCHAR(12) NOT NULL, id VARCHAR(40) NOT NULL, patient_id VARCHAR(40) NOT NULL, data MEDIUMTEXT NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY (kind, id), INDEX (patient_id)) $t",
        "CREATE TABLE IF NOT EXISTS receipts (receipt VARCHAR(20) PRIMARY KEY, payment_id VARCHAR(40) NOT NULL) $t",
        "CREATE TABLE IF NOT EXISTS audit (id INT AUTO_INCREMENT PRIMARY KEY, at DATETIME NOT NULL, user VARCHAR(80) NOT NULL, role VARCHAR(12) NOT NULL, action VARCHAR(40) NOT NULL, detail TEXT NULL) $t",
        "CREATE TABLE IF NOT EXISTS throttle (id INT AUTO_INCREMENT PRIMARY KEY, k VARCHAR(80) NOT NULL, at DATETIME NOT NULL, INDEX (k, at)) $t",
    ];
    foreach ($sql as $s) pdo()->exec($s);
}

/* ---- email (Gmail SMTP over SSL, port 465) ----
 * Needs smtp_user + smtp_pass in config.php (an app password). Returns false instead of throwing:
 * a mail problem must never break a booking. */
function send_mail(string $to, string $subject, string $body, string $replyTo = ''): bool {
    try {
        $c = cfg();
        if (empty($c['smtp_user']) || empty($c['smtp_pass'])) return false;
        $line = fn($s) => trim(preg_replace('/[\r\n]+/', ' ', (string)$s));
        $to = $line($to);
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return false;
        $host = $c['smtp_host'] ?? 'smtp.gmail.com';
        $fp = @stream_socket_client('ssl://' . $host . ':' . (int)($c['smtp_port'] ?? 465), $en, $es, 10);
        if (!$fp) return false;
        stream_set_timeout($fp, 10);
        $read = function () use ($fp) {
            $r = '';
            while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; }
            return $r;
        };
        $cmd = function (string $s, string $ok) use ($fp, $read) { fwrite($fp, $s . "\r\n"); return str_starts_with($read(), $ok); };
        $user = $line($c['smtp_user']);
        $fromName = $line($c['from_name'] ?? 'Dentspace');
        if (!str_starts_with($read(), '220')) return false;
        if (!$cmd('EHLO dentspace', '250')) return false;
        if (!$cmd('AUTH LOGIN', '334') || !$cmd(base64_encode($user), '334') || !$cmd(base64_encode((string)$c['smtp_pass']), '235')) return false;
        if (!$cmd("MAIL FROM:<$user>", '250') || !$cmd("RCPT TO:<$to>", '250') || !$cmd('DATA', '354')) return false;
        $h = ['From: =?UTF-8?B?' . base64_encode($fromName) . "?= <$user>", "To: <$to>",
              'Subject: =?UTF-8?B?' . base64_encode($line($subject)) . '?=', 'Date: ' . date('r'),
              'Message-ID: <' . bin2hex(random_bytes(8)) . '@dentspace>', 'MIME-Version: 1.0',
              'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: base64'];
        if ($replyTo !== '' && filter_var($line($replyTo), FILTER_VALIDATE_EMAIL)) $h[] = 'Reply-To: <' . $line($replyTo) . '>';
        $ok = $cmd(implode("\r\n", $h) . "\r\n\r\n" . chunk_split(base64_encode($body)) . "\r\n.", '250');
        $cmd('QUIT', '221');
        fclose($fp);
        return $ok;
    } catch (Throwable $e) {
        error_log('Dentspace mail: ' . $e->getMessage());
        return false;
    }
}

/* ---- Google Sheet log (Apps Script web app) ----
 * Sends one booking to the clinic's Google Sheet. Silent no-op unless sheet_url + sheet_secret are in config.php. */
function push_sheet(array $row): bool {
    try {
        $c = cfg();
        if (empty($c['sheet_url']) || empty($c['sheet_secret'])) return false;
        if (!str_starts_with((string)$c['sheet_url'], 'https://script.google.com/')) return false;
        $ctx = stream_context_create(['http' => [
            'method' => 'POST', 'timeout' => 15, 'ignore_errors' => true, 'follow_location' => 0,
            'header' => "Content-Type: application/json\r\n",
            'content' => json_encode($row + ['secret' => $c['sheet_secret']], JSON_UNESCAPED_UNICODE),
        ]]);
        // Apps Script runs doPost on this request; the redirect it answers with only carries the reply.
        $res = @file_get_contents($c['sheet_url'], false, $ctx);
        return $res !== false;
    } catch (Throwable $e) {
        error_log('Dentspace sheet: ' . $e->getMessage());
        return false;
    }
}
