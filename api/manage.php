<?php
// Public: a patient looks up their booking with reference number + mobile number, and can cancel it.
require __DIR__ . '/lib.php';
boot_api();
$in = require_post();

$ip = client_ip();
if (throttle_count("manage:$ip", 900) >= 10) throw new ApiError(429, 'Too many attempts. Please try again later or call the clinic.');
throttle_hit("manage:$ip");

$ref = strtoupper(str($in['ref'] ?? '', 8));
$mobile = norm_mobile($in['mobile'] ?? '');
if ($ref === '' || $mobile === '') throw new ApiError(422, 'Enter your reference number and the mobile number you booked with.');

$row = q('SELECT a.id, a.service_id, a.start_at, a.status, p.name, p.mobile, p.email
          FROM appointments a JOIN patients p ON p.id = a.patient_id
          WHERE a.ref = ? AND p.mobile = ? LIMIT 1', [$ref, $mobile])->fetch();
// One generic answer for "no such reference" and "wrong mobile" so nobody can probe for valid references.
if (!$row) throw new ApiError(404, 'We could not find a booking with those details. Check the reference and mobile number.');

$start = new DateTimeImmutable($row['start_at'], new DateTimeZone('UTC'));
$cancellable = in_array($row['status'], ['SCHEDULED', 'CONFIRMED'], true) && $start > now_utc()->modify('+120 minutes');
$info = [
    'ref' => $ref, 'service' => SERVICE_NAMES[$row['service_id']] ?? $row['service_id'],
    'start' => to_iso($row['start_at']), 'status' => $row['status'], 'canCancel' => $cancellable,
];

if (($in['action'] ?? 'lookup') !== 'cancel') json_out($info);

if (!$cancellable) throw new ApiError(409, 'This booking can no longer be cancelled online. Please call the clinic.');
q("UPDATE appointments SET status = 'CANCELLED', updated_at = ? WHERE id = ? AND status IN ('SCHEDULED','CONFIRMED')", [gmdate('Y-m-d H:i:s'), $row['id']]);
$info['status'] = 'CANCELLED';
$info['canCancel'] = false;
respond_and_continue($info);

// After the answer is sent: tell the clinic and update the Sheet.
$c = cfg();
$when = $start->setTimezone(new DateTimeZone(CLINIC_TZ))->format('D, M j, Y \a\t g:i A');
$clinic = $c['notify_to'] ?? ($c['smtp_user'] ?? '');
if ($clinic) {
    send_mail($clinic, "Booking cancelled by patient: {$row['name']}, $when",
        "A patient cancelled online.\n\nReference: $ref\nService:   {$info['service']}\nWas:       $when (Manila time)\nPatient:   {$row['name']}\nMobile:    {$row['mobile']}\n");
}
push_sheet(['action' => 'status', 'ref' => $ref, 'status' => 'CANCELLED']);
exit;
