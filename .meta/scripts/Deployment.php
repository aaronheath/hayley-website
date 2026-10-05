<?php

namespace HayleyWebsite\Deployment;

use Closure;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

final class Deployment
{
    private bool $interrupted = false;

    /**
     * @param  array<string, mixed>  $config
     * @param  array<string, string>  $templates
     */
    public function __construct(
        private array $config,
        private array $templates,
        private ?Closure $runner = null,
    ) {}

    public function run(string $action, string $branch = 'master', ?string $release = null): void
    {
        $this->validate();

        if (! in_array($action, ['setup', 'check', 'deploy', 'releases', 'rollback'], true)) {
            throw new RuntimeException('Unknown deployment action.');
        }

        $this->assertDeployUser();

        $base = $this->config['baseDir'];

        if (! is_dir($base) && $action === 'setup') {
            $this->sudo([
                '/usr/bin/install',
                '-d',
                '-o',
                $this->config['user'],
                '-g',
                $this->config['user'],
                '-m',
                '0750',
                $base,
            ]);
        }

        $this->directory($base);

        $lock = fopen($base.'/.deploy.lock', 'c');

        if ($lock === false) {
            throw new RuntimeException('Could not open the app deployment lock; check base directory permissions.');
        }

        if (! flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);

            throw new RuntimeException('Another deployment operation holds the app lock.');
        }

        $handlers = [];
        $asynchronousSignals = null;

        if (function_exists('pcntl_async_signals')) {
            $asynchronousSignals = pcntl_async_signals(true);

            foreach ([SIGHUP, SIGINT, SIGTERM] as $signal) {
                $handlers[$signal] = pcntl_signal_get_handler($signal);

                pcntl_signal($signal, function (): void {
                    $this->interrupted = true;
                });
            }
        }

        try {
            $this->directory($base.'/.deployment');

            $journal = $base.'/.deployment/transaction.json';

            if (is_file($journal)) {
                if ($action !== 'rollback' || $release !== null) {
                    throw new RuntimeException('An interrupted deployment needs recovery. Run rollback without --release first.');
                }

                $this->restore($this->readJson($journal));

                unlink($journal);

                return;
            }

            if ($action === 'releases') {
                $this->listReleases();

                return;
            }

            if ($action === 'rollback') {
                $target = $this->managedRelease($release ?? basename($this->linkTarget('previous') ?? ''));

                $this->useRuntime($this->readJson($target.'/.deployment/runtime.json'));

                $this->validate();

                $this->setupApplication(poolOnly: true);

                $this->preflight(false);

                $this->activate($target, false);

                return;
            }

            if ($action === 'setup') {
                $this->setupApplication();

                $this->setupDirectories();

                echo "App resources ready. Register the deploy key, install the origin certificate and review .env, then run check and deploy.\n";

                return;
            }

            if ($action === 'deploy') {
                $this->setupApplication(poolOnly: true);
            }

            $this->preflight(true);

            if ($action === 'check') {
                echo "Preflight passed.\n";

                return;
            }

            $this->setupDirectories();

            $target = $this->prepare($branch);

            $this->activate($target, true);

            $this->cleanup();
        } finally {
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }

            if ($asynchronousSignals !== null) {
                pcntl_async_signals($asynchronousSignals);
            }

            flock($lock, LOCK_UN);

