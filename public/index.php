<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

function adminer_object()
{
    // Adminer calls this factory after defining its namespaced base class.
    require dirname(__DIR__) . '/app/MyAdmin.php';
    return new \Adminer\MyAdmin($GLOBALS['myadmin_config']);
}

require dirname(__DIR__) . '/vendor/adminer.php';
