<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require dirname(__DIR__) . '/app/bootstrap.php';
$checks = [
    'PHP >= 8.2' => PHP_VERSION_ID >= 80200,
    'Sessioni' => extension_loaded('session'),
    'Mbstring' => extension_loaded('mbstring'),
    'OpenSSL' => extension_loaded('openssl'),
    'Driver Microsoft SQL Server (sqlsrv o pdo_sqlsrv)' => extension_loaded('sqlsrv') || extension_loaded('pdo_sqlsrv'),
];
foreach ($checks as $label => $ok) {
    echo ($ok ? '[OK] ' : '[--] '), $label, PHP_EOL;
}
echo 'PHP ', PHP_VERSION, ' (', PHP_SAPI, ')', PHP_EOL;
echo 'SQL Server Encrypt: ', $GLOBALS['myadmin_config']['sqlserver_encrypt'] ? 'true' : 'false', PHP_EOL;
echo 'SQL Server TrustServerCertificate: ', $GLOBALS['myadmin_config']['sqlserver_trust_certificate'] ? 'true' : 'false', PHP_EOL;
echo 'La configurazione PHP del web server può differire da quella CLI.', PHP_EOL;
exit(in_array(false, $checks, true) ? 1 : 0);
