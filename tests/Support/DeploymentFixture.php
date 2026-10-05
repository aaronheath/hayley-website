<?php

namespace Tests\Support;

use Closure;
use HayleyWebsite\Deployment\Deployment;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

require_once __DIR__.'/../../.meta/scripts/Deployment.php';

/** Simulates the host filesystem and commands without sudo, SSH or service changes. */
final class DeploymentFixture
{
    public string $root;
    public array $config;
    public array $calls = [];
    public ?Closure $failure = null;
    public int $migrations = 0;
    public int $clones = 0;
    public string $nodeSelection = '22';
    public string $identity = 'deploy';
    public string $httpStatus = '200';
    public string $homepage = "<title>Hayley O'Kelly | CV</title>";

    public function __construct()
    {
        $this->root = realpath(sys_get_temp_dir()).'/hayley-deploy-'.bin2hex(random_bytes(5));
        $this->config = [
            'app' => 'hayley-website',
            'user' => 'deploy',
            'runtimeUser' => 'app-hayley-website',
            'webUser' => 'www-data',
            'repository' => 'git@github.com:aaronheath/hayley-website.git',
            'domain' => 'hayleyokelly.com',
            'redirectHosts' => ['www.hayleyokelly.com'],
            'baseDir' => $this->root.'/app',
            'phpVersion' => '8.4',
            'phpBinary' => $this->root.'/bin/php8.4',
            'composerBinary' => $this->root.'/bin/composer',
            'nodeBinary' => '',
            'nvmDir' => $this->root.'/nvm',
            'buildLock' => $this->root.'/build.lock',
            'repositoryKey' => $this->root.'/key',
            'knownHosts' => $this->root.'/known_hosts',
            'fpmChildren' => 4,
            'phpMemoryMb' => 256,
            'valkeyMemoryMb' => 256,
            'memcachedMemoryMb' => 64,
            'systemRoot' => $this->root.'/etc',
            'fpmSocket' => $this->root.'/php8.4.sock',
            'certificate' => $this->root.'/origin.pem',
            'privateKey' => $this->root.'/origin.key',
            'originCa' => $this->root.'/ca.pem',
            'keepReleases' => 5,
        ];
        foreach (['app', 'bin', 'etc/nginx/sites-available', 'etc/nginx/sites-enabled', 'etc/cron.d'] as $path) {
            mkdir($this->root.'/'.$path, 0750, true);
        }
        foreach (['origin.pem', 'origin.key', 'ca.pem'] as $path) {
            file_put_contents($this->root.'/'.$path, 'certificate fixture');
        }
        file_put_contents($this->root.'/build.lock', '');
        $environment = [
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_KEY' => 'base64:fixture',
            'APP_URL' => 'https://hayleyokelly.com', 'DB_CONNECTION' => 'mariadb',
            'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'redis', 'SESSION_DRIVER' => 'redis',
            'SESSION_CONNECTION' => 'sessions', 'SESSION_SECURE_COOKIE' => 'true',
            'REDIS_CLIENT' => 'phpredis', 'REDIS_HOST' => '/run/valkey-hayley-website/valkey.sock',
            'REDIS_PORT' => '0', 'REDIS_DB' => '0', 'REDIS_CACHE_DB' => '1', 'REDIS_SESSION_DB' => '2',
            'REDIS_CACHE_LOCK_CONNECTION' => 'cache',
        ];
        $contents = '';
        foreach ($environment as $key => $value) {
            $contents .= $key.'='.$value."\n";
        }
        file_put_contents($this->config['baseDir'].'/.env', $contents);
        chmod($this->config['baseDir'].'/.env', 0640);
    }

    public function deployment(): Deployment
    {
        $templates = [];
        foreach (['nginx.conf', 'cron', 'setup.sh'] as $name) {
            $templates[$name] = file_get_contents(__DIR__.'/../../.meta/deployment/'.$name);
        }
        $templates['environment.example'] = file_get_contents(__DIR__.'/../../.env.example');

        return new Deployment($this->config, $templates, $this->execute(...));
    }

    public function deploy(): string
    {
        $this->deployment()->run('deploy');
        return realpath($this->config['baseDir'].'/current');
    }

    public function execute(array $arguments, ?string $directory, array $environment): string
    {
        $this->calls[] = ['arguments' => $arguments, 'directory' => $directory, 'environment' => $environment];
        $command = $arguments[0] === '/usr/bin/sudo' ? array_slice($arguments, 2) : $arguments;
        if (in_array('artisan', $command, true)) {
            $command = array_slice($command, array_search('artisan', $command, true) - 1);
        }
        $tool = basename($command[0]);
        if ($tool === 'curl' && ($command[1] ?? '') === '--version') {
            return '';
        }
        $output = '';
        if ($tool === 'id') {
            $output = $this->identity;
        } elseif (str_starts_with($tool, 'php') && ($command[1] ?? '') === '-r') {
            $output = json_encode([
                'version' => substr($tool, 3),
                'extensions' => ['ctype', 'curl', 'dom', 'fileinfo', 'filter', 'hash', 'mbstring',
                    'openssl', 'pcntl', 'pdo_mysql', 'posix', 'redis', 'session', 'tokenizer', 'xml'],
            ]);
        } elseif ($tool === 'bash' && ($command[1] ?? '') === '-c') {
            $output = $this->root.'/nvm/versions/node/v22.22.1/bin/node';
        } elseif ($tool === 'node') {
            $output = 'v22.22.1';
        } elseif ($tool === 'git' && $command[1] === 'clone') {
            $target = end($command);
            mkdir($target.'/storage', 0750, true);
            mkdir($target.'/public/css', 0750, true);
            mkdir($target.'/public/js', 0750, true);
            file_put_contents($target.'/.nvmrc', $this->nodeSelection."\n");
            $this->clones++;
        } elseif ($tool === 'git' && $command[1] === 'rev-parse') {
            $output = str_pad(dechex($this->clones), 40, '0', STR_PAD_LEFT);
        } elseif ($tool === 'npm' && ($command[1] ?? '') === 'run') {
            file_put_contents($directory.'/public/css/app.css', 'body {color: black}');
            file_put_contents($directory.'/public/js/app.js', 'window.fixture = true;');
            file_put_contents($directory.'/public/mix-manifest.json', json_encode([
                '/css/app.css' => '/css/app.css?id=fixture', '/js/app.js' => '/js/app.js?id=fixture',
            ]));
        } elseif (($command[1] ?? '') === 'artisan' && $command[2] === 'migrate') {
            $this->migrations++;
        } elseif ($tool === 'install' && $command[1] === '-m') {
            copy($command[3], $command[4]);
        } elseif ($tool === 'install' && $command[1] === '-d') {
            mkdir(end($command), 0750, true);
        } elseif ($tool === 'ln') {
            symlink($command[2], $command[3]);
        } elseif ($tool === 'rm' && (file_exists($command[2]) || is_link($command[2]))) {
            unlink($command[2]);
        } elseif ($tool === 'curl') {
            $output = (str_ends_with(end($command), '/up') ? 'OK' : $this->homepage)."\n".$this->httpStatus;
        } elseif ($tool === 'supervisorctl' || str_contains(implode(' ', $command), 'inertia:')) {
            throw new RuntimeException('Unexpected background service command.');
        }
        if ($this->failure !== null) {
            ($this->failure)($command, $directory, $environment);
        }
        return $output;
    }

    public function destroy(): void
    {
        (new Filesystem)->deleteDirectory($this->root);
    }
}
