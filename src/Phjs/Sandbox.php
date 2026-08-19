<?php

declare(strict_types=1);

namespace Phjs;

/**
 * phjs sandbox: an isolated Linux root filesystem (chroot) that carries a
 * full Node.js installation, npm/npx, coreutils and the project directory
 * bind-mounted at /app.
 */
final class Sandbox
{
    public const ROOTFS_NAME = '.phjs-rootfs';
    public const NOBODY_UID = 65534;
    public const NOBODY_GID = 65534;

    private const TOOLS = [
        'bash', 'sh', 'env', 'ls', 'mkdir', 'rm', 'cp', 'mv', 'cat', 'touch',
        'dirname', 'basename', 'readlink', 'grep', 'sed', 'sort', 'uname',
        'head', 'tail', 'find', 'xargs', 'tar', 'which', 'expr', 'id', 'ln',
        'pwd', 'sleep', 'cut', 'wc', 'od', 'tr', 'date', 'test', 'uniq', 'du',
        'stat', 'realpath', 'hostname', 'getconf', 'tee', 'diff', 'patch',
        'ps', 'kill', 'chmod', 'chown', 'echo', 'printf', 'true', 'false',
        'seq', 'base64', 'md5sum', 'sha256sum', 'cksum', 'df', 'mount',
    ];

    /** @var string[] mount points created by this process (host paths) */
    private array $mounted = [];

    public function __construct(
        public readonly string $root,
        public readonly string $projectDir,
    ) {
    }

    // ------------------------------------------------------------------
    // resolution helpers
    // ------------------------------------------------------------------

    public static function globalHome(): string
    {
        $env = getenv('PHJS_HOME');
        if ($env !== false && $env !== '') {
            return rtrim($env, '/');
        }
        $home = getenv('HOME');
        if ($home === false || $home === '') {
            throw new \RuntimeException('cannot determine home directory (set HOME or PHJS_HOME)');
        }
        return rtrim($home, '/') . '/.phjs';
    }

    /**
     * Rootfs location: explicit --root wins, then $projectDir/.phjs-rootfs
     * if already initialized, else the shared global rootfs.
     */
    public static function resolveRoot(?string $explicit, string $projectDir): string
    {
        if ($explicit !== null && $explicit !== '') {
            return rtrim($explicit, '/');
        }
        $local = rtrim($projectDir, '/') . '/' . self::ROOTFS_NAME;
        if (is_dir($local) && is_file($local . '/usr/bin/node')) {
            return $local;
        }
        return self::globalHome() . '/rootfs';
    }

    public static function isRoot(): bool
    {
        return function_exists('posix_geteuid') && posix_geteuid() === 0;
    }

    public function exists(): bool
    {
        return is_dir($this->root) && is_file($this->root . '/usr/bin/node');
    }

    // ------------------------------------------------------------------
    // rootfs construction
    // ------------------------------------------------------------------

    public function init(bool $withGit = false, bool $withGcc = false): int
    {
        if ($this->exists()) {
            Logger::warn("sandbox already initialized at {$this->root}");
            return 0;
        }
        if (!self::isRoot()) {
            Logger::error('phjs init requires root privileges (needs chroot + mount). Run with sudo.');
            return 1;
        }
        $start = microtime(true);
        Logger::info("building sandbox rootfs at {$this->root} ...");

        foreach ([
            'bin', 'sbin', 'usr/bin', 'usr/local/bin', 'usr/local/lib',
            'usr/lib/node_modules', 'usr/share', 'etc', 'dev', 'proc',
            'tmp', 'var/tmp', 'var/cache', 'home/sandbox', 'root',
            'lib', 'lib64', 'usr/lib', 'app', 'run',
        ] as $d) {
            @mkdir($this->root . '/' . $d, 0755, true);
        }
        @chmod($this->root . '/tmp', 01777);
        @chmod($this->root . '/var/tmp', 01777);

        $this->copyNode();
        $this->copyNpm();
        $this->copyTools();
        $this->copyDevNodes();
        $this->copyEtc();
        if ($withGit) {
            $this->copyGit();
        }
        if ($withGcc) {
            $this->copyGcc();
        }
        $this->setupAppDir();

        Logger::ok(sprintf('sandbox ready at %s (%.1fs)', $this->root, microtime(true) - $start));
        return 0;
    }