            fclose($lock);
        }
    }

    private function validate(): void
    {
        foreach (['app', 'user', 'runtimeUser', 'webUser'] as $key) {
            if (! preg_match('/\A[a-z][a-z0-9_-]*\z/', $this->config[$key] ?? '')) {
                throw new RuntimeException("Invalid {$key}.");
            }
        }

        if (! preg_match('/\A[a-z][a-z0-9-]{0,26}\z/', $this->config['app'])
            || $this->config['runtimeUser'] !== 'app-'.$this->config['app']) {
            throw new RuntimeException('Use a lowercase app name of at most 27 characters and its app-prefixed runtime user.');
        }

        foreach (['fpmChildren', 'phpMemoryMb', 'valkeyMemoryMb', 'memcachedMemoryMb'] as $key) {
            if (! filter_var($this->config[$key] ?? null, FILTER_VALIDATE_INT, [
                'options' => [
                    'min_range' => 1,
                    'max_range' => 99999,
                ],
            ])) {
                throw new RuntimeException('Invalid app resource limit: '.$key);
            }
        }

        if (! preg_match('/\A(?=.{1,253}\z)[a-z0-9]+(?:[.-][a-z0-9]+)+\z/', $this->config['domain'] ?? '')
            || str_contains($this->config['domain'], 'CHANGE_ME')) {
            throw new RuntimeException('Configure the production domain in Envoy.blade.php.');
        }

        if (! preg_match('/\A8\.[4-9]\z/', $this->config['phpVersion'] ?? '')) {
            throw new RuntimeException('Configure a supported PHP version, such as 8.4.');
        }

        $redirectHosts = $this->config['redirectHosts'] ?? [];

        if (! is_array($redirectHosts) || ! array_is_list($redirectHosts)) {
            throw new RuntimeException('Redirect hosts must be a list of hostnames.');
        }

        foreach ($redirectHosts as $host) {
            if (! is_string($host) || ! preg_match('/\A(?=.{1,253}\z)[a-z0-9]+(?:[.-][a-z0-9]+)+\z/', $host)
                || $host === $this->config['domain'] || str_contains($host, 'CHANGE_ME')) {
                throw new RuntimeException('Redirect hosts must be valid hostnames distinct from the production domain.');
            }
        }

        if (count(array_unique($redirectHosts)) !== count($redirectHosts)) {
            throw new RuntimeException('Redirect hostnames must be unique.');
        }

        foreach ([
            'baseDir',
            'systemRoot',
            'phpBinary',
            'composerBinary',
            'nvmDir',
            'buildLock',
            'repositoryKey',
            'knownHosts',
            'certificate',
            'privateKey',
            'originCa',
            'fpmSocket',
        ] as $key) {
            $path = $this->config[$key] ?? '';

            if (! preg_match('#\A/[a-zA-Z0-9/_.-]+\z#', $path) || in_array('..', explode('/', $path), true)) {
                throw new RuntimeException("Invalid {$key}; use an absolute path without spaces or traversal.");
            }
        }

        if (($this->config['nodeBinary'] ?? '') !== '' && ! preg_match(
            '#\A'.preg_quote($this->config['nvmDir'], '#').'/versions/node/v[0-9]+\.[0-9]+\.[0-9]+/bin/node\z#',
            $this->config['nodeBinary'],
        )) {
            throw new RuntimeException('Node must be an exact installed NVM binary.');
        }

        if (count(array_filter(explode('/', $this->config['baseDir']))) < 2 || is_link($this->config['baseDir'])) {
            throw new RuntimeException('The base directory must be a dedicated real application directory.');
        }

        $this->config['baseDir'] = realpath($this->config['baseDir']) ?: $this->config['baseDir'];

        if (! filter_var($this->config['keepReleases'], FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 2],
        ])) {
            throw new RuntimeException('Retain at least two releases.');
        }

        if (! preg_match('#\Agit@github\.com:[a-zA-Z0-9_.-]+/[a-zA-Z0-9_.-]+\.git\z#', $this->config['repository'] ?? '')) {
            throw new RuntimeException('Configure a GitHub SSH repository URL.');
        }

        foreach (['current', 'previous'] as $name) {
            $path = $this->config['baseDir'].'/'.$name;

            if (file_exists($path) && ! is_link($path)) {
                throw new RuntimeException("{$name} must be a managed release symlink.");
            }

            if (is_link($path)) {
                $managed = $this->managedRelease(basename((string) readlink($path)), false);

                if (readlink($path) !== $managed) {
                    throw new RuntimeException('Release symlinks must point directly inside the managed releases directory.');
                }
            }
        }
    }

    private function assertDeployUser(): void
    {
        if (trim($this->command(['id', '-un'], capture: true)) !== $this->config['user']) {
            throw new RuntimeException('Run deployment as the configured deploy user.');
        }
    }

    private function setupApplication(bool $poolOnly = false): void
    {
        $metadata = $this->config['baseDir'].'/.deployment';

        $this->write($metadata.'/setup.sh', $this->templates['setup.sh']);

        if (! $poolOnly) {
            $environment = preg_replace(
                '/^APP_URL=.*$/m',
                'APP_URL=https://'.$this->config['domain'],
                $this->templates['environment.example'],
            );

            $this->write($metadata.'/environment.example', $environment);
        }

        $this->sudo([
            '/bin/bash',
            $metadata.'/setup.sh',
            $poolOnly ? 'pool' : 'setup',
            $this->config['app'],
            $this->config['user'],
            $this->config['baseDir'],
            $this->config['phpVersion'],
            $this->config['systemRoot'],
            (string) $this->config['fpmChildren'],
            (string) $this->config['phpMemoryMb'],
            (string) $this->config['valkeyMemoryMb'],
            (string) $this->config['memcachedMemoryMb'],
        ]);
    }

    private function preflight(bool $checkRepository): void
    {
        $this->assertDeployUser();

        $this->command([
            '/usr/bin/test',
            '-r',
            $this->config['nvmDir'].'/nvm.sh',
        ]);

        $this->command([
            '/usr/bin/test',
            '-w',
            $this->config['buildLock'],
        ]);

        $php = $this->command([
            $this->config['phpBinary'],
            '-r',
            'echo json_encode(["version" => PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION, "extensions" => get_loaded_extensions()]);',
        ], capture: true);

        $runtime = json_decode($php, true, flags: JSON_THROW_ON_ERROR);

        if ($runtime['version'] !== $this->config['phpVersion']) {
            throw new RuntimeException('The selected PHP binary has the wrong version.');
        }

        foreach ([
            'ctype',
            'curl',
            'dom',
            'fileinfo',
            'filter',
            'hash',
            'mbstring',
            'openssl',
            'pcntl',
            'pdo_mysql',
            'posix',
            'redis',
            'session',
            'tokenizer',
            'xml',
        ] as $extension) {
            if (! in_array($extension, $runtime['extensions'], true)) {
                throw new RuntimeException("Missing PHP extension: {$extension}.");
            }
        }

        if ($this->config['nodeBinary'] !== '') {
            $this->checkNode();
        }

        foreach ([
            ['git', '--version'],
            ['curl', '--version'],
            [
                $this->config['phpBinary'],
                $this->config['composerBinary'],
                '--version',
            ],
        ] as $command) {
            $this->command($command);
        }

        $this->sudo([
            '/usr/bin/test',
            '-r',
            $this->config['baseDir'].'/.env',
        ]);

        $this->command([
            '/usr/bin/sudo',
            '-n',
            '-u',
            $this->config['runtimeUser'],
            '/usr/bin/test',
            '-r',
            $this->config['baseDir'].'/.env',
        ]);

        foreach (['certificate', 'privateKey', 'originCa'] as $key) {
            $this->sudo([
                '/usr/bin/test',
                '-s',
                $this->config[$key],
            ]);
        }

        if (! is_readable($this->config['originCa'])) {
            throw new RuntimeException('The origin CA certificate must be readable by the deploy user.');
        }

        $this->sudo([
            '/usr/bin/test',
            '-S',
            $this->config['fpmSocket'],
        ]);

        $this->sudo([
            '/usr/bin/systemctl',
            'is-active',
            'php'.$this->config['phpVersion'].'-fpm',
        ]);

        $this->sudo(['/usr/sbin/nginx', '-t']);

        $environmentFile = $this->config['baseDir'].'/.env';

        $environment = is_readable($environmentFile) ? @parse_ini_file($environmentFile, false, INI_SCANNER_RAW) : false;

        if ($environment === false || empty($environment['APP_KEY'])) {
            throw new RuntimeException('Provision a readable production .env with a persistent APP_KEY.');
        }

        foreach ([
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'DB_CONNECTION' => 'mariadb',
            'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'redis',
            'SESSION_DRIVER' => 'redis',
            'SESSION_CONNECTION' => 'sessions',
            'SESSION_SECURE_COOKIE' => 'true',
            'REDIS_CLIENT' => 'phpredis',
            'REDIS_HOST' => '/run/valkey-'.$this->config['app'].'/valkey.sock',
            'REDIS_PORT' => '0',
            'REDIS_DB' => '0',
            'REDIS_CACHE_DB' => '1',
            'REDIS_SESSION_DB' => '2',
            'REDIS_CACHE_LOCK_CONNECTION' => 'cache',
        ] as $key => $value) {
            if (($environment[$key] ?? null) !== $value) {
                throw new RuntimeException("Production .env must set {$key}={$value}.");
            }
        }

        foreach (['DATABASE_URL', 'DB_URL', 'REDIS_URL'] as $key) {
            if (! empty($environment[$key]) && $environment[$key] !== 'null') {
                throw new RuntimeException('Remove conflicting production URL override: '.$key);
            }
        }

        $this->sudo([
            '/usr/bin/systemctl', 'is-active',
            'laravel-valkey@'.$this->config['app'],
            'laravel-memcached@'.$this->config['app'],
        ]);

        if (($environment['APP_URL'] ?? null) !== 'https://'.$this->config['domain']) {
            throw new RuntimeException('APP_URL must match the configured HTTPS domain.');
        }

        if ((fileperms($environmentFile) & 07) !== 0) {
            throw new RuntimeException('Production .env must not be accessible to other host users; use mode 0640.');
        }

        if (! is_writable($this->config['baseDir'])) {
            throw new RuntimeException('The deploy user must own the writable application base directory.');
        }

        if ($checkRepository) {
            $this->command([
                'git',
                'ls-remote',
                '--exit-code',
                $this->config['repository'],
                'HEAD',
            ], environment: $this->repositoryEnvironment(), capture: true);
        }
    }

    private function setupDirectories(): void
    {
        $base = $this->config['baseDir'];

        $this->directory($base.'/releases');

        $this->createStorageDirectories($base.'/storage');

        $this->sudo([
            '/usr/bin/chgrp',
            '-hR',
            $this->config['runtimeUser'],
            $base.'/storage',
        ]);

        $this->sudo([
            '/usr/bin/chmod',
            '-R',
            'u+rwX,g+rwX,o-rwx',
            $base.'/storage',
        ]);

        $this->writableDirectory($base.'/storage');

        foreach ([
            $base,
            $base.'/releases',
            $base.'/storage',
            $base.'/storage/app',
        ] as $path) {
            $this->sudo([
                '/usr/bin/setfacl',
                '-m',
                'u:'.$this->config['webUser'].':--x',
                $path,
            ]);
        }

        $this->publicDirectory($base.'/storage/app/public');
    }

    private function createStorageDirectories(string $storage): void
    {
        foreach ([
            'app/private',
            'app/public',
            'framework/cache/data',
            'framework/sessions',
            'framework/views',
            'framework/tmp',
            'logs',
        ] as $directory) {
            $this->directory($storage.'/'.$directory);
        }
    }

    private function writableDirectory(string $path): void
    {
        $this->sudo([
            '/usr/bin/setfacl',
            '-RP',
            '-m',
            'u:'.$this->config['user'].':rwX,g::rwX,o::---',
            $path,
        ]);

        $this->sudo([
            '/usr/bin/find',
            $path,
            '-type',
            'd',
            '-exec',
            'chmod',
            'g+s',
            '{}',
            '+',
        ]);

        $this->sudo([
            '/usr/bin/find',
            $path,
            '-type',
            'd',
            '-exec',
            'setfacl',
            '-m',
            'd:u::rwx,d:u:'.$this->config['user'].':rwx,d:g::rwx,d:o::---',
            '{}',
            '+',
        ]);
    }

    private function publicDirectory(string $path): void
    {
        $this->sudo([
            '/usr/bin/setfacl',
            '-RP',
            '-m',
            'u:'.$this->config['webUser'].':rX',
            $path,
        ]);

        $this->sudo([
            '/usr/bin/find',
            $path,
            '-type',
            'd',
            '-exec',
            'setfacl',
            '-m',
            'd:u:'.$this->config['webUser'].':r-x',
            '{}',
            '+',
        ]);
    }

    /** @return array<string, string> */
    private function repositoryEnvironment(): array
    {
        return [
            'GIT_SSH_COMMAND' => 'ssh -o BatchMode=yes -o IdentitiesOnly=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile='
                .escapeshellarg($this->config['knownHosts'])
                .' -i '.escapeshellarg($this->config['repositoryKey']),
        ];
    }

    private function checkNode(): void
    {
        $node = ltrim(trim($this->command([
            $this->config['nodeBinary'],
            '--version',
        ], capture: true)), 'v');

        if (version_compare($node, '22.12.0', '<')) {
            throw new RuntimeException('Node 22.12 or newer is required.');
        }
    }

    private function resolveNode(string $target): void
    {
        $version = trim($this->read($target.'/.nvmrc'));

        if (! preg_match('/\Av?[0-9]+(?:\.[0-9]+){0,2}\z/', $version)) {
            throw new RuntimeException('Commit a numeric Node version in .nvmrc.');
        }

        $script = 'set -eo pipefail; umask 022; export NVM_DIR="$1"; source "$NVM_DIR/nvm.sh"; nvm install "$2" >&2; nvm which "$2"';

        $this->config['nodeBinary'] = trim($this->command([
            '/bin/bash',
            '-c',
            $script,
            'resolve-node',
            $this->config['nvmDir'],
            $version,
        ], capture: true));

        $this->validate();

        $this->checkNode();
    }

    private function prepare(string $branch): string
    {
        $lock = fopen($this->config['buildLock'], 'c');

        if ($lock === false || ! flock($lock, LOCK_EX)) {
            throw new RuntimeException('Could not acquire the host build lock.');
        }

        try {
            return $this->prepareRelease($branch);
        } finally {
            flock($lock, LOCK_UN);

            fclose($lock);
        }
    }

    private function prepareRelease(string $branch): string
    {
        $this->command(['git', 'check-ref-format', '--branch', $branch], capture: true);

        $target = $this->config['baseDir'].'/releases/'.gmdate('YmdHis').'-'.bin2hex(random_bytes(4));

        $metadata = $target.'/.deployment';

        if (file_exists($target) || is_link($target)) {
            throw new RuntimeException('The generated release directory already exists.');
        }

        try {
            $this->command([
                'git',
                'clone',
                '--depth',
                '1',
                '--single-branch',
                '--branch',
                $branch,
                '--',
                $this->config['repository'],
                $target,
            ], environment: $this->repositoryEnvironment());
        } catch (Throwable $exception) {
            $this->recordInterruptedClone($target);

            throw $exception;
        }

        $this->directory($metadata);

        $this->write($metadata.'/owned', $this->config['app']);

        $this->resolveNode($target);

        $this->write($target.'/VERSION', trim($this->command(['git', 'rev-parse', 'HEAD'], $target, capture: true))."\n");

        $this->removeTree($target.'/storage');

        $this->createStorageDirectories($target.'/storage');

        $this->symlink($this->config['baseDir'].'/.env', $target.'/.env');

        $this->directory($metadata.'/bin');

        $this->directory($metadata.'/views');

        $this->directory($target.'/bootstrap/cache');

        $this->symlink($this->config['phpBinary'], $metadata.'/bin/php');

        $this->symlink($this->config['nodeBinary'], $metadata.'/bin/node');

        $environment = $this->releaseEnvironment($target);

        $this->command([
            $this->config['phpBinary'],
            $this->config['composerBinary'],
            'install',
            '--no-dev',
            '--no-scripts',
            '--no-interaction',
            '--prefer-dist',
            '--optimize-autoloader',
        ], $target, $environment);

        $this->artisan($target, ['package:discover']);

        $this->command([
            $this->config['phpBinary'],
            $this->config['composerBinary'],
            'check-platform-reqs',
            '--no-dev',
        ], $target, $environment);

        $current = $this->linkTarget('current');

        if ($current !== null) {
            $previousRuntime = $this->readJson($current.'/.deployment/runtime.json');

            if ($previousRuntime['phpVersion'] !== $this->config['phpVersion']) {
                $this->command([
                    $previousRuntime['phpBinary'],
                    $this->config['composerBinary'],
                    'check-platform-reqs',
                    '--no-dev',
                ], $target, $environment);
            }
        }

        $npm = dirname($this->config['nodeBinary']).'/npm';

        $this->command([$npm, 'ci', '--include=dev'], $target, $environment);

        $this->command([$npm, 'run', 'production'], $target, $environment);

        $this->validateAssets($target);

        $this->artisan($target, ['storage:link']);

        $this->artisan($target, ['optimize']);

        $this->removeTree($target.'/storage');

        $this->symlink($this->config['baseDir'].'/storage', $target.'/storage');

        $this->sudo([
            '/usr/bin/chown',
            '-hR',
            $this->config['user'].':'.$this->config['runtimeUser'],
            $target,
        ]);

        $this->sudo(['/usr/bin/chmod', '-R', 'u=rwX,g=rX,o=', $target]);

        $this->writableDirectory($metadata.'/views');

        $this->writableDirectory($target.'/bootstrap/cache');

        $this->sudo([
            '/usr/bin/setfacl',
            '-m',
            'u:'.$this->config['webUser'].':--x',
            $target,
        ]);

        $this->publicDirectory($target.'/public');

        $this->writeJson($metadata.'/runtime.json', $this->config);

        $values = [
            '{{APP}}' => $this->config['app'],
            '{{USER}}' => $this->config['runtimeUser'],
            '{{BASE}}' => $this->config['baseDir'],
            '{{DOMAIN}}' => $this->config['domain'],
            '{{PHP}}' => $this->config['phpBinary'],
            '{{FPM_SOCKET}}' => $this->config['fpmSocket'],
            '{{NODE_BIN}}' => dirname($this->config['nodeBinary']),
            '{{CERTIFICATE}}' => $this->config['certificate'],
            '{{PRIVATE_KEY}}' => $this->config['privateKey'],
            '{{REDIRECT_SERVERS}}' => $this->redirectServers(),
        ];

        foreach (['nginx.conf', 'cron'] as $name) {
            $this->write($metadata.'/'.$name, strtr($this->templates[$name], $values));
        }

        return $target;
    }

    private function validateAssets(string $target): void
    {
        $manifest = $this->readJson($target.'/public/mix-manifest.json');

        foreach (['/css/app.css', '/js/app.js'] as $asset) {
            $url = $manifest[$asset] ?? null;
            $path = is_string($url) ? parse_url($url, PHP_URL_PATH) : null;

            if ($path !== $asset || ! is_file($target.'/public'.$asset)
                || filesize($target.'/public'.$asset) === 0) {
                throw new RuntimeException('The Mix build must produce a valid manifest and asset: '.$asset);
            }
        }
    }

    private function redirectServers(): string
    {
        $hosts = implode(' ', $this->config['redirectHosts'] ?? []);

        if ($hosts === '') {
            return '';
        }

        $domain = $this->config['domain'];
        $certificate = $this->config['certificate'];
        $privateKey = $this->config['privateKey'];

        return <<<NGINX
        server {
            listen 80;
            listen [::]:80;
            listen 443 ssl http2;
            listen [::]:443 ssl http2;
            server_name {$hosts};
            ssl_certificate {$certificate};
            ssl_certificate_key {$privateKey};
            ssl_protocols TLSv1.2 TLSv1.3;
            return 301 https://{$domain}\$request_uri;
        }
        NGINX;
    }

    private function recordInterruptedClone(string $target): void
    {
        clearstatcache(true, $target);

        if (is_dir($target) && ! is_link($target)) {
            $this->directory($target.'/.deployment');

            $this->write($target.'/.deployment/owned', $this->config['app']);
        }
    }

    private function activate(string $target, bool $migrate): void
    {
        $journal = $this->snapshot();

        $journalFile = $this->config['baseDir'].'/.deployment/transaction.json';

        $this->writeJson($journalFile, $journal);

        try {
            if ($migrate) {
                $this->artisan($target, ['migrate', '--force'], asRuntimeUser: true);
            }

            $this->installConfiguration($target);

            if ($journal['current'] !== null && $journal['current'] !== $target) {
                $this->symlink($journal['current'], $this->config['baseDir'].'/previous');
            }

            $this->symlink($target, $this->config['baseDir'].'/current');

            $this->sudo([
                '/usr/bin/systemctl',
                'reload',
                'php'.$this->config['phpVersion'].'-fpm',
            ]);

            $this->sudo(['/usr/bin/systemctl', 'reload', 'nginx']);

            $this->healthy($target);

            $this->writeJson($target.'/.deployment/success.json', [
                'activatedAt' => microtime(true),
            ]);

            unlink($journalFile);

            echo 'Activated '.basename($target)."\n";
        } catch (Throwable $exception) {
            $this->interrupted = false;

            try {
                $this->restore($journal);

                unlink($journalFile);
            } catch (Throwable $recoveryException) {
                throw new RuntimeException(
                    'Deployment failed: '.$exception->getMessage()
                        .'. Recovery also failed: '.$recoveryException->getMessage()
                        .'. The transaction journal is retained; run rollback to retry recovery.',
                    previous: $exception,
                );
            }

            throw new RuntimeException(
                'Deployment failed and previous state was restored: '.$exception->getMessage().'. Database migrations were not reversed.',
                previous: $exception,
            );
        }
    }

    /** @return array<string, string> */
    private function configurationFiles(): array
    {
        $root = $this->config['systemRoot'];
        $app = $this->config['app'];

        return [
            'nginx.conf' => $root.'/nginx/sites-available/'.$app.'.conf',
            'cron' => $root.'/cron.d/'.$app,
        ];
    }

    private function enabledSite(): string
    {
        return $this->config['systemRoot'].'/nginx/sites-enabled/'.$this->config['app'].'.conf';
    }

    /** @return array{current: ?string, previous: ?string, files: array<string, string|null>, enabled: bool, config: array<string, mixed>} */
    private function snapshot(): array
    {
        $files = [];

        foreach ($this->configurationFiles() as $path) {
            if (is_link($path) || is_file($path) && ! is_readable($path)) {
                throw new RuntimeException('Managed configuration must be a readable regular file: '.$path);
            }

            $files[$path] = is_file($path) ? $this->read($path) : null;
        }

        $enabled = $this->enabledSite();

        if (file_exists($enabled) && ! is_link($enabled)) {
            throw new RuntimeException('The enabled app site must be a symlink.');
        }

        if (is_link($enabled) && readlink($enabled) !== $this->configurationFiles()['nginx.conf']) {
            throw new RuntimeException('The app site symlink points to an unmanaged configuration.');
        }

        $current = $this->linkTarget('current');

        return [
            'current' => $current,
            'previous' => $this->linkTarget('previous'),
            'files' => $files,
            'enabled' => is_link($enabled),
            'config' => $current === null ? $this->config : $this->readJson($current.'/.deployment/runtime.json'),
        ];
    }

    private function installConfiguration(string $target): void
    {
        foreach ($this->configurationFiles() as $name => $path) {
            $source = $target.'/.deployment/'.$name;

            if (! is_file($source)) {
                throw new RuntimeException('Missing saved release configuration: '.$name);
            }

            if (! is_file($path) || file_get_contents($source) !== file_get_contents($path)) {
                $this->sudo(['/usr/bin/install', '-m', '0644', $source, $path]);
            }
        }

        if (! is_link($this->enabledSite())) {
            $this->sudo([
                '/usr/bin/ln',
                '-s',
                $this->configurationFiles()['nginx.conf'],
                $this->enabledSite(),
            ]);
        }

        $this->sudo(['/usr/sbin/nginx', '-t']);
    }

    /** @param array<string, mixed> $runtime */
    private function useRuntime(array $runtime): void
    {
        foreach ([
            'app',
            'baseDir',
            'systemRoot',
            'user',
            'runtimeUser',
            'webUser',
            'nvmDir',
        ] as $key) {
            if (($runtime[$key] ?? null) !== $this->config[$key]) {
                throw new RuntimeException('Saved runtime belongs to a different application: '.$key);
            }
        }

        $this->config = $runtime;
    }

    /** @param array<string, mixed> $journal */
    private function restore(array $journal): void
    {
        $base = $this->config['baseDir'];

        $this->useRuntime($journal['config']);

        $this->validate();

        if ($journal['current'] !== null) {
            $this->setupApplication(poolOnly: true);
        }

        foreach ($this->configurationFiles() as $path) {
            if (! array_key_exists($path, $journal['files'])) {
                throw new RuntimeException('Recovery journal does not match managed configuration paths.');
            }

            $contents = $journal['files'][$path];

            if ($contents === null) {
                $this->sudo(['/usr/bin/rm', '-f', $path]);
            } else {
                $source = $base.'/.deployment/restore-'.basename($path);

                $this->write($source, $contents);

                $this->sudo(['/usr/bin/install', '-m', '0644', $source, $path]);
                unlink($source);
            }
        }

        if (! $journal['enabled']) {
            $this->sudo([
                '/usr/bin/rm',
                '-f',
                $this->enabledSite(),
            ]);
        }

        foreach (['current', 'previous'] as $name) {
            if ($journal[$name] === null) {
                if (is_link($base.'/'.$name)) {
                    unlink($base.'/'.$name);
                }
            } else {
                $target = $this->managedRelease(basename($journal[$name]), false);

                $this->symlink($target, $base.'/'.$name);
            }
        }

        $this->sudo(['/usr/sbin/nginx', '-t']);

        $this->sudo(['/usr/bin/systemctl', 'reload', 'nginx']);

        if ($journal['current'] !== null) {
            $this->sudo([
                '/usr/bin/systemctl',
                'reload',
                'php'.$this->config['phpVersion'].'-fpm',
            ]);

            $this->healthy($journal['current']);
        }

        echo "Previous deployment state restored. Database changes remain in place.\n";
    }

    private function healthy(string $target): void
    {
        foreach (['/up', '/'] as $path) {
            $response = $this->command([
                'curl',
                '--fail',
                '--silent',
                '--show-error',
                '--max-time',
                '15',
                '--retry',
                '2',
                '--retry-all-errors',
                '--write-out',
                '\n%{http_code}',
                '--resolve',
                $this->config['domain'].':443:127.0.0.1',
                '--cacert',
                $this->config['originCa'],
                'https://'.$this->config['domain'].$path,
            ], capture: true);

            if (! str_ends_with($response, "\n200")) {
                throw new RuntimeException('Health check must return HTTP 200: '.$path);
            }

            if ($path === '/' && ! str_contains($response, "<title>Hayley O'Kelly | CV</title>")) {
                throw new RuntimeException('The homepage did not render the expected website.');
            }
        }
    }

    private function cleanup(): void
    {
        $protected = array_filter([
            $this->linkTarget('current'),
            $this->linkTarget('previous'),
        ]);

        $successful = $this->successfulReleases();

        $keep = $protected;

        foreach ($successful as $path) {
            if (count($keep) >= $this->config['keepReleases']) {
                break;
            }

            if (! in_array($path, $keep, true)) {
                $keep[] = $path;
            }
        }

        foreach (glob($this->config['baseDir'].'/releases/*') ?: [] as $path) {
            if (! is_link($path)
                && preg_match('/\A\d{14}-[a-f0-9]{8}\z/', basename($path))
                && is_file($path.'/.deployment/owned')
                && file_get_contents($path.'/.deployment/owned') === $this->config['app']
                && ! in_array($path, $keep, true)) {
                $this->removeTree($path);
            }
        }
    }

    /** @return list<string> */
    private function successfulReleases(): array
    {
        $releases = [];

        foreach (glob($this->config['baseDir'].'/releases/*') ?: [] as $path) {
            if (! is_link($path)
                && is_file($path.'/.deployment/success.json')
                && is_file($path.'/.deployment/owned')
                && file_get_contents($path.'/.deployment/owned') === $this->config['app']) {
                $releases[] = $path;
            }
        }

        usort(
            $releases,
            function (string $left, string $right): int {
                return $this->readJson($right.'/.deployment/success.json')['activatedAt']
                    <=> $this->readJson($left.'/.deployment/success.json')['activatedAt'];
            },
        );

        return $releases;
    }

    private function listReleases(): void
    {
        foreach ($this->successfulReleases() as $path) {
            $state = $path === $this->linkTarget('current')
                ? 'current'
                : ($path === $this->linkTarget('previous') ? 'previous' : 'retained');

            echo basename($path).' '.trim($this->read($path.'/VERSION')).' '.$state."\n";
        }
    }

    private function managedRelease(string $name, bool $successful = true): string
    {
        $path = $this->config['baseDir'].'/releases/'.$name;

        if (! preg_match('/\A\d{14}-[a-f0-9]{8}\z/', $name)
            || is_link($path)
            || ! is_file($path.'/.deployment/owned')
            || file_get_contents($path.'/.deployment/owned') !== $this->config['app']
            || $successful && ! is_file($path.'/.deployment/success.json')) {
            throw new RuntimeException('Select a retained managed release.');
        }

        return $path;
    }

    private function linkTarget(string $name): ?string
    {
        $path = $this->config['baseDir'].'/'.$name;

        return is_link($path) ? realpath($path) ?: null : null;
    }

    /** @return array<string, string> */
    private function releaseEnvironment(string $target): array
    {
        return [
            'PATH' => $target.'/.deployment/bin:'.dirname($this->config['nodeBinary']).':'.getenv('PATH'),
            'VIEW_COMPILED_PATH' => $target.'/.deployment/views',
        ];
    }

    /** @param list<string> $arguments */
    private function artisan(string $target, array $arguments, bool $asRuntimeUser = false): void
    {
        $environment = $this->releaseEnvironment($target);

        $command = [
            $this->config['phpBinary'],
            'artisan',
            ...$arguments,
            '--no-interaction',
        ];

        if ($asRuntimeUser) {
            $assignments = [];

            foreach ($environment as $name => $value) {
                $assignments[] = $name.'='.$value;
            }

            $command = [
                '/usr/bin/sudo',
                '-n',
                '-u',
                $this->config['runtimeUser'],
                '/usr/bin/env',
                ...$assignments,
                ...$command,
            ];
        }

        $this->command($command, $target, $environment);
    }

    /** @param list<string> $arguments */
    private function sudo(array $arguments, bool $capture = false): string
    {
        return $this->command([
            '/usr/bin/sudo',
            '-n',
            ...$arguments,
        ], capture: $capture);
    }

    /**
     * @param  list<string>  $arguments
     * @param  array<string, string>  $environment
     */
    private function command(
        array $arguments,
        ?string $directory = null,
        array $environment = [],
        bool $capture = false,
    ): string {
        $this->checkInterruption();

        echo $capture ? '' : '> '.implode(' ', $arguments)."\n";

        if ($this->runner !== null) {
            $output = ($this->runner)($arguments, $directory, $environment);

            $this->checkInterruption();

            return $output;
        }

        $process = proc_open($arguments, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['pipe', 'w'],
            2 => STDERR,
        ], $pipes, $directory, array_replace(getenv(), $environment));

        if (! is_resource($process)) {
            throw new RuntimeException('Could not start command: '.$arguments[0]);
        }

        $output = '';

        while (! feof($pipes[1])) {
            $chunk = fread($pipes[1], 8192);

            if ($chunk === false) {
                fclose($pipes[1]);

                proc_close($process);

                throw new RuntimeException('Could not read command output.');
            }

            $output .= $chunk;

            if (! $capture) {
                echo $chunk;
            }
        }

        fclose($pipes[1]);

        $status = proc_close($process);

        $this->checkInterruption();

        if ($status !== 0) {
            throw new RuntimeException('Command failed ('.$status.'): '.implode(' ', $arguments), $status);
        }

        return $output;
    }

    private function checkInterruption(): void
    {
        if ($this->interrupted) {
            throw new RuntimeException('Deployment interrupted after the in-flight command returned.');
        }
    }

    private function directory(string $path): void
    {
        if (! is_dir($path) && ! mkdir($path, 0750, true)) {
            throw new RuntimeException('Could not create directory: '.$path);
        }
    }

    private function write(string $path, string $contents): void
    {
        $temporary = $path.'.tmp-'.bin2hex(random_bytes(4));

        if (file_put_contents($temporary, $contents) === false || ! rename($temporary, $path)) {
            throw new RuntimeException('Could not write file: '.$path);
        }
    }

    /** @param array<string, mixed> $data */
    private function writeJson(string $path, array $data): void
    {
        $this->write($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $data = json_decode($this->read($path), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException('Expected a deployment metadata object: '.$path);
        }

        return $data;
    }

    private function read(string $path): string
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Could not read file: '.$path);
        }

        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('Could not read file: '.$path);
        }

        return $contents;
    }

    private function symlink(string $target, string $path): void
    {
        $temporary = $path.'.next-'.bin2hex(random_bytes(4));

        if (! symlink($target, $temporary) || ! rename($temporary, $path)) {
            throw new RuntimeException('Could not atomically replace symlink: '.$path);
        }
    }

    private function removeTree(string $path): void
    {
        if (is_link($path)) {
            unlink($path);

            return;
        }

        if (! is_dir($path)) {
            return;
        }

        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($files as $file) {
            if ($file->isDir() && ! $file->isLink()) {
                rmdir($file->getPathname());
            } else {
                unlink($file->getPathname());
            }
        }

        rmdir($path);
    }
}
