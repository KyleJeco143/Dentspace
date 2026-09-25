<?php
// Diagnoses email sending. Run on the server:  php test-mail.php
// Prints Gmail's replies (never the password) and tries to send a test email to the clinic address.
$c = require '/var/www/dentspace/api/config.php';
foreach (['smtp_user', 'smtp_pass'] as $k) if (empty($c[$k])) exit("Missing $k in config.php. Run: bash set-mail.sh\n");
$to = $c['notify_to'] ?? $c['smtp_user'];
echo "Sending as {$c['smtp_user']} to $to\n";
$fp = @stream_socket_client('ssl://' . ($c['smtp_host'] ?? 'smtp.gmail.com') . ':' . ($c['smtp_port'] ?? 465), $en, $es, 10);
if (!$fp) exit("Cannot reach Gmail: $es ($en). The server may block outgoing port 465.\n");
stream_set_timeout($fp, 10);
function rd($fp) { $r = ''; while (($l = fgets($fp, 515)) !== false) { $r .= $l; if (strlen($l) < 4 || $l[3] === ' ') break; } return trim($r); }
function step($fp, $label, $line = null) { if ($line !== null) fwrite($fp, $line . "\r\n"); $r = rd($fp); echo str_pad($label, 12) . substr($r, 0, 200) . "\n"; return $r; }
step($fp, 'connect');
step($fp, 'EHLO', 'EHLO dentspace');
step($fp, 'AUTH', 'AUTH LOGIN');
step($fp, 'user', base64_encode($c['smtp_user']));
$r = step($fp, 'password', base64_encode($c['smtp_pass']));
if (!str_starts_with($r, '235')) {
    echo "\nGmail REJECTED the login. The app password is wrong, expired, or was cancelled when the Google password changed.\n";
    echo "Fix: create a new app password at myaccount.google.com/apppasswords, then run: bash set-mail.sh\n";
    exit(1);
}
step($fp, 'MAIL FROM', 'MAIL FROM:<' . $c['smtp_user'] . '>');
step($fp, 'RCPT TO', 'RCPT TO:<' . $to . '>');
step($fp, 'DATA', 'DATA');
$msg = "From: Dentspace <{$c['smtp_user']}>\r\nTo: <$to>\r\nSubject: Dentspace test email\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\nIf you can read this, email from your booking server works.\r\n.";
$r = step($fp, 'send', $msg);
fwrite($fp, "QUIT\r\n");
echo str_starts_with($r, '250') ? "\nSUCCESS. Check the inbox (and Spam) of $to.\n" : "\nFAILED at the final step.\n";
