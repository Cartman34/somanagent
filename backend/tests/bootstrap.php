<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

if (($_SERVER['SOMANAGENT_PHPUNIT_LOCAL'] ?? $_ENV['SOMANAGENT_PHPUNIT_LOCAL'] ?? getenv('SOMANAGENT_PHPUNIT_LOCAL')) === '1') {
    foreach (['http', 'https'] as $scheme) {
        if (in_array($scheme, stream_get_wrappers(), true)) {
            stream_wrapper_unregister($scheme);
        }
    }
}

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}
