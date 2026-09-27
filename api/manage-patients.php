<?php
// Lists patients, removes one patient and everything tied to them, or merges two duplicate
// patient records into one. Run on the server:
//   php manage-patients.php list
//   php manage-patients.php delete <id>
//   php manage-patients.php merge <keep-id> <other-id>
require __DIR__ . '/lib.php';
$cmd = $argv[1] ?? '';
$fmtMobile = fn($m) => $m ? preg_replace('/(\d{4})(\d{3})(\d{4})/', '$1 $2 $3', $m) : '(none)';

if ($cmd === 'list') {
    foreach (q('SELECT id, name, mobile FROM patients ORDER BY name')->fetchAll() as $p) {
        $n = (int)q('SELECT COUNT(*) FROM appointments WHERE patient_id = ?', [$p['id']])->fetchColumn();
        printf("%-34s  %-28s  %-16s  %d appt(s)\n", $p['id'], $p['name'], $fmtMobile($p['mobile']), $n);
    }
    exit;
}

if ($cmd === 'delete') {
    $id = $argv[2] ?? '';
    if (!valid_id($id)) exit("Usage: php manage-patients.php delete <id>\nRun 'list' first to get the id.\n");
    $p = q('SELECT * FROM patients WHERE id = ?', [$id])->fetch();
    if (!$p) exit("No patient with id $id.\n");
    echo "About to permanently delete:\n";
    echo "  Patient:      {$p['name']}  ({$fmtMobile($p['mobile'])})\n";
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
    $keepId = $argv[2] ?? '';
    $otherId = $argv[3] ?? '';
    if (!valid_id($keepId) || !valid_id($otherId)) exit("Usage: php manage-patients.php merge <keep-id> <other-id>\nRun 'list' first to get both ids.\n");
    if ($keepId === $otherId) exit("Those are the same id.\n");
    $keep = q('SELECT * FROM patients WHERE id = ?', [$keepId])->fetch();
    $other = q('SELECT * FROM patients WHERE id = ?', [$otherId])->fetch();
    if (!$keep) exit("No patient with id $keepId.\n");
    if (!$other) exit("No patient with id $otherId.\n");
    echo "Keeping:  {$keep['name']}  ({$fmtMobile($keep['mobile'])})  [$keepId]\n";
    echo "Removing: {$other['name']}  ({$fmtMobile($other['mobile'])})  [$otherId]\n";
    echo "All of $otherId's appointments, treatment plans, payments and clinical notes move to $keepId, then $otherId is deleted.\n";
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

echo "Usage:\n  php manage-patients.php list\n  php manage-patients.php delete <id>\n  php manage-patients.php merge <keep-id> <other-id>\n";
