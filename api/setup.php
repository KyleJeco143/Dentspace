<?php
// One-time setup: creates the tables and the three staff logins. install.sh deletes this file afterwards.
require __DIR__ . '/lib.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Frame-Options: DENY');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

try {
    if (!hash_equals((string)cfg()['setup_key'], (string)($_GET['key'] ?? ''))) { http_response_code(403); exit('Forbidden'); }
    migrate();
    $done = (int)q('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    $msg = '';
    if (!$done && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $rows = [];
        foreach (['owner' => 'Owner', 'dentist' => 'Dentist', 'reception' => 'Reception'] as $role => $label) {
            $u = strtolower(trim($_POST["u_$role"] ?? ''));
            $p = (string)($_POST["p_$role"] ?? '');
            $n = trim($_POST["n_$role"] ?? '') ?: $label;
            if (!preg_match('/^[a-z0-9._-]{3,40}$/', $u)) { $msg = "$label: username must be 3-40 letters/numbers."; break; }
            if (strlen($p) < 10) { $msg = "$label: password must be at least 10 characters."; break; }
            $rows[] = [$u, password_hash($p, PASSWORD_DEFAULT), $role, mb_substr($n, 0, 80)];
        }
        if (!$msg && count(array_unique(array_column($rows, 0))) < 3) $msg = 'Usernames must be different.';
        if (!$msg) {
            foreach ($rows as $r) q('INSERT INTO users (username, pass_hash, role, person) VALUES (?,?,?,?)', $r);
            $done = true;
        }
    }
} catch (Throwable $e) {
    error_log('Dentspace setup: ' . $e);
    http_response_code(500);
    exit('Setup failed. Check the database settings in api/config.php.');
}
?><!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Dentspace setup</title>
<style>body{font:16px/1.5 system-ui,sans-serif;max-width:520px;margin:40px auto;padding:0 20px}input{width:100%;padding:10px;margin:4px 0 12px;box-sizing:border-box}fieldset{margin:16px 0;border:1px solid #ccc;border-radius:8px}.e{color:#b42318}button{padding:12px 20px}</style>
<h1>Dentspace setup</h1>
<?php if ($done): ?>
<p><b>Done.</b> Tables are created and staff logins exist. Delete <code>api/setup.php</code> now (install.sh does this for you), then sign in at <a href="/admin/">/admin/</a>.</p>
<?php else: ?>
<p>Create the three staff logins. Passwords are stored hashed and shown nowhere.</p>
<?php if ($msg): ?><p class="e"><?= $h($msg) ?></p><?php endif; ?>
<form method="post" autocomplete="off">
<?php foreach (['owner' => 'Owner', 'dentist' => 'Dentist', 'reception' => 'Reception'] as $role => $label): ?>
<fieldset><legend><?= $label ?></legend>
<label>Display name<input name="n_<?= $role ?>" value="<?= $h($_POST["n_$role"] ?? '') ?>"></label>
<label>Username<input name="u_<?= $role ?>" value="<?= $h($_POST["u_$role"] ?? '') ?>" required></label>
<label>Password (10+ characters)<input type="password" name="p_<?= $role ?>" required></label></fieldset>
<?php endforeach; ?>
<button>Create logins</button></form>
<?php endif; ?>
