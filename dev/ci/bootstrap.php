<?php
/**
 * Magelan_Omnisend - unit test bootstrap (composer install in this folder, or the vendor folder of a
 * sibling Magelan module's harness).
 */
declare(strict_types=1);

error_reporting(E_ALL);
date_default_timezone_set('UTC');

$autoload = __DIR__ . '/vendor/autoload.php';
if (!is_file($autoload)) {
    $autoload = dirname(__DIR__, 3) . '/FreeGift/dev/ci/vendor/autoload.php';
}
$loader = require $autoload;

if (!function_exists('__')) {
    require dirname($autoload) . '/magento/framework/Phrase/__.php';
}

$loader->addPsr4('Magelan\\Omnisend\\', dirname(__DIR__, 2) . '/');