<?php
// install.sh creates config.php for you. Manual setup: copy this file to config.php and fill it in.
return [
    'db_host'   => 'localhost',
    'db_name'   => 'dentspace',
    'db_user'   => 'dentspace',
    'db_pass'   => 'your-database-password',
    // Long random string. Needed once to open /api/setup.php, which install.sh then removes.
    'setup_key' => 'change-this-to-a-long-random-string',
];
