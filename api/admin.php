<?php
// Staff API: login, session, state, and saving changes. Every write is permission-checked by role.
require __DIR__ . '/lib.php';
boot_api();

const CAN_WRITE = [
    'accounts' => ['owner', 'reception'],
    'payments' => ['owner', 'reception'],
    'clinical' => ['owner', 'dentist'],
];
const APPT_STATUS = ['SCHEDULED', 'CONFIRMED', 'ARRIVED', 'COMPLETED', 'CANCELLED', 'NO_SHOW'];
const METHODS = ['CASH', 'GCASH', 'CARD', 'BANK'];

$a = $_GET['a'] ?? '';

if ($a === 'login') {
    $in = require_post();
    $username = strtolower(str($in['username'] ?? '', 40));
    $password = is_string($in['password'] ?? null) ? $in['password'] : '';
    $ip = client_ip();
    if (throttle_count("login:$ip", 900) >= 8 || throttle_count("loginu:$username", 900) >= 8)
        throw new ApiError(429, 'Too many failed sign-in attempts. Try again in 15 minutes.');
    $u = q('SELECT * FROM users WHERE username = ?', [$username])->fetch();
    $ok = password_verify($password, $u['pass_hash'] ?? '$2y$10$abcdefghijklmnopqrstuuKXmMqCkGkYq6b3e8bMQxY0h9PbmYQeq');
    if (!$u || !$ok) {
        throttle_hit("login:$ip");
        throttle_hit("loginu:$username");
        throw new ApiError(401, 'Wrong username or password.');
    }
    start_session();
    session_regenerate_id(true);
    $_SESSION = ['uid' => (int)$u['id'], 'role' => $u['role'], 'person' => $u['person'], 'last' => time()];
    audit(['person' => $u['person'], 'role' => $u['role']], 'login', $ip);
    json_out(['role' => $u['role'], 'person' => $u['person']]);
}

if ($a === 'logout') {
    require_post();
    start_session();
    $_SESSION = [];
    session_destroy();
    json_out(['ok' => true]);
}

$user = require_user();
$role = $user['role'];
$clinicalRole = in_array($role, ['owner', 'dentist'], true);

if ($a === 'me') json_out(['role' => $role, 'person' => $user['person']]);

if ($a === 'state') {
    $patients = array_map(fn($r) => [
        'id' => $r['id'], 'name' => $r['name'], 'mobile' => $r['mobile'], 'email' => $r['email'],
        'birthDate' => $r['birth_date'], 'address' => $r['address'],
        'allergies' => $clinicalRole ? (string)$r['allergies'] : '', 'conditions' => $clinicalRole ? (string)$r['conditions'] : '',
        'consent' => (bool)$r['consent'], 'createdAt' => to_iso($r['created_at']),
    ], q('SELECT * FROM patients')->fetchAll());
    $appts = array_map(fn($r) => [
        'id' => $r['id'], 'patientId' => $r['patient_id'], 'serviceId' => $r['service_id'],
        'start' => to_iso($r['start_at']), 'end' => to_iso($r['end_at']), 'status' => $r['status'],
        'source' => $r['source'], 'ref' => $r['ref'] ?? '', 'createdAt' => to_iso($r['created_at']),
    ], q('SELECT * FROM appointments')->fetchAll());
    $out = ['patients' => $patients, 'appts' => $appts, 'accounts' => [], 'payments' => [], 'clinical' => []];
    foreach (q('SELECT kind, data FROM records ORDER BY updated_at')->fetchAll() as $r) {
        if ($r['kind'] === 'clinical' && !$clinicalRole) continue;
        if (isset($out[$r['kind']])) $out[$r['kind']][] = json_decode($r['data'], true);
    }
    $seq = 0;
    foreach (q('SELECT receipt FROM receipts')->fetchAll() as $r) $seq = max($seq, (int)substr($r['receipt'], strrpos($r['receipt'], '-') + 1));
    json_out(['db' => $out, 'seq' => $seq]);
}

if ($a === 'save') {
    $GLOBALS['ds_events'] = [];
    $in = require_post();
    $fixes = with_lock(fn() => save_changes($in, $user));
    respond_and_continue(['ok' => true, 'fixes' => $fixes]);
    foreach ($GLOBALS['ds_events'] as $ev) if (!empty($ev['mobile']) || ($ev['action'] ?? '') === 'status') push_sheet($ev);
    exit;
}

