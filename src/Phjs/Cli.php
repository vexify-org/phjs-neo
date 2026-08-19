<?php

declare(strict_types=1);

namespace Phjs;

final class Cli
{
    public const VERSION = '1.0.0';

    private array $args;
    private ?string $rootDir = null;
    private string $projectDir;
    private bool $nobody = false;
    private int $timeout = 0;

    public function __construct(array $argv)
    {
        $this->args = array_slice($argv, 1);
        $this->projectDir = getcwd() ?: '/';
    }

    public function run(): int
    {
        while (($a = $this->args[0] ?? null) !== null && str_starts_with($a, '-') && $a !== '--') {
            $this->shift();
            switch ($a) {
                case '--root':
                    $this->rootDir = $this->shift();
                    break;
                case '--project':
                    $this->projectDir = rtrim((string) $this->shift(), '/');
                    break;
                case '--nobody':
                    $this->nobody = true;
                    break;
                case '--timeout':
                    $this->timeout = max(0, (int) $this->shift());
                    break;
                case '--help':
                case '-h':
                    return $this->help();
                case '--version':
                case '-V':
                    echo 'phjs ' . self::VERSION . "\n";
                    return 0;
                default:
                    Logger::error("unknown option: $a (see phjs --help)");
                    return 2;
            }
        }

        if (!extension_loaded('pcntl') || !extension_loaded('posix')) {
            Logger::error('phjs requires the pcntl and posix PHP extensions');
            return 1;
        }

        $cmd = $this->shift() ?? '';
        $sandbox = new Sandbox(
            Sandbox::resolveRoot($this->rootDir, $this->projectDir),
            $this->projectDir,
        );

        switch ($cmd) {
            case 'init':
                return $this->cmdInit($sandbox);
            case 'run':
                return $this->cmdRun($sandbox);
            case 'repl':
                return $this->cmdNode($sandbox, ['-i']);
            case 'node':
                return $this->cmdNode($sandbox, $this->args);
            case 'shell':
                return $this->cmdShell($sandbox);
            case 'npm':
                return $this->cmdNpm($sandbox, false);
            case 'npx':
                return $this->cmdNpm($sandbox, true);
            case 'exec':
                return $this->cmdExec($sandbox);
            case 'serve':
                return $this->cmdServe();
            case 'info':
                return $this->cmdInfo($sandbox);
            case 'uninstall':
                return $this->cmdUninstall($sandbox);
            case 'help':
                return $this->help();
            default:
                Logger::error("unknown command: $cmd");
                $this->help();
                return 2;
        }
    }

    // ------------------------------------------------------------------
    // commands
    // ------------------------------------------------------------------

    private function cmdInit(Sandbox $sb): int
    {
        $withGit = false;
        $withGcc = false;
        foreach ($this->args as $a) {
            switch ($a) {
                case '--with-git':
                    $withGit = true;
                    break;
                case '--with-gcc':
                    $withGcc = true;
                    break;
                default:
                    Logger::error("unknown init option: $a");
                    return 2;
            }
        }
        return $sb->init($withGit, $withGcc);
    }

    private function cmdRun(Sandbox $sb): int
    {
        $file = $this->shift() ?? '';
        if ($file === '') {
            Logger::error('usage: phjs run <file.js> [args...]');
            return 2;
        }
        $tmp = null;
        if ($file === '-') {
            $tmp = $this->projectDir . '/.__phjs_stdin__.js';
            if (file_put_contents($tmp, stream_get_contents(STDIN)) === false) {
                Logger::error('cannot read script from stdin');
                return 1;
            }
            $file = basename($tmp);
        }

        $abs = $file[0] === '/' ? $file : rtrim($this->projectDir, '/') . '/' . $file;
        if (!is_file($abs)) {
            Logger::error("script not found: $abs");
            return 1;
        }
        $rel = $this->relToProject($abs);
        if ($rel === null) {
            Logger::error('script must live inside the project directory');
            return 1;
        }
        $script = '/app/' . $rel;

        $cmd = array_merge(['/usr/bin/node', $script], $this->args);
        $code = $sb->run($cmd, self::nodeEnv(), ['nobody' => $this->nobody, 'timeout' => $this->timeout]);
        if ($tmp !== null) {
            @unlink($tmp);
        }
        return $code;
    }

