<?php
// Public: create an appointment from the booking page.
require __DIR__ . '/lib.php';
boot_api();
$in = require_post();
if (!empty($in['website'])) throw new ApiError(400, 'Invalid request'); // honeypot

$ip = client_ip();
if (throttle_count("book:$ip", 3600) >= 6) throw new ApiError(429, 'Too many booking attempts. Please try again later or call the clinic.');

$sid = $in['serviceId'] ?? '';
if (!is_string($sid) || !isset(SERVICES[$sid])) throw new ApiError(422, 'Choose a service.');
$dur = SERVICES[$sid];
$name = str($in['name'] ?? '', 80);
if (mb_strlen($name) < 2 || !preg_match('/\p{L}/u', $name)) throw new ApiError(422, 'Enter your full name.');
$mobile = norm_mobile($in['mobile'] ?? '');
if ($mobile === '') throw new ApiError(422, 'Enter an 11-digit mobile number starting with 09.');
$email = str($in['email'] ?? '', 120);
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError(422, 'Enter a valid email or leave it blank.');
if (($in['consent'] ?? false) !== true) throw new ApiError(422, 'Consent is required to book.');
$start = parse_iso((string)($in['start'] ?? ''));
if (!$start) throw new ApiError(422, 'Choose a date and time.');
$now = now_utc();
if ($start < $now->modify('+' . LEAD_MIN . ' minutes') || $start > $now->modify('+' . (DAYS_AHEAD + 2) . ' days') || !within_hours($start, $dur))
    throw new ApiError(422, 'That time is not available. Please pick another.', 'slot_taken');
$end = $start->modify("+$dur minutes");

throttle_hit("book:$ip");
$ref = with_lock(function () use ($start, $end, $sid, $name, $mobile, $email) {
    if (overlaps(db_dt($start), db_dt($end))) throw new ApiError(409, 'Someone just booked that time. Please pick another.', 'slot_taken');

    // One active booking per mobile per Manila day.
    $tz = new DateTimeZone(CLINIC_TZ);
    $ds = $start->setTimezone($tz)->setTime(0, 0);
    $de = $ds->modify('+1 day');
    $active = "'" . implode("','", ACTIVE) . "'";
    $dup = q("SELECT a.ref FROM appointments a JOIN patients p ON p.id = a.patient_id WHERE p.mobile = ? AND a.status IN ($active) AND a.start_at >= ? AND a.start_at < ? LIMIT 1",
        [$mobile, db_dt($ds), db_dt($de)])->fetch();
    if ($dup) throw new ApiError(409, 'You already have a booking on this day' . ($dup['ref'] ? " (reference {$dup['ref']})" : '') . '. Please call the clinic to change it.', 'duplicate');

    $p = q('SELECT id FROM patients WHERE mobile = ? AND LOWER(name) = LOWER(?) LIMIT 1', [$mobile, $name])->fetch();
    $now = gmdate('Y-m-d H:i:s');
    if ($p) {
        $pid = $p['id'];
        if ($email !== '') q("UPDATE patients SET email = ? WHERE id = ? AND email = ''", [$email, $pid]);
    } else {
        $pid = new_id();
        q('INSERT INTO patients (id, name, mobile, email, consent, created_at) VALUES (?, ?, ?, ?, 1, ?)', [$pid, $name, $mobile, $email, $now]);
    }
    $ref = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    q("INSERT INTO appointments (id, patient_id, service_id, start_at, end_at, status, source, ref, created_at) VALUES (?, ?, ?, ?, ?, 'SCHEDULED', 'booking_site', ?, ?)",
        [new_id(), $pid, $sid, db_dt($start), db_dt($end), $ref, $now]);
    return $ref;
});
// Answer the patient first, then send email in the background so a slow mail server never delays booking.
$cfgc = cfg();
$mailReady = !empty($cfgc['smtp_user']) && !empty($cfgc['smtp_pass']);
$payload = json_encode(['ref' => $ref, 'start' => to_iso(db_dt($start)), 'end' => to_iso(db_dt($end)),
                        'emailed' => $mailReady && $email !== ''], JSON_UNESCAPED_UNICODE);
ignore_user_abort(true);
ob_start();
echo $payload;
header('Content-Length: ' . ob_get_length());
header('Connection: close');
ob_end_flush();
flush();
if (function_exists('fastcgi_finish_request')) fastcgi_finish_request();

if ($mailReady) {
    $svcName = SERVICE_NAMES[$sid] ?? $sid;
    $when = $start->setTimezone(new DateTimeZone(CLINIC_TZ))->format('D, M j, Y \a\t g:i A');
    $clinic = $cfgc['notify_to'] ?? $cfgc['smtp_user'];
    $site = rtrim((string)($cfgc['site_url'] ?? ''), '/');
    send_mail($clinic, "New booking: $name, $svcName, $when",
        "A new appointment was booked online.\n\nReference: $ref\nService:   $svcName\nWhen:      $when (Manila time)\nPatient:   $name\nMobile:    $mobile\nEmail:     " . ($email ?: '(none given)') . "\n"
        . ($site ? "\nOpen the dashboard: $site/dashboard/\n" : ''), $email);
    if ($email !== '') {
        send_mail($email, "Your Dentspace appointment (ref $ref)",
            "Hi $name,\n\nYour appointment is saved.\n\nReference: $ref\nService:   $svcName\nWhen:      $when (Philippine time)\n\nPlease arrive 10 minutes early. To change or cancel, contact the clinic and give your reference number.\n\nDentspace", $clinic);
    }
}
push_sheet([
    'ref' => $ref, 'bookedAt' => (new DateTimeImmutable('now', new DateTimeZone(CLINIC_TZ)))->format('Y-m-d H:i'),
    'name' => $name, 'mobile' => $mobile, 'email' => $email,
    'service' => SERVICE_NAMES[$sid] ?? $sid,
    'date' => $start->setTimezone(new DateTimeZone(CLINIC_TZ))->format('Y-m-d'),
    'time' => $start->setTimezone(new DateTimeZone(CLINIC_TZ))->format('g:i A'),
    'status' => 'SCHEDULED', 'source' => 'Online booking',
]);
exit;
