@setup
    /** Ubuntu 24.04 LTS with versioned PHP packages from ppa:ondrej/php. See docs/deploys.md. */
    $server = $server ?? '85.155.189.223';
    $domain = 'hayleyokelly.com';
    $phpVersion = '8.4';
    $baseDir = '/var/www/hayley-website';

    $config = [
        'app' => 'hayley-website',
        'user' => 'deploy',
        'runtimeUser' => 'app-hayley-website',
        'webUser' => 'www-data',
        'repository' => 'git@github.com:aaronheath/hayley-website.git',
        'domain' => $domain,
        'redirectHosts' => ['www.hayleyokelly.com'],
        'baseDir' => $baseDir,
        'phpVersion' => $phpVersion,
        'phpBinary' => "/usr/bin/php{$phpVersion}",
        'composerBinary' => '/usr/local/bin/composer',
        'nodeBinary' => '',
        'nvmDir' => '/opt/nvm',
        'buildLock' => '/var/lib/laravel-deploy/build.lock',
        'repositoryKey' => '/var/lib/laravel-deploy/keys/hayley-website',
        'knownHosts' => '/etc/laravel-host/github_known_hosts',
        'fpmChildren' => 4,
        'phpMemoryMb' => 256,
        'valkeyMemoryMb' => 256,
        'memcachedMemoryMb' => 64,
        'fpmSocket' => "/run/php/php{$phpVersion}-hayley-website.sock",
        'systemRoot' => '/etc',
        'certificate' => "/etc/ssl/cloudflare/{$domain}.pem",
        'privateKey' => "/etc/ssl/cloudflare/{$domain}.key",
        'originCa' => '/etc/ssl/cloudflare/origin-ca.pem',
        'keepReleases' => 5,
    ];

    $localMode = (string) ($local ?? '0') === '1';
    $branch = $branch ?? 'master';
    $release = $release ?? '';
    $taskOptions = [
        'on' => 'target',
    ];
    $templates = [];

    foreach (['nginx.conf', 'cron', 'setup.sh'] as $name) {
        $templates[$name] = file_get_contents($__dir.'/.meta/deployment/'.$name);
    }

    $templates['environment.example'] = file_get_contents($__dir.'/.env.example');

    $bundle = base64_encode(json_encode([
        'config' => $config,
        'templates' => $templates,
        'script' => file_get_contents($__dir.'/.meta/scripts/Deployment.php'),
    ], JSON_THROW_ON_ERROR));

    $bootstrap = <<<'PHP'
    $bundle = json_decode(base64_decode($argv[1], true), true, flags: JSON_THROW_ON_ERROR);

    $script = tmpfile();

    fwrite($script, $bundle['script']);

    require stream_get_meta_data($script)['uri'];

    try {
        umask(0027);

        (new \HayleyWebsite\Deployment\Deployment($bundle['config'], $bundle['templates']))
            ->run($argv[2], $argv[3], $argv[4] === '' ? null : $argv[4]);
    } catch (\Throwable $exception) {
        fwrite(STDERR, $exception->getMessage().PHP_EOL);

        exit(1);
    }
    PHP;

    $command = static function (string $action) use ($config, $bootstrap, $bundle, $branch, $release): string {
        return 'exec '.implode(' ', array_map('escapeshellarg', [
            $config['phpBinary'],
            '-r',
            $bootstrap,
            $bundle,
            $action,
            $branch,
            $release,
        ]));
    };
@endsetup

@servers([
    'target' => $localMode ? '127.0.0.1' : "{$config['user']}@{$server}",
])

@task('setup', $taskOptions)
    set -eu
    {{ $command('setup') }}
@endtask

@task('check', $taskOptions)
    set -eu
    {{ $command('check') }}
@endtask

@task('deploy', $taskOptions)
    set -eu
    {{ $command('deploy') }}
@endtask

@task('releases', $taskOptions)
    set -eu
    {{ $command('releases') }}
@endtask

@task('rollback', $taskOptions)
    set -eu
    {{ $command('rollback') }}
@endtask