    private function cmdNode(Sandbox $sb, array $args): int
    {
        $resolved = [];
        foreach ($args as $a) {
            $resolved[] = $this->resolveInSandbox($a);
        }
        return $sb->run(array_merge(['/usr/bin/node'], $resolved), self::nodeEnv(), [
            'nobody' => $this->nobody,
            'timeout' => $this->timeout,
        ]);
    }

    private function cmdShell(Sandbox $sb): int
    {
        $env = self::nodeEnv();
        $env['HISTFILE'] = '/home/sandbox/.bash_history';
        $env['PS1'] = "\\[\\033[1;36m\\]phjs:\\w\\$ \\[\\033[0m\\]";
        $env['PS2'] = '> ';
        if ($this->args === []) {
            $cmd = ['/bin/bash', '-i'];
        } else {
            $cmd = ['/bin/bash', '-c', implode(' ', $this->args)];
        }
        return $sb->run($cmd, $env, ['nobody' => $this->nobody, 'timeout' => $this->timeout]);
    }

    private function cmdNpm(Sandbox $sb, bool $npx): int
    {
        $cli = $npx
            ? '/usr/lib/node_modules/npm/bin/npx-cli.js'
            : '/usr/lib/node_modules/npm/bin/npm-cli.js';
        $cmd = array_merge(['/usr/bin/node', $cli], $this->args);
        return $sb->run($cmd, self::nodeEnv(), ['nobody' => $this->nobody, 'timeout' => $this->timeout]);
    }

    private function cmdExec(Sandbox $sb): int
    {
        $line = implode(' ', $this->args);
        if ($line === '') {
            Logger::error('usage: phjs exec <command...>');
            return 2;
        }
        return $sb->run(['/bin/bash', '-c', $line], self::nodeEnv(), [
            'nobody' => $this->nobody,
            'timeout' => $this->timeout,
        ]);
    }

    private function cmdInfo(Sandbox $sb): int
    {
        echo 'sandbox root : ' . $sb->root . "\n";
        echo 'project dir  : ' . $sb->projectDir . "\n";
        if (!$sb->exists()) {
            echo 'status       : NOT initialized (run: phjs init)' . "\n";
            return 0;
        }
        echo 'status       : ready' . "\n";
        echo 'node         : ' . trim((string) shell_exec('node --version 2>/dev/null') ?: 'unknown') . "\n";
        echo 'npm          : ' . trim((string) shell_exec('npm --version 2>/dev/null') ?: 'unknown') . "\n";
        echo 'isolated     : chroot + bind-mount (/app -> project)' . "\n";
        return 0;
    }

    private function cmdServe(): int
    {
        $port = null;
        $stdin = false;
        $qs = null;
        $idx = 0;
        $n = count($this->args);
        while ($idx < $n) {
            $a = $this->args[$idx];
            if ($a === '--port' && $idx + 1 < $n) {
                $port = (int) $this->args[$idx + 1];
                $idx += 2;
            } elseif (preg_match('/^--port=(\d+)$/', $a, $m) === 1) {
                $port = (int) $m[1];
                $idx++;
            } elseif ($a === '--stdin') {
                $stdin = true;
                $idx++;
            } elseif (str_starts_with($a, '-') && $a !== '-') {
                Logger::error("unknown serve option: $a");
                return 2;
            } elseif ($qs === null) {
                $qs = $a;
                $idx++;
            } else {
                Logger::error('unexpected extra argument: ' . $a);
                return 2;
            }
        }

        $stateDir = getenv('PHJS_STATE_DIR') ?: (Sandbox::globalHome() . '/users');
        $lockFile = getenv('PHJS_LOCK_FILE') ?: (Sandbox::globalHome() . '/serve.lock');
        $timeout = $this->timeout > 0 ? $this->timeout : 60;
        $server = new Server($stateDir, $lockFile, Server::loadUsers(null), $timeout, $this->rootDir ?? '');

        if ($stdin) {
            Logger::info("state dir: $stateDir");
            Logger::info('waiting for requests on stdin, one query string per line ...');
            while (($line = fgets(STDIN)) !== false) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $r = $server->handle(Server::parseQuery($line));
                echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
                fflush(STDOUT);
            }
            return 0;
        }

