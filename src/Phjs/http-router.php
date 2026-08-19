<?php

declare(strict_types=1);

// php -S router for `phjs serve --port N`. Pure PHP, no extra SAPI.
// All requests hit this script; the docroot is an empty dir (-t).

require __DIR__ . '/Logger.php';
require __DIR__ . '/Sandbox.php';
require __DIR__ . '/Cli.php';
require __DIR__ . '/Server.php';

use Phjs\Sandbox;
use Phjs\Server;

$q = $_GET;
foreach ($_POST as $k => $v) {
    $q[$k] = $v;
}

$format = ($q['format'] ?? 'json') === 'text' ? 'text' : 'json';

$home = Sandbox::globalHome();
$server = new Server(
    stateDir: getenv('PHJS_STATE_DIR') ?: ($home . '/users'),
    lockFile: getenv('PHJS_LOCK_FILE') ?: ($home . '/serve.lock'),
    users: Server::loadUsers(null),
    timeout: max(1, (int) (getenv('PHJS_TIMEOUT') ?: '60')),
    rootDir: (string) (getenv('PHJS_ROOT') ?: ''),
);

$result = $server->handle($q);

if ($format === 'text') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Phjs-Exit: ' . (int) ($result['exit'] ?? 1));
    http_response_code($result['ok'] ? 200 : ($result['error'] === 'unauthorized' ? 401 : (int) ($result['_http'] ?? 400)));
    if (!$result['ok']) {
        echo 'ERROR: ' . $result['error'] . "\n";
        return;
    }
    echo $result['stdout'];
    if ($result['stderr'] !== '') {
        echo "\n[stderr]\n" . $result['stderr'];
    }
    return;
}

header('Content-Type: application/json; charset=utf-8');
http_response_code($result['ok'] ? 200 : ($result['error'] === 'unauthorized' ? 401 : (int) ($result['_http'] ?? 400)));
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
