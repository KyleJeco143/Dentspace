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
if (($in['consent'] ?? false) !== true) throw new ApiError(422, 'Consent is required to book.');
$start = parse_iso((string)($in['start'] ?? ''));
if (!$start) throw new ApiError(422, 'Choose a date and time.');
$now = now_utc();
if ($start < $now->modify('+' . LEAD_MIN . ' minutes') || $start > $now->modify('+' . (DAYS_AHEAD + 2) . ' days') || !within_hours($start, $dur))
    throw new ApiError(422, 'That time is not available. Please pick another.', 'slot_taken');
$end = $start->modify("+$dur minutes");

throttle_hit("book:$ip");
$ref = with_lock(function () use ($start, $end, $sid, $name, $mobile) {
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
    } else {
        $pid = new_id();
        q('INSERT INTO patients (id, name, mobile, consent, created_at) VALUES (?, ?, ?, 1, ?)', [$pid, $name, $mobile, $now]);
    }
    $ref = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
    q("INSERT INTO appointments (id, patient_id, service_id, start_at, end_at, status, source, ref, created_at) VALUES (?, ?, ?, ?, ?, 'SCHEDULED', 'booking_site', ?, ?)",
        [new_id(), $pid, $sid, db_dt($start), db_dt($end), $ref, $now]);
    return $ref;
});
json_out(['ref' => $ref, 'start' => to_iso(db_dt($start)), 'end' => to_iso(db_dt($end))]);
