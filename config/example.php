<?php
// Copy to local.php. Keep local.php out of Git. Never use the database root account in production.
return [
    'dsn' => 'mysql:host=127.0.0.1;port=3306;dbname=campusconnect;charset=utf8mb4',
    'db_user' => 'root',
    'db_password' => '',
    'timezone' => 'Australia/Sydney',
    'production' => false,
    'app_key' => 'REPLACE_WITH_64_RANDOM_HEX_CHARACTERS',
];