    private function copyNode(): void
    {
        $src = $this->hostWhich('node');
        if ($src === null) {
            Logger::error('node binary not found on host');
            exit(1);
        }
        $this->copyBinary($src, '/usr/bin/node');
        @symlink('/usr/bin/node', $this->root . '/bin/node');
        @symlink('/usr/bin/node', $this->root . '/usr/local/bin/node');
        @symlink('/usr/lib/node_modules', $this->root . '/usr/local/lib/node_modules');
    }

    private function copyNpm(): void
    {
        // locate the real npm package via the npm bin symlink
        $npmDir = null;
        if (is_link('/usr/bin/npm')) {
            $resolved = realpath('/usr/bin/npm');
            if ($resolved !== false && str_ends_with($resolved, '/npm-cli.js')) {
                $npmDir = dirname(dirname($resolved));
            }
        }
        if ($npmDir === null || !is_dir($npmDir)) {
            $prefix = trim((string) shell_exec('npm config get prefix 2>/dev/null')) ?: '/usr/local';
            $npmDir = $prefix . '/lib/node_modules/npm';
        }
        if (!is_dir($npmDir)) {
            Logger::warn("npm package not found at $npmDir; npm/npx will be unavailable");
            return;
        }
        @mkdir($this->root . '/usr/lib/node_modules', 0755, true);
        $this->copyTree($npmDir, $this->root . '/usr/lib/node_modules/npm');

        $launcher = '#!/usr/bin/env bash' . "\n"
            . 'exec /usr/bin/node /usr/lib/node_modules/npm/bin/npm-cli.js "$@"' . "\n";
        file_put_contents($this->root . '/usr/bin/npm', $launcher, LOCK_EX);
        @chmod($this->root . '/usr/bin/npm', 0755);
        file_put_contents($this->root . '/usr/bin/npx', str_replace('npm-cli.js', 'npx-cli.js', $launcher), LOCK_EX);
        @chmod($this->root . '/usr/bin/npx', 0755);
    }

    private function copyTools(): void
    {
        foreach (self::TOOLS as $t) {
            if ($t === 'sh') {
                continue;
            }
            $src = $this->hostWhich($t);
            if ($src === null) {
                Logger::warn("tool not found on host: $t");
                continue;
            }
            $this->copyTool($src);
        }
        // sh -> bash
        @symlink('/usr/bin/bash', $this->root . '/bin/sh');
        @symlink('/usr/bin/bash', $this->root . '/usr/bin/sh');
        @symlink('/usr/bin/bash', $this->root . '/bin/bash');
    }

    private function copyTool(string $src): void
    {
        if (is_link($src)) {
            $target = readlink($src);
            if ($target !== false && $target[0] !== '/') {
                $target = dirname($src) . '/' . $target;
            }
            if ($target !== false && is_executable($target)) {
                $this->copyTool($target);
                @symlink('/' . ltrim(str_replace($this->root, '', $target), '/'), $this->root . $src);
                return;
            }
        }
        $this->copyBinary($src, $src);
    }

    private function copyBinary(string $src, string $dstAbs): void
    {
        $dst = $this->root . $dstAbs;
        @mkdir(dirname($dst), 0755, true);
        if (!@link($src, $dst)) {
            if (!@copy($src, $dst)) {
                Logger::warn("cannot copy $src -> $dst");
                return;
            }
            @chmod($dst, 0755);
        }
        foreach ($this->lddOf($src) as $lib) {
            $this->copyLib($lib);
        }
    }