throw new ApiError(404, 'Unknown action');

/* ------------------------------------------------------------------ */

function list_of($v): array {
    return is_array($v) && array_is_list($v) ? array_slice($v, 0, 200) : [];
}

function save_changes(array $in, array $user): array {
    $role = $user['role'];
    $clinicalRole = in_array($role, ['owner', 'dentist'], true);
    $pdo = pdo();
    $pdo->beginTransaction();
    try {
        $now = gmdate('Y-m-d H:i:s');
        $fixes = ['payments' => []];

        /* patients */
        foreach (list_of($in['patients'] ?? null) as $p) {
            if (!is_array($p) || !valid_id($p['id'] ?? null)) throw new ApiError(422, 'Invalid patient.');
            $name = str($p['name'] ?? '', 80);
            if (mb_strlen($name) < 2) throw new ApiError(422, 'Enter the patient’s full name.');
            $mobRaw = trim((string)($p['mobile'] ?? ''));
            $mobile = $mobRaw === '' ? '' : norm_mobile($mobRaw);
            if ($mobRaw !== '' && $mobile === '') throw new ApiError(422, 'Mobile must start with 09 and have 11 digits.');
            $email = str($p['email'] ?? '', 120);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new ApiError(422, 'Enter a valid email or leave it blank.');
            $birth = str($p['birthDate'] ?? '', 10);
            if ($birth !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $birth)) throw new ApiError(422, 'Invalid birth date.');
            $addr = str($p['address'] ?? '', 255);
            $consent = !empty($p['consent']) ? 1 : 0;
            $exists = q('SELECT id FROM patients WHERE id = ?', [$p['id']])->fetch();
            if ($exists) {
                q('UPDATE patients SET name=?, mobile=?, email=?, birth_date=?, address=?, consent=?, updated_at=? WHERE id=?',
                    [$name, $mobile, $email, $birth, $addr, $consent, $now, $p['id']]);
                if ($clinicalRole) // reception never overwrites medical fields
                    q('UPDATE patients SET allergies=?, conditions=? WHERE id=?', [str($p['allergies'] ?? '', 500), str($p['conditions'] ?? '', 500), $p['id']]);
                audit($user, 'patient.update', $name);
            } else {
                q('INSERT INTO patients (id, name, mobile, email, birth_date, address, allergies, conditions, consent, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                    [$p['id'], $name, $mobile, $email, $birth, $addr, $clinicalRole ? str($p['allergies'] ?? '', 500) : '', $clinicalRole ? str($p['conditions'] ?? '', 500) : '', $consent, $now]);
                audit($user, 'patient.create', $name);
            }
        }

        /* appointments */
        foreach (list_of($in['appts'] ?? null) as $x) {
            if (!is_array($x) || !valid_id($x['id'] ?? null)) throw new ApiError(422, 'Invalid appointment.');
            $status = $x['status'] ?? '';
            if (!in_array($status, APPT_STATUS, true)) throw new ApiError(422, 'Invalid status.');
            $old = q('SELECT * FROM appointments WHERE id = ?', [$x['id']])->fetch();
            if ($old) { // only the status of an existing appointment can change
                if ($status !== $old['status']) {
                    if (!in_array($status, NEXT[$old['status']], true)) throw new ApiError(409, 'That status change isn’t allowed.');
                    q('UPDATE appointments SET status=?, updated_at=? WHERE id=?', [$status, $now, $x['id']]);
                    audit($user, 'appointment.status', "{$old['status']} → $status ({$x['id']})");
                    if (!empty($old['ref'])) $GLOBALS['ds_events'][] = ['action' => 'status', 'ref' => $old['ref'], 'status' => $status];
                }
                continue;
            }
            $sid = $x['serviceId'] ?? '';
            if (!is_string($sid) || !isset(SERVICES[$sid])) throw new ApiError(422, 'Unknown service.');
            if (!q('SELECT 1 FROM patients WHERE id = ?', [$x['patientId'] ?? ''])->fetch()) throw new ApiError(422, 'Unknown patient.');
            $start = parse_iso((string)($x['start'] ?? ''));
            if (!$start || !within_hours($start, SERVICES[$sid])) throw new ApiError(422, 'That time is outside clinic hours.');
            $end = $start->modify('+' . SERVICES[$sid] . ' minutes');
            if (overlaps(db_dt($start), db_dt($end))) throw new ApiError(409, 'That time was just taken. Pick another.', 'slot_taken');
            $ref = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));
            q("INSERT INTO appointments (id, patient_id, service_id, start_at, end_at, status, source, ref, created_at) VALUES (?,?,?,?,?,'SCHEDULED','staff',?,?)",
                [$x['id'], $x['patientId'], $sid, db_dt($start), db_dt($end), $ref, $now]);
            $pp = q('SELECT name, mobile, email FROM patients WHERE id = ?', [$x['patientId']])->fetch();
            $local = $start->setTimezone(new DateTimeZone(CLINIC_TZ));
            $GLOBALS['ds_events'][] = ['ref' => $ref, 'bookedAt' => (new DateTimeImmutable('now', new DateTimeZone(CLINIC_TZ)))->format('Y-m-d H:i'),
                'name' => $pp['name'], 'mobile' => $pp['mobile'], 'email' => $pp['email'], 'service' => SERVICE_NAMES[$sid] ?? $sid,
                'date' => $local->format('Y-m-d'), 'time' => $local->format('g:i A'), 'status' => 'SCHEDULED', 'source' => 'Front desk'];
            audit($user, 'appointment.create', "$sid " . db_dt($start));
        }

        /* accounts, payments, clinical notes */
        $touchedAccounts = [];
        foreach (['accounts', 'payments', 'clinical'] as $kind) {
            $rows = list_of($in[$kind] ?? null);
            if (!$rows) continue;
            if (!in_array($role, CAN_WRITE[$kind], true)) throw new ApiError(403, 'You don’t have permission to change that.');
            foreach ($rows as $r) {
                if (!is_array($r) || !valid_id($r['id'] ?? null) || !valid_id($r['patientId'] ?? null)) throw new ApiError(422, 'Invalid record.');
                if (!q('SELECT 1 FROM patients WHERE id = ?', [$r['patientId']])->fetch()) throw new ApiError(422, 'Unknown patient.');
                $oldRow = q('SELECT data FROM records WHERE kind = ? AND id = ?', [$kind, $r['id']])->fetch();
                $old = $oldRow ? json_decode($oldRow['data'], true) : null;
                if ($kind === 'accounts') {
                    if ($old) continue; // status is recomputed from payments below
                    $agreed = $r['agreed'] ?? null;
                    if (!is_int($agreed) || $agreed <= 0 || $agreed > 100000000000) throw new ApiError(422, 'Invalid agreed amount.');
                    $name = str($r['name'] ?? '', 120);
                    if ($name === '') throw new ApiError(422, 'Name the treatment.');
                    $rec = ['id' => $r['id'], 'patientId' => $r['patientId'], 'name' => $name, 'agreed' => $agreed,
                            'start' => str($r['start'] ?? '', 10), 'status' => 'ACTIVE'];
                    $touchedAccounts[$r['id']] = true;
                    audit($user, 'account.create', $name);
                } elseif ($kind === 'clinical') {
                    if ($old) continue; // notes are append-only
                    $rec = ['id' => $r['id'], 'patientId' => $r['patientId'], 'date' => str($r['date'] ?? '', 10),
                            'teeth' => str($r['teeth'] ?? '', 60), 'diagnosis' => str($r['diagnosis'] ?? '', 200),
                            'procedure' => str($r['procedure'] ?? '', 200), 'notes' => mb_substr(trim((string)($r['notes'] ?? '')), 0, 2000),
                            'by' => $user['person']];
                    if ($rec['diagnosis'] === '' && $rec['procedure'] === '' && $rec['notes'] === '') throw new ApiError(422, 'Add a diagnosis, procedure, or note.');
                    audit($user, 'clinical.create', $r['patientId']);
                } else { // payments
                    if ($old) { // existing payments can only be voided, and only by the owner
                        if (!empty($r['voided']) && empty($old['voided'])) {
                            if ($role !== 'owner') throw new ApiError(403, 'Only the owner can void a receipt.');
                            $reason = str($r['voidReason'] ?? '', 200);
                            if ($reason === '') throw new ApiError(422, 'A reason is required to void a receipt.');
                            $rec = $old + [];
                            $rec['voided'] = true; $rec['voidReason'] = $reason; $rec['voidedBy'] = $user['person']; $rec['voidedAt'] = to_iso($now);
                            $touchedAccounts[$old['accountId']] = true;
                            audit($user, 'payment.void', "{$old['receipt']}: $reason");
                        } else continue;
                    } else {
                        $amt = $r['amount'] ?? null;
                        if (!is_int($amt) || $amt <= 0) throw new ApiError(422, 'Enter a valid amount.');
                        $method = $r['method'] ?? '';
                        if (!in_array($method, METHODS, true)) throw new ApiError(422, 'Invalid payment method.');
                        $ref = str($r['ref'] ?? '', 60);
                        if ($method !== 'CASH' && $ref === '') throw new ApiError(422, 'Add the reference number for non-cash payments.');
                        $accRow = q("SELECT data FROM records WHERE kind='accounts' AND id = ?", [$r['accountId'] ?? ''])->fetch();
                        if (!$accRow) throw new ApiError(422, 'Unknown treatment plan.');
                        $acc = json_decode($accRow['data'], true);
                        if ($acc['patientId'] !== $r['patientId']) throw new ApiError(422, 'Plan belongs to another patient.');
                        if ($acc['agreed'] - paid_on($acc['id']) < $amt) throw new ApiError(422, 'That is more than the remaining balance.');
                        // Receipt numbers are assigned by the server so two staff members can never collide.
                        $receipt = next_receipt(str($r['receipt'] ?? '', 20));
                        if ($receipt !== ($r['receipt'] ?? null)) $fixes['payments'][$r['id']] = $receipt;
                        q('INSERT INTO receipts (receipt, payment_id) VALUES (?, ?)', [$receipt, $r['id']]);
                        $rec = ['id' => $r['id'], 'accountId' => $acc['id'], 'patientId' => $r['patientId'], 'amount' => $amt, 'method' => $method,
                                'ref' => $ref, 'paidAt' => to_iso($now), 'receipt' => $receipt, 'voided' => false, 'voidReason' => '', 'by' => $user['person']];
                        $touchedAccounts[$acc['id']] = true;
                        audit($user, 'payment.create', $receipt);
                    }
                }
                q('INSERT INTO records (kind, id, patient_id, data, updated_at) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE data = VALUES(data), updated_at = VALUES(updated_at)',
                    [$kind, $r['id'], $rec['patientId'], json_encode($rec, JSON_UNESCAPED_UNICODE), $now]);
            }
        }
        foreach (array_keys($touchedAccounts) as $aid) { // PAID once fully covered by non-voided payments
            $row = q("SELECT data FROM records WHERE kind='accounts' AND id = ?", [$aid])->fetch();
            if (!$row) continue;
            $acc = json_decode($row['data'], true);
            $acc['status'] = paid_on($aid) >= $acc['agreed'] ? 'PAID' : 'ACTIVE';
            q("UPDATE records SET data = ? WHERE kind='accounts' AND id = ?", [json_encode($acc, JSON_UNESCAPED_UNICODE), $aid]);
        }
        $pdo->commit();
        return $fixes;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

function paid_on(string $accountId): int {
    $sum = 0;
    foreach (q("SELECT data FROM records WHERE kind='payments'")->fetchAll() as $r) {
        $p = json_decode($r['data'], true);
        if ($p['accountId'] === $accountId && empty($p['voided'])) $sum += (int)$p['amount'];
    }
    return $sum;
}

/** Keep the client's proposed receipt number if free, otherwise issue the next one. */
function next_receipt(string $wanted): string {
    if ($wanted !== '' && preg_match('/^DS-\d{4}-\d{5}$/', $wanted) && !q('SELECT 1 FROM receipts WHERE receipt = ?', [$wanted])->fetch()) return $wanted;
    $seq = 0;
    foreach (q('SELECT receipt FROM receipts')->fetchAll() as $r) $seq = max($seq, (int)substr($r['receipt'], strrpos($r['receipt'], '-') + 1));
    return 'DS-' . (new DateTimeImmutable('now', new DateTimeZone(CLINIC_TZ)))->format('Y') . '-' . str_pad((string)($seq + 1), 5, '0', STR_PAD_LEFT);
}
