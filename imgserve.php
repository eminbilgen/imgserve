<?php
declare(strict_types=1);

/**
 * Entry point. Copy this file and the src/ folder to your server, copy config.example.php to config.php,
 * then request:  imgserve.php?src=photos/cat.jpg&w=800&h=600&fit=cover&fmt=auto
 */

require __DIR__ . '/src/Imgserve.php';

use Imgserve\Config;
use Imgserve\Imgserve;

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("imgserve is not configured. Copy config.example.php to config.php.\n");
}
$config = Config::fromArray(require $configFile);

// CLI: php imgserve.php prune 30   (delete cached files older than 30 days)
if (PHP_SAPI === 'cli') {
    if (($argv[1] ?? '') === 'prune') {
        $n = Imgserve::prune($config, max(1, (int) ($argv[2] ?? 30)));
        fwrite(STDOUT, "Removed $n cached file(s).\n");
        exit(0);
    }
    fwrite(STDERR, "Usage: php imgserve.php prune [days]\n");
    exit(1);
}

if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
    http_response_code(405);
    header('Allow: GET, HEAD');
    exit;
}

Imgserve::send(Imgserve::handle($_GET, $_SERVER, $config));