    private function lddOf(string $bin): array
    {
        $out = [];
        @exec('ldd ' . escapeshellarg($bin) . ' 2>/dev/null', $out);
        $libs = [];
        foreach ($out as $raw) {
            $line = trim($raw);
            if ($line === '' || str_starts_with($line, 'linux-vdso')) {
                continue;
            }
            if (preg_match('~(?:=>\s+)?(/\S+)~', $line, $m)) {
                $libs[] = $m[1];
            }
        }
        return $libs;
    }

    private function copyLib(string $lib): void
    {
        if (!is_file($lib) && !is_link($lib)) {
            return;
        }
        $dst = $this->root . $lib;
        if (is_file($dst)) {
            return;
        }
        $real = realpath($lib) ?: $lib;
        if (!is_file($real)) {
            return;
        }
        @mkdir(dirname($dst), 0755, true);
        if (!@link($real, $dst) && !is_file($dst)) {
            @copy($real, $dst);
        }
    }

    private function copyDevNodes(): void
    {
        foreach (['null', 'zero', 'full', 'random', 'urandom', 'tty', 'ptmx'] as $dev) {
            if (file_exists('/dev/' . $dev)) {
                @exec('cp -a /dev/' . $dev . ' ' . escapeshellarg($this->root . '/dev/' . $dev) . ' 2>/dev/null');
            }
        }
    }

    private function copyEtc(): void
    {
        foreach (['passwd', 'group', 'hosts', 'nsswitch.conf', 'resolv.conf', 'mtab', 'os-release'] as $f) {
            if (is_file('/etc/' . $f)) {
                @copy('/etc/' . $f, $this->root . '/etc/' . $f);
            }
        }
        if (is_link('/etc/localtime')) {
            $real = realpath('/etc/localtime');
            if ($real !== false && is_file($real)) {
                @copy($real, $this->root . '/etc/localtime');
            }
        }
        if (is_dir('/etc/ssl')) {
            @exec('cp -a /etc/ssl ' . escapeshellarg($this->root . '/etc/ssl') . ' 2>/dev/null');
        }
        file_put_contents($this->root . '/etc/shells', "/bin/sh\n/bin/bash\n");
    }

    private function copyGit(): void
    {
        $src = $this->hostWhich('git');
        if ($src === null) {
            Logger::warn('git not found on host, skipping');
            return;
        }
        $this->copyTool($src);
        foreach (['git-receive-pack', 'git-upload-pack', 'git-upload-archive'] as $helper) {
            $h = $this->hostWhich($helper);
            if ($h !== null) {
                $this->copyTool($h);
            }
        }
    }

    private function copyGcc(): void
    {
        foreach (['gcc', 'g++', 'cc', 'c++', 'cpp', 'make', 'ar', 'as', 'ld',
            'ld.bfd', 'strip', 'ranlib', 'nm', 'objcopy', 'objdump', 'readelf',
            'python3', 'patch', 'cmp', 'install', 'perl'] as $t) {
            $src = $this->hostWhich($t);
            if ($src !== null) {
                $this->copyTool($src);
            }
        }
        foreach (glob('/usr/lib/gcc/*') ?: [] as $dir) {
            @exec('cp -a ' . escapeshellarg($dir) . ' ' . escapeshellarg($this->root . '/usr/lib/gcc/') . ' 2>/dev/null');
        }
        $this->copyTree('/usr/include', $this->root . '/usr/include');
        $multi = '/usr/lib/x86_64-linux-gnu';
        foreach (['crt1.o', 'crti.o', 'crtn.o', 'crtbegin.o', 'crtbeginS.o',
            'crtbeginT.o', 'crtend.o', 'crtendS.o', 'libc.a', 'libm.a',
            'libpthread.a', 'libdl.a', 'libstdc++.a', 'libgcc.a'] as $f) {
            $p = $multi . '/' . $f;
            if (is_file($p)) {
                $this->copyLib($p);
            }
        }
        Logger::info('gcc toolchain copied (experimental: header/static libs)');
    }

