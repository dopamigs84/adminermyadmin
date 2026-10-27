<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);


/**
 * Carica Adminer PRIMA
 */
//require __DIR__ . '/adminer.php';

/**
 * Override per MSSQL con SSL self-signed
 */
function adminer_object() {

    class AdminerMSSQL extends Adminer {

        function connectSsl() {
            return [
                "TrustServerCertificate" => true,
                "Encrypt" => true,
            ];
        }
    }

    return new AdminerMSSQL;
}

/**
 * Adminer va incluso SOLO ALLA FINE
 */
require __DIR__ . '/adminer.php';
