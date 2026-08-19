<?php

declare(strict_types=1);

namespace Phjs;

/**
 * Serialized request processor: authenticate -> run one node command in the
 * sandbox -> persist the user's state -> release the lock for the next user.
 * Pure PHP stdlib, no extensions beyond pcntl/posix (same as phjs itself).
 */
final class Server
{
    private const DEFAULT_USERS = ['root' => '123456'];

    public function __construct(
        private readonly string $stateDir,
        private readonly string $lockFile,
        private readonly array $users,
        private readonly int $timeout,
        private readonly string $rootDir,
    ) {
    }

    public static function parseQuery(string $qs): array
    {
        $q = [];
        parse_str($qs, $q);
        return $q;
    }

    public static function loadUsers(?string $authFlag): array
    {
        $src = ($authFlag !== null && $authFlag !== '') ? $authFlag : (getenv('PHJS_AUTH') ?: '');
        if ($src !== '') {
            return self::parseUsers($src);
        }
        $conf = Sandbox::globalHome() . '/users.conf';
        if (is_file($conf)) {
            $src = trim((string) file_get_contents($conf));
            if ($src !== '') {
                return self::parseUsers($src);
            }
        }
        Logger::warn('no users configured; using default root/123456 (set PHJS_AUTH or ' . Sandbox::globalHome() . '/users.conf)');
        return self::DEFAULT_USERS;
    }

    private static function parseUsers(string $src): array
    {
        $users = [];
        foreach (explode(',', $src) as $pair) {
            $pair = trim($pair);
            if ($pair === '') {
                continue;
            }
            $pos = strpos($pair, '=');
            if ($pos === false) {
                continue;
            }
            $users[trim(substr($pair, 0, $pos))] = substr($pair, $pos + 1);
        }
        return $users;
    }

    /**
     * Process one request. Assumes serialized use: callers must NOT run this
     * concurrently in the same process; the flock inside guarantees
     * cross-process serialization with other phjs servers.
     *
     * @param array<string,string> $req
     * @return array<string,mixed>
     */
    public function handle(array $req): array
    {
        $user = (string) ($req['user'] ?? '');
        $pass = (string) ($req['password'] ?? '');
        $nodecommand = trim((string) ($req['nodecommand'] ?? ''));
        $code = (string) ($req['code'] ?? '');

        $fail = fn (string $err, int $httpStatus): array => [
            'ok' => false,
            'user' => $user,
            'error' => $err,
            '_http' => $httpStatus,
        ];

        if (!$this->authenticate($user, $pass)) {
            return $fail('unauthorized', 401);
        }
        if ($nodecommand === '' && $code === '') {
            return $fail('missing nodecommand or code parameter', 400);
        }

        $safe = $this->sanitizeUser($user);
        $userDir = $this->stateDir . '/' . $safe;
        $appDir = $userDir . '/app';
        if (!@mkdir($appDir, 0755, true) && !is_dir($appDir)) {
            return $fail('cannot create state dir: ' . $userDir, 500);
        }
        if (!is_writable($appDir)) {
            return $fail('state dir not writable: ' . $appDir, 500);
        }

        $lock = @fopen($this->lockFile, 'c');
        if ($lock === false) {
            return $fail('cannot open lock file: ' . $this->lockFile, 500);
        }
        $t0 = microtime(true);
        if (!flock($lock, LOCK_EX)) {
            fclose($lock);
            return $fail('cannot acquire lock', 500);
        }
        $waitedMs = (int) round((microtime(true) - $t0) * 1000);

        try {
            $result = $this->execute($userDir, $appDir, $nodecommand, $code, $user);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        $result['waitedMs'] = $waitedMs;
        return $result;
    }

    /** @return array<string,mixed> */
    private function execute(string $userDir, string $appDir, string $nodecommand, string $code, string $user): array
    {
        $start = microtime(true);

        if ($code !== '') {
            $scriptRel = '__code__.js';
            @file_put_contents($appDir . '/' . $scriptRel, $code);
            $args = [];
            $display = '(inline code)';
        } else {
            [$scriptRel, $args] = self::splitCommand($nodecommand);
            $display = $nodecommand;
        }

        if (!is_file($appDir . '/' . $scriptRel)) {
            return [
                'ok' => false,
                'user' => $user,
                'error' => 'script not found in state dir: ' . $scriptRel,
                'state' => $appDir,
            ];
        }

        $root = $this->rootDir !== '' ? $this->rootDir : Sandbox::resolveRoot(null, $appDir);
        $outTmp = '__phjs_out__.tmp';
        $errTmp = '__phjs_err__.tmp';

        $quoted = [];
        foreach ($args as $a) {
            $quoted[] = escapeshellarg($a);
        }
        $bashCmd = 'exec >' . escapeshellarg('/app/' . $outTmp)
            . ' 2>' . escapeshellarg('/app/' . $errTmp)
            . '; exec /usr/bin/node ' . escapeshellarg('/app/' . $scriptRel)
            . ($quoted !== [] ? ' ' . implode(' ', $quoted) : '');

        $sandbox = new Sandbox($root, $appDir);
        $exit = $sandbox->run(['/bin/bash', '-c', $bashCmd], Cli::env(), ['timeout' => $this->timeout]);

        $stdout = is_file($appDir . '/' . $outTmp) ? (string) file_get_contents($appDir . '/' . $outTmp) : '';
        $stderr = is_file($appDir . '/' . $errTmp) ? (string) file_get_contents($appDir . '/' . $errTmp) : '';
        @unlink($appDir . '/' . $outTmp);
        @unlink($appDir . '/' . $errTmp);

        $durMs = (int) round((microtime(true) - $start) * 1000);
        $state = [
            'command' => $display,
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'ts' => date('c'),
            'durationMs' => $durMs,
        ];
        @file_put_contents($userDir . '/state.json', json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        @file_put_contents($userDir . '/history.log', json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);

        return [
            'ok' => $exit === 0,
            'user' => $user,
            'command' => $display,
            'script' => $scriptRel,
            'exit' => $exit,
            'stdout' => $stdout,
            'stderr' => $stderr,
            'durationMs' => $durMs,
            'state' => $userDir,
            'ts' => $state['ts'],
        ];
    }

    private function authenticate(string $user, string $pass): bool
    {
        $expected = $this->users[$user] ?? null;
        return $expected !== null && hash_equals((string) $expected, $pass);
    }

    private function sanitizeUser(string $user): string
    {
        $u = (string) (preg_replace('/[^A-Za-z0-9._-]/', '_', $user) ?? 'user');
        return $u !== '' ? $u : 'user';
    }

    /** Split "script.js arg1 'arg 2'" -> [script, [args]] (quote aware). */
    private static function splitCommand(string $s): array
    {
        $parts = [];
        $buf = '';
        $quote = null;
        $len = strlen($s);
        for ($i = 0; $i < $len; $i++) {
            $c = $s[$i];
            if ($quote !== null) {
                if ($c === $quote) {
                    $quote = null;
                } else {
                    $buf .= $c;
                }
                continue;
            }
            if ($c === '"' || $c === "'") {
                $quote = $c;
                continue;
            }
            if ($c === ' ' || $c === "\t") {
                if ($buf !== '') {
                    $parts[] = $buf;
                    $buf = '';
                }
                continue;
            }
            $buf .= $c;
        }
        if ($buf !== '') {
            $parts[] = $buf;
        }
        if ($parts === []) {
            return ['', []];
        }
        return [$parts[0], array_slice($parts, 1)];
    }
}
