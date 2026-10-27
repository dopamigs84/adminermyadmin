<?php
declare(strict_types=1);

$envBool = static function (string $name, bool $default): bool {
    $value = getenv($name);
    return $value === false ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
};

$GLOBALS['myadmin_config'] = [
    'name' => 'AdminerMyAdmin',
    'sqlserver_encrypt' => true,
    // Matches the original AdminerMSSQL customization. Configurable per installation.
    'sqlserver_trust_certificate' => $envBool('AM_SQLSERVER_TRUST_CERTIFICATE', true),
    'sqlite_password_hash' => getenv('AM_SQLITE_PASSWORD_HASH') ?: '',
];
$localConfig = dirname(__DIR__) . '/config.local.php';
if (is_file($localConfig)) {
    $overrides = require $localConfig;
    if (!is_array($overrides)) {
        throw new RuntimeException('config.local.php deve restituire un array.');
    }
    $GLOBALS['myadmin_config'] = array_replace($GLOBALS['myadmin_config'], $overrides);
}

// Keep the application Italian by default, honoring Adminer's language selector.
if (!isset($_COOKIE['adminer_lang'])) {
    $_COOKIE['adminer_lang'] = 'it';
}

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('session.use_strict_mode', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');
if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    ini_set('session.cookie_secure', '1');
}
