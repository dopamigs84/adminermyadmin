<?php
// Copy to config.local.php. Keep outside the public/ document root.
return [
    'name' => 'AdminerMyAdmin',
    'sqlserver_encrypt' => true,
    // true preserves compatibility with the original self-signed SQL Server.
    // Set false once the server certificate is trusted by Ubuntu.
    'sqlserver_trust_certificate' => true,
    // Optional: SQLite is disabled until an application password hash is set.
    // Generate with: php -r 'echo password_hash(readline("Password SQLite: "), PASSWORD_DEFAULT), PHP_EOL;'
    'sqlite_password_hash' => '',
];