        if ($port !== null) {
            return $this->serveHttp($port, $stateDir, $lockFile);
        }

        if ($qs !== null) {
            $q = Server::parseQuery($qs);
            $r = $server->handle($q);
            if (($q['format'] ?? 'json') === 'text') {
                if (!$r['ok']) {
                    fwrite(STDERR, 'ERROR: ' . ($r['error'] ?? 'unknown') . "\n");
                    return 1;
                }
                echo $r['stdout'];
                if (($r['stderr'] ?? '') !== '') {
                    fwrite(STDERR, $r['stderr']);
                }
                return (int) $r['exit'];
            }
            echo json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
            return $r['ok'] ? (int) $r['exit'] : 1;
        }

        Logger::error('usage: phjs serve "user=..&password=..&nodecommand=.. [args]" [--port 8080|--stdin]');
        return 2;
    }

    private function serveHttp(int $port, string $stateDir, string $lockFile): int
    {
        $router = realpath(__DIR__ . '/http-router.php');
        if ($router === false) {
            Logger::error('http router file missing');
            return 1;
        }
        @mkdir($stateDir . '/.docroot', 0755, true);
        foreach ([
            'PHJS_STATE_DIR' => $stateDir,
            'PHJS_LOCK_FILE' => $lockFile,
            'PHJS_TIMEOUT' => (string) ($this->timeout > 0 ? $this->timeout : 60),
            'PHJS_ROOT' => (string) ($this->rootDir ?? ''),
            'PHJS_AUTH' => (string) (getenv('PHJS_AUTH') ?: ''),
        ] as $k => $v) {
            putenv($k . '=' . $v);
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            Logger::error('fork failed');
            return 1;
        }
        if ($pid === 0) {
            pcntl_exec(PHP_BINARY, ['-S', '0.0.0.0:' . $port, '-t', $stateDir . '/.docroot', $router]);
            fwrite(STDERR, "phjs: failed to start php -S\n");
            exit(127);
        }

        Logger::ok("listening on http://0.0.0.0:$port  (state: $stateDir, one request at a time)");
        Logger::info('press Ctrl+C to stop');

        pcntl_async_signals(true);
        $forward = function (int $sig) use ($pid): void {
            @posix_kill($pid, $sig);
        };
        foreach ([SIGINT, SIGTERM, SIGHUP, SIGQUIT] as $s) {
            pcntl_signal($s, $forward);
        }
        pcntl_waitpid($pid, $raw);
        pcntl_async_signals(false);
        if (pcntl_wifexited($raw)) {
            return pcntl_wexitstatus($raw);
        }
        return 1;
    }

    private function cmdUninstall(Sandbox $sb): int
    {
        if (!$sb->exists() && !is_dir($sb->root)) {
            Logger::error("nothing to uninstall at {$sb->root}");
            return 1;
        }
        if ($this->rootDir === null) {
            echo "About to permanently delete the sandbox rootfs:\n  {$sb->root}\n";
            fwrite(STDOUT, 'type "yes" to continue: ');
            if (trim((string) fgets(STDIN)) !== 'yes') {
                Logger::info('aborted');
                return 0;
            }
        }
        $sb->cleanupMounts();
        @exec('rm -rf ' . escapeshellarg($sb->root));
        if (is_dir($sb->root)) {
            Logger::error("failed to remove {$sb->root}");
            return 1;
        }
        Logger::ok("sandbox removed: {$sb->root}");
        return 0;
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private static function nodeEnv(): array
    {
        return [
            'HOME' => '/home/sandbox',
            'USER' => 'sandbox',
            'LOGNAME' => 'sandbox',
            'SHELL' => '/bin/bash',
            'PATH' => '/usr/local/bin:/usr/bin:/bin',
            'TMPDIR' => '/tmp',
            'TERM' => getenv('TERM') ?: 'xterm-256color',
            'NODE_PATH' => '/usr/local/lib/node_modules:/usr/lib/node_modules',
            'npm_config_cache' => '/home/sandbox/.npm',
            'npm_config_update_notifier' => 'false',
            'PHJS_SANDBOX' => '1',
        ];
    }

    public static function env(): array
    {
        return self::nodeEnv();
    }

    /** Maps a host path inside the project to /app/...; null when outside. */
    private function relToProject(string $abs): ?string
    {
        $proj = realpath($this->projectDir);
        $real = realpath($abs);
        if ($proj === false || $real === false) {
            return null;
        }
        if ($real === $proj) {
            return '.';
        }
        if (!str_starts_with($real, $proj . '/')) {
            return null;
        }
        return substr($real, strlen($proj) + 1);
    }

    /** node <arg>: resolve project-relative paths, keep flags/sandbox-paths raw. */
    private function resolveInSandbox(string $a): string
    {
        if (str_starts_with($a, '/')) {
            return $a;
        }
        $abs = rtrim($this->projectDir, '/') . '/' . $a;
        if (is_file($abs)) {
            $rel = $this->relToProject($abs);
            if ($rel !== null) {
                return '/app/' . $rel;
            }
        }
        return $a;
    }

    private function shift(): ?string
    {
        if ($this->args === []) {
            return null;
        }
        return array_shift($this->args);
    }

    private function help(): int
    {
        echo <<<HELP
phjs - JavaScript sandbox VM in PHP (chroot-isolated Node.js)

USAGE
  phjs [--root DIR] [--project DIR] [--nobody] [--timeout SEC] <command> [...]

COMMANDS
  init [--with-git] [--with-gcc]   Build the isolated rootfs (needs root)
  run <file.js> [args...]          Run a JS script inside the sandbox
  node <args...>                   Run node directly inside the sandbox
  repl                             Interactive node REPL inside the sandbox
  shell [command...]               Interactive shell (or run a command)
  npm <npm args...>                npm inside the sandbox (cwd = /app)
  npx <npx args...>                npx inside the sandbox
  exec <command...>                Run any command inside the sandbox
  serve "<querystring>"            Process one request (auth -> run -> save state)
  serve --stdin                    Read requests from stdin, one per line (loop)
  serve --port 8080                HTTP server: ?user=&password=&nodecommand=
  info                             Show sandbox status
  uninstall                        Remove the sandbox rootfs

OPTIONS
  --root DIR      Sandbox rootfs location (default: ~/.phjs/rootfs)
  --project DIR   Project dir bind-mounted at /app (default: cwd)
  --nobody        Drop privileges to nobody (uid 65534) inside the sandbox
  --timeout SEC   Kill the sandboxed process after SEC seconds

EXAMPLES
  phjs init
  phjs run hello.js a b c
  phjs npm install express
  phjs shell
  echo 'console.log(process.version)' | phjs repl

SERVE (requirement 2: php-only request handling, one user at a time)
  phjs serve "user=root&password=123456&nodecommand=hello.js --help"
  phjs serve --port 8080        # curl "http://host:8080/?user=root&password=123456&nodecommand=hello.js%20--help"
  echo 'user=root&password=123456&nodecommand=app.js' | phjs serve --stdin
  # after each request the user's app state is saved (state/<user>/app + state.json)
  # configure users: PHJS_AUTH="a=1,b=2" or ~/.phjs/users.conf

HELP;
        return 0;
    }
}
