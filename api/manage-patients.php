<?php
// Lists patients, or removes one patient and everything tied to them (appointments, notes,
// payments, treatment plans, receipts). Run on the server:
//   php manage-patients.php list
//   php manage-patients.php delete <id>
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

echo "Usage:\n  php manage-patients.php list\n  php manage-patients.php delete <id>\n";
