<?php
// Diagnoses the Google Sheet connection. Run on the server:  php test-sheet.php
// Sends one clearly labelled test row and prints Google's reply (never the secret).
$c = require '/var/www/dentspace/api/config.php';
if (empty($c['sheet_url']) || empty($c['sheet_secret'])) exit("No sheet_url/sheet_secret in config.php. Run: bash set-sheet.sh\n");
$u = $c['sheet_url'];
echo "URL starts:  " . substr($u, 0, 45) . "...  (length " . strlen($u) . ")\n";
echo "URL ends:    ..." . substr($u, -12) . "\n";
if (!preg_match('#^https://script\.google\.com/macros/s/[A-Za-z0-9_-]+/exec$#', $u)) echo "WARNING: the URL has an unexpected shape (a mistyped character or a missing /exec).\n";
echo "allow_url_fopen: " . (ini_get('allow_url_fopen') ? 'on' : 'OFF') . "   openssl: " . (extension_loaded('openssl') ? 'yes' : 'NO') . "\n";
$row = ['ref' => 'TEST01', 'bookedAt' => date('Y-m-d H:i'), 'name' => 'Sheet Test', 'mobile' => '09170000009', 'email' => '',
        'service' => 'Check-up', 'date' => date('Y-m-d'), 'time' => '9:00 AM', 'status' => 'TEST', 'source' => 'test-sheet.php', 'secret' => $c['sheet_secret']];
$ctx = stream_context_create(['http' => ['method' => 'POST', 'timeout' => 20, 'ignore_errors' => true, 'follow_location' => 0,
    'header' => "Content-Type: application/json\r\n", 'content' => json_encode($row)]]);
$body = @file_get_contents($u, false, $ctx);
echo "\nGoogle status: " . ($http_response_header[0] ?? '(no response at all)') . "\n";
foreach ($http_response_header ?? [] as $h) if (stripos($h, 'Location:') === 0) echo "Redirect to:   " . substr($h, 0, 70) . "...\n";
// The script's own answer ("ok" or "forbidden") sits behind the redirect.
foreach ($http_response_header ?? [] as $h) if (stripos($h, 'Location:') === 0) {
    $body = @file_get_contents(trim(substr($h, 9)), false, stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]));
}
echo "Script answered: " . substr(trim((string)$body), 0, 200) . "\n\n";
if ($body === false) echo "Could not reach Google. The server may block outgoing HTTPS.\n";
elseif (stripos($body, 'forbidden') !== false) echo "The SECRET does not match the one in the script. Run: bash set-sheet.sh and retype it exactly.\n";
elseif (stripos($body, 'ok') === 0) echo "SUCCESS. Look for a row 'Sheet Test' in the Bookings tab.\n";
elseif (stripos($body, '404') !== false || stripos($body, 'not found') !== false || stripos($body, 'Sorry, unable to open') !== false) echo "Google cannot find that deployment. The Deployment ID has a typo, or the deployment was replaced.\n";
else echo "Unexpected reply. Send me the lines above.\n";
