<?php

declare(strict_types=1);

// phjs 前台请求入口：不常驻、不后台。请求到来时在本次进程内
// 完成：认证 -> 沙箱执行 -> 保存状态 -> 返回结果，随即结束。
//
// 用法1（Web/php-fpm/nginx 等）: 直接作为入口文件，接收 GET/POST
//    请求: /api.php?user=root&password=123456&nodecommand=hello.js%20--help
// 用法2（CLI 前台，一次性）:
//    php /data/workspace/phjs/api.php "user=root&password=123456&nodecommand=hello.js --help"
//    或从任何程序（ai.py/调度）里同步调用:
//    subprocess.run(["php", "/data/workspace/phjs/api.php", qs])
// 用法3（用 php -S 临时对外提供）:
//    php -S 0.0.0.0:8090 /data/workspace/phjs/api.php

require __DIR__ . '/src/Phjs/Logger.php';
require __DIR__ . '/src/Phjs/Sandbox.php';
require __DIR__ . '/src/Phjs/Cli.php';
require __DIR__ . '/src/Phjs/Server.php';

use Phjs\Sandbox;
use Phjs\Server;

// 收集请求参数：Web 下 $_GET+$_POST；CLI 下取 argv[1] 逐个处理
if (PHP_SAPI === 'cli') {
    $requests = isset($argv[1]) ? [$argv[1]] : [];
    while (($line = fgets(STDIN)) !== false) {
        $line = trim($line);
        if ($line !== '') {
            $requests[] = $line;
        }
    }
} else {
    $req = $_GET;
    foreach ($_POST as $k => $v) {
        $req[$k] = $v;
    }
    $requests = [$req];
}

$home = Sandbox::globalHome();
$server = new Server(
    stateDir: getenv('PHJS_STATE_DIR') ?: ($home . '/users'),
    lockFile: getenv('PHJS_LOCK_FILE') ?: ($home . '/serve.lock'),
    users: Server::loadUsers(null),
    timeout: max(1, (int) (getenv('PHJS_TIMEOUT') ?: '60')),
    rootDir: (string) (getenv('PHJS_ROOT') ?: ''),
);

foreach ($requests as $r) {
    $query = is_string($r) ? Server::parseQuery($r) : $r;
    $result = $server->handle($query);
    $result['_http'] = $result['ok'] ? 200 : ($result['error'] === 'unauthorized' ? 401 : (int) ($result['_http'] ?? 400));

    if (PHP_SAPI === 'cli') {
        if (($query['format'] ?? 'json') === 'text') {
            if (!$result['ok']) {
                fwrite(STDERR, 'ERROR: ' . ($result['error'] ?? 'unknown') . "\n");
                exit(1);
            }
            echo $result['stdout'];
            if (($result['stderr'] ?? '') !== '') {
                fwrite(STDERR, $result['stderr']);
            }
            exit((int) $result['exit']);
        }
        echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        exit($result['ok'] ? (int) $result['exit'] : 1);
    }

    header('Content-Type: application/json; charset=utf-8');
    http_response_code($result['_http']);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}