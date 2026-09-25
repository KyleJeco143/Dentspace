<?php
// Public: busy time ranges (no personal data) so the booking page can hide taken slots.
require __DIR__ . '/lib.php';
boot_api();
$in = "'" . implode("','", ACTIVE) . "'";
$from = now_utc()->modify('-1 day');
$to = now_utc()->modify('+' . (DAYS_AHEAD + 2) . ' days');
$rows = q("SELECT start_at, end_at FROM appointments WHERE status IN ($in) AND end_at > ? AND start_at < ?", [db_dt($from), db_dt($to)])->fetchAll();
json_out(['busy' => array_map(fn($r) => [to_iso($r['start_at']), to_iso($r['end_at'])], $rows)]);
