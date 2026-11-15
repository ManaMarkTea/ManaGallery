<?php

declare(strict_types=1);
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
umask(0077);
require is_file(__DIR__ . '/src/code-deploy.php') ? __DIR__ . '/src/code-deploy.php' : dirname(__DIR__) . '/src/code-deploy.php';
deploy_code_release($argv[1] ?? '', $argv[2] ?? '', $argv[3] ?? '', $argv[4] ?? null);
