<?php
// Lists patients, removes one patient and everything tied to them, or merges two duplicate
// patient records into one. Identify a patient by their id (from 'list') OR just their mobile
// number, which is far easier to type correctly in a console. Run on the server:
//   php manage-patients.php list
//   php manage-patients.php delete <id-or-mobile>
//   php manage-patients.php merge <keep id-or-mobile> <other id-or-mobile>
require __DIR__ . '/lib.php';
$cmd = $argv[1] ?? '';
$fmtMobile = fn($m) => $m ? preg_replace('/(\d{4})(\d{3})(\d{4})/', '$1 $2 $3', $m) : '(none)';

/** Finds one patient by exact id, or by mobile number (any formatting: spaces, +63, leading 0 all accepted). */
function resolve_patient(string $arg): ?array {
    if (valid_id($arg)) {
        $p = q('SELECT * FROM patients WHERE id = ?', [$arg])->fetch();
        if ($p) return $p;
    }
    $digits = preg_replace('/\D/', '', $arg);
    $digits = ltrim($digits, '0');
    if ($digits === '') return null;
    $matches = array_values(array_filter(
        q('SELECT * FROM patients')->fetchAll(),
        fn($p) => ltrim(preg_replace('/\D/', '', $p['mobile'] ?? ''), '0') === $digits && $digits !== ''
    ));
    if (count($matches) === 1) return $matches[0];
    if (count($matches) > 1) { echo "More than one patient has that mobile number; use the id from 'list' instead.\n"; exit(1); }
    return null;
}

if ($cmd === 'list') {
    foreach (q('SELECT id, name, mobile FROM patients ORDER BY name')->fetchAll() as $p) {
        $n = (int)q('SELECT COUNT(*) FROM appointments WHERE patient_id = ?', [$p['id']])->fetchColumn();
        printf("%-34s  %-28s  %-16s  %d appt(s)\n", $p['id'], $p['name'], $fmtMobile($p['mobile']), $n);
    }
    exit;
}

if ($cmd === 'delete') {
    $arg = $argv[2] ?? '';
    if ($arg === '') exit("Usage: php manage-patients.php delete <id-or-mobile>\nRun 'list' first.\n");
    $p = resolve_patient($arg);
    if (!$p) exit("No patient matches '$arg'.\n");
    $id = $p['id'];
    echo "About to permanently delete:\n";
    echo "  Patient:      {$p['name']}  ({$fmtMobile($p['mobile'])})  [$id]\n";
    echo "  Appointments: " . (int)q('SELECT COUNT(*) FROM appointments WHERE patient_id = ?', [$id])->fetchColumn() . "\n";
    echo "  Records:      " . (int)q('SELECT COUNT(*) FROM records WHERE patient_id = ?', [$id])->fetchColumn() . " (treatment plans, payments, clinical notes)\n";
    echo "Type YES to confirm: ";
    if (trim(fgets(STDIN)) !== 'YES') exit("Cancelled.\n");

    $pdo = pdo();
    $pdo->beginTransaction();
    try {
        $payIds = array_column(q("SELECT id FROM records WHERE kind = 'payments' AND patient_id = ?", [$id])->fetchAll(), 'id');
        if ($payIds) {
            $in = implode(',', array_fill(0, count($payIds), '?'));
            q("DELETE FROM receipts WHERE payment_id IN ($in)", $payIds);
        }
        q('DELETE FROM records WHERE patient_id = ?', [$id]);
        q('DELETE FROM appointments WHERE patient_id = ?', [$id]);
        q('DELETE FROM patients WHERE id = ?', [$id]);
        $pdo->commit();
        echo "Deleted.\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo 'Failed, nothing was changed: ' . $e->getMessage() . "\n";
    }
    exit;
}

if ($cmd === 'merge') {
    $keepArg = $argv[2] ?? '';
    $otherArg = $argv[3] ?? '';
    if ($keepArg === '' || $otherArg === '') exit("Usage: php manage-patients.php merge <keep id-or-mobile> <other id-or-mobile>\nRun 'list' first.\n");
    $keep = resolve_patient($keepArg);
    $other = resolve_patient($otherArg);
    if (!$keep) exit("No patient matches '$keepArg'.\n");
    if (!$other) exit("No patient matches '$otherArg'.\n");
    if ($keep['id'] === $other['id']) exit("Those are the same patient.\n");
    $keepId = $keep['id']; $otherId = $other['id'];
    echo "Keeping:  {$keep['name']}  ({$fmtMobile($keep['mobile'])})  [$keepId]\n";
    echo "Removing: {$other['name']}  ({$fmtMobile($other['mobile'])})  [$otherId]\n";
    echo "All of {$other['name']}'s appointments, treatment plans, payments and clinical notes move to {$keep['name']}, then that record is deleted.\n";
    echo "Type YES to confirm: ";
    if (trim(fgets(STDIN)) !== 'YES') exit("Cancelled.\n");

    $pdo = pdo();
    $pdo->beginTransaction();
    try {
        q('UPDATE appointments SET patient_id = ? WHERE patient_id = ?', [$keepId, $otherId]);
        q('UPDATE records SET patient_id = ? WHERE patient_id = ?', [$keepId, $otherId]);
        if (empty($keep['mobile']) && !empty($other['mobile'])) q('UPDATE patients SET mobile = ? WHERE id = ?', [$other['mobile'], $keepId]);
        if (empty($keep['email']) && !empty($other['email'])) q('UPDATE patients SET email = ? WHERE id = ?', [$other['email'], $keepId]);
        q('DELETE FROM patients WHERE id = ?', [$otherId]);
        $pdo->commit();
        echo "Merged.\n";
    } catch (Throwable $e) {
        $pdo->rollBack();
        echo 'Failed, nothing was changed: ' . $e->getMessage() . "\n";
    }
    exit;
}

echo "Usage:\n  php manage-patients.php list\n  php manage-patients.php delete <id-or-mobile>\n  php manage-patients.php merge <keep id-or-mobile> <other id-or-mobile>\n";