    private function copyTree(string $src, string $dst): void
    {
        if (!is_dir($src)) {
            return;
        }
        $realSrc = realpath($src) ?: $src;
        $realDst = realpath($this->root);
        if ($realDst !== false && str_starts_with($realSrc, $realDst . '/')) {
            return; // avoid copying the sandbox into itself
        }
        @mkdir($dst, 0755, true);
        @exec('cp -a ' . escapeshellarg($src . '/.') . ' ' . escapeshellarg($dst . '/') . ' 2>/dev/null');
    }

    private function setupAppDir(): void
    {
        $app = $this->root . '/app';
        @mkdir($app, 0755, true);
        $tpl = __DIR__ . '/../../templates/package.json';
        if (!is_file($app . '/package.json') && is_file($tpl)) {
            @copy($tpl, $app . '/package.json');
        }
        file_put_contents($app . '/.npmrc', "cache=/home/sandbox/.npm\n", LOCK_EX);
        if (!is_file($app . '/index.js')) {
            file_put_contents(
                $app . '/index.js',
                "console.log('hello from phjs sandbox (node ' + process.version + ')');\n"
                . "console.log('cwd:', process.cwd());\n"
            );
        }
    }

    // ------------------------------------------------------------------
    // mounts
    // ------------------------------------------------------------------

    private function isMountpoint(string $path): bool
    {
        $out = [];
        @exec('findmnt -n --mountpoint ' . escapeshellarg($path) . ' 2>/dev/null', $out);
        return trim(implode("\n", $out)) !== '';
    }

    private function mountProc(): void
    {
        $p = $this->root . '/proc';
        @mkdir($p, 0755, true);
        if ($this->isMountpoint($p)) {
            return;
        }
        $out = [];
        @exec('mount -t proc proc ' . escapeshellarg($p) . ' 2>/dev/null', $out, $rc);
        if ($rc === 0) {
            $this->mounted[] = $p;
            return;
        }
        if (!$this->isMountpoint($p)) {
            Logger::warn('could not mount /proc inside sandbox (some tools may misbehave)');
        }
    }

    private function mountApp(): void
    {
        $app = $this->root . '/app';
        @mkdir($app, 0755, true);
        $realProj = realpath($this->projectDir);
        $realRoot = realpath($this->root);
        if ($realProj === false || $realRoot === false) {
            Logger::error('cannot resolve project or sandbox path');
            exit(1);
        }
        if (str_starts_with($realProj, $realRoot . '/') || $realProj === $realRoot) {
            return; // project already lives inside the sandbox rootfs
        }
        if ($this->isMountpoint($app)) {
            return;
        }
        $out = [];
        @exec('mount --bind ' . escapeshellarg($realProj) . ' ' . escapeshellarg($app) . ' 2>/dev/null', $out, $rc);
        if ($rc === 0) {
            $this->mounted[] = $app;
            return;
        }
        $this->fallbackCopyProject($app);
    }

    private function fallbackCopyProject(string $app): void
    {
        $entries = @scandir($app);
        $nonEmpty = false;
        if (is_array($entries)) {
            foreach ($entries as $e) {
                if ($e !== '.' && $e !== '..' && $e !== '.npmrc' && $e !== 'index.js') {
                    $nonEmpty = true;
                    break;
                }
            }
        }
        if (!$nonEmpty) {
            @exec('cp -a ' . escapeshellarg($this->projectDir . '/.') . ' ' . escapeshellarg($app . '/') . ' 2>/dev/null');
            Logger::warn('bind mount unavailable; project was copied into the sandbox (changes are not synced back)');
        } else {
            Logger::warn('bind mount unavailable and /app not empty; running against existing /app content');
        }
    }

    public function cleanupMounts(): void
    {
        foreach (array_reverse($this->mounted) as $p) {
            $out = [];
            @exec('umount ' . escapeshellarg($p) . ' 2>/dev/null', $out, $rc);
            if ($rc !== 0) {
                @exec('umount -l ' . escapeshellarg($p) . ' 2>/dev/null');
            }
        }
        $this->mounted = [];
    }

    // ------------------------------------------------------------------
    // execution
    // ------------------------------------------------------------------

    private function childSetup(array $env, bool $dropPrivs): void
    {
        if (!@chroot($this->root)) {
            fwrite(STDERR, "phjs: chroot({$this->root}) failed\n");
            exit(1);
        }
        if (!@chdir('/app')) {
            fwrite(STDERR, "phjs: chdir(/app) failed\n");
            exit(1);
        }
        if ($dropPrivs) {
            @chown('/tmp', self::NOBODY_UID);
            @chown('/var/tmp', self::NOBODY_UID);
            @chown('/home/sandbox', self::NOBODY_UID);
            if (!@posix_setgid(self::NOBODY_GID)) {
                fwrite(STDERR, "phjs: setgid(nobody) failed\n");
                exit(1);
            }
            if (!@posix_setuid(self::NOBODY_UID)) {
                fwrite(STDERR, "phjs: setuid(nobody) failed\n");
                exit(1);
            }
        }
        foreach ($env as $k => $v) {
            if ($v === null) {
                @putenv($k);
            } else {
                putenv($k . '=' . $v);
            }
        }
    }

    public function run(array $cmd, array $env, array $opts = []): int
    {
        if (!$this->exists()) {
            Logger::error("sandbox not initialized at {$this->root} (run: phjs init)");
            return 1;
        }
        if ($cmd === [] || $cmd[0] === '') {
            Logger::error('empty command');
            return 1;
        }
        if (!self::isRoot()) {
            Logger::warn('not running as root: chroot will most likely fail');
        }

        // mounts are per-namespace: track them in this (parent) process so
        // cleanupMounts() can always undo them after the child exits
        $this->mountProc();
        $this->mountApp();

        $pid = pcntl_fork();
        if ($pid === -1) {
            Logger::error('fork failed');
            return 1;
        }

        if ($pid === 0) {
            $this->childSetup($env, (bool) ($opts['nobody'] ?? false));
            if (($opts['timeout'] ?? 0) > 0) {
                pcntl_alarm((int) $opts['timeout']);
            }
            pcntl_exec($cmd[0], array_slice($cmd, 1));
            $err = error_get_last();
            fwrite(STDERR, 'phjs: exec failed: ' . ($err['message'] ?? 'unknown error') . "\n");
            exit(127);
        }

        $status = $this->waitChild($pid);
        $this->cleanupMounts();
        return $status;
    }

    private function waitChild(int $pid): int
    {
        pcntl_async_signals(true);
        $forward = function (int $sig) use ($pid): void {
            @posix_kill($pid, $sig);
        };
        $signals = [SIGINT, SIGTERM, SIGHUP, SIGQUIT, SIGUSR1, SIGUSR2, SIGWINCH, SIGTSTP];
        foreach ($signals as $sig) {
            pcntl_signal($sig, $forward);
        }

        $status = 1;
        do {
            $r = pcntl_waitpid($pid, $raw, WUNTRACED);
            if ($r <= 0) {
                break;
            }
            if (pcntl_wifexited($raw)) {
                $status = pcntl_wexitstatus($raw);
                break;
            }
            if (pcntl_wifsignaled($raw)) {
                $status = 128 + pcntl_wtermsig($raw);
                break;
            }
        } while (true);

        foreach ($signals as $sig) {
            pcntl_signal($sig, SIG_DFL);
        }
        pcntl_async_signals(false);
        return $status;
    }

    // ------------------------------------------------------------------
    // misc
    // ------------------------------------------------------------------

    private function hostWhich(string $bin): ?string
    {
        if (str_contains($bin, '/')) {
            return is_executable($bin) ? $bin : null;
        }
        $paths = explode(':', getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin');
        foreach ($paths as $p) {
            if ($p !== '' && is_executable($p . '/' . $bin)) {
                return $p . '/' . $bin;
            }
        }
        return null;
    }
}
