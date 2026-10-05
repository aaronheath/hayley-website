<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Support\DeploymentFixture;
use Tests\TestCase;

class DeploymentTest extends TestCase
{
    private DeploymentFixture $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->host = new DeploymentFixture;
        ob_start();
    }

    protected function tearDown(): void
    {
        ob_end_clean();
        $this->host->destroy();
        parent::tearDown();
    }

    public function test_first_release_builds_before_activation_and_uses_isolated_runtime(): void
    {
        $this->host->failure = function (array $command, ?string $directory): void {
            if (($command[2] ?? '') === 'migrate') {
                $this->assertFileExists($directory.'/public/mix-manifest.json');
                $this->assertFalse(is_link($this->host->config['baseDir'].'/current'));
            }
            if (($command[2] ?? '') === 'optimize') {
                $this->assertFalse(is_link($directory.'/storage'));
            }
        };
        $environment = file_get_contents($this->host->config['baseDir'].'/.env');
        $release = $this->host->deploy();
        $this->assertSame($this->host->config['baseDir'].'/.env', readlink($release.'/.env'));
        $this->assertSame($this->host->config['baseDir'].'/storage', readlink($release.'/storage'));
        $this->assertFileExists($release.'/.deployment/success.json');
        $this->assertSame($environment, file_get_contents($release.'/.env'));
        $this->assertSame(1, $this->host->migrations);
        $runtime = json_decode(file_get_contents($release.'/.deployment/runtime.json'), true);
        $this->assertSame($this->host->root.'/nvm/versions/node/v22.22.1/bin/node', $runtime['nodeBinary']);
        foreach ($this->host->calls as $call) {
            $command = $call['arguments'];
            $this->assertStringNotContainsString('supervisor', implode(' ', $command));
            $this->assertStringNotContainsString('inertia', implode(' ', $command));
            $this->assertArrayNotHasKey('INERTIA_SSR_PORT', $call['environment']);
            if (basename($command[0]) === 'npm') {
                $this->assertSame(dirname($runtime['nodeBinary']).'/npm', $command[0]);
                $this->assertContains(array_slice($command, 1), [['ci', '--include=dev'], ['run', 'production']]);
                $this->assertSame($release.'/.deployment/views', $call['environment']['VIEW_COMPILED_PATH']);
            }
            if (in_array('migrate', $command, true)) {
                $this->assertSame(['/usr/bin/sudo', '-n', '-u', 'app-hayley-website', '/usr/bin/env'], array_slice($command, 0, 5));
            }
        }
        $this->assertFileExists($this->host->root.'/etc/cron.d/hayley-website');
        $this->assertFalse(file_exists($release.'/.deployment/supervisor.conf'));
        $this->assertStringContainsString('location /css/', file_get_contents($release.'/.deployment/nginx.conf'));
        $this->assertStringContainsString('www.hayleyokelly.com', file_get_contents($release.'/.deployment/nginx.conf'));
    }

    public function test_subsequent_deployment_and_rollback_preserve_shared_data_and_do_not_reverse_migrations(): void
    {
        $first = $this->host->deploy();
        file_put_contents($first.'/storage/app/public/resume.pdf', 'persistent CV');
        $second = $this->host->deploy();
        $this->assertSame($first, readlink($this->host->config['baseDir'].'/previous'));
        $this->host->deployment()->run('rollback', release: basename($first));
        $this->assertSame($first, readlink($this->host->config['baseDir'].'/current'));
        $this->assertSame($second, readlink($this->host->config['baseDir'].'/previous'));
        $this->assertSame(2, $this->host->migrations);
        $this->assertSame('persistent CV', file_get_contents($first.'/storage/app/public/resume.pdf'));
        $this->host->deployment()->run('rollback', release: basename($second));
        $this->assertSame($second, readlink($this->host->config['baseDir'].'/current'));
    }

    public static function buildFailures(): array
    {
        return [['command'], ['manifest'], ['missing-manifest'], ['css'], ['js'], ['empty'], ['unsafe']];
    }

    #[DataProvider('buildFailures')]
    public function test_failed_build_preserves_the_active_release(string $failure): void
    {
        $first = $this->host->deploy();
        $this->host->failure = function (array $command, ?string $directory) use ($failure): void {
            if (basename($command[0]) !== 'npm' || ($command[1] ?? '') !== 'run') {
                return;
            }
            if ($failure === 'command') {
                throw new RuntimeException('Build failed');
            }
            if ($failure === 'manifest') {
                file_put_contents($directory.'/public/mix-manifest.json', '{}');
            } elseif ($failure === 'missing-manifest') {
                unlink($directory.'/public/mix-manifest.json');
            } elseif ($failure === 'empty') {
                file_put_contents($directory.'/public/css/app.css', '');
            } elseif ($failure === 'unsafe') {
                file_put_contents($directory.'/public/mix-manifest.json', '{"/css/app.css":"/../secret"}');
            } else {
                unlink($directory.'/public/'.$failure.'/app.'.$failure);
            }
        };
        try {
            $this->host->deploy();
            $this->fail('Expected build rejection');
        } catch (RuntimeException $exception) {
            $this->assertSame($first, readlink($this->host->config['baseDir'].'/current'));
            $this->assertSame(1, $this->host->migrations);
            $this->assertFileDoesNotExist($this->host->config['baseDir'].'/.deployment/transaction.json');
        }
    }

    public static function healthFailures(): array
    {
        return [['redirect'], ['error'], ['wrong-page'], ['nginx'], ['migration']];
    }

    #[DataProvider('healthFailures')]
    public function test_activation_failure_restores_the_previous_release_and_configuration(string $failure): void
    {
        $first = $this->host->deploy();
        $site = file_get_contents($first.'/.deployment/nginx.conf');
        $this->host->config['redirectHosts'] = [];
        $failed = false;
        $this->host->failure = function (array $command) use ($failure, &$failed): void {
            $matches = match ($failure) {
                'nginx' => basename($command[0]) === 'nginx',
                'migration' => ($command[2] ?? '') === 'migrate',
                default => basename($command[0]) === 'curl',
            };
            // Skip the preflight Nginx check; fail once during activation.
            if ($failure === 'nginx' && $this->host->clones < 2) {
                return;
            }
            if ($matches && ! $failed) {
                $failed = true;
                throw new RuntimeException('Injected activation failure');
            }
        };
        if ($failure === 'redirect') {
            $this->host->httpStatus = '302';
            $this->host->failure = function (array $command): void {
                if (basename($command[0]) === 'curl') {
                    $this->host->httpStatus = '200'; // Next request is recovery.
                }
            };
        } elseif ($failure === 'wrong-page') {
            $this->host->homepage = '<title>Another app</title>';
            $this->host->failure = function (array $command): void {
                if (basename($command[0]) === 'curl' && str_ends_with(end($command), '/')) {
                    $this->host->homepage = "<title>Hayley O'Kelly | CV</title>";
                }
            };
        }
        try {
            $this->host->deploy();
            $this->fail('Expected activation rejection');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('previous state was restored', $exception->getMessage());
            $this->assertSame($first, readlink($this->host->config['baseDir'].'/current'));
            $this->assertSame($site, file_get_contents($this->host->root.'/etc/nginx/sites-available/hayley-website.conf'));
            $this->assertFileDoesNotExist($this->host->config['baseDir'].'/.deployment/transaction.json');
        }
    }

    public function test_failed_first_activation_removes_site_and_symlinks(): void
    {
        $this->host->httpStatus = '503';
        try {
            $this->host->deploy();
            $this->fail('Expected health rejection');
        } catch (RuntimeException $exception) {
            $this->assertFalse(is_link($this->host->config['baseDir'].'/current'));
            $this->assertFalse(is_link($this->host->root.'/etc/nginx/sites-enabled/hayley-website.conf'));
            $this->assertFileDoesNotExist($this->host->root.'/etc/cron.d/hayley-website');
        }
    }

    public function test_failed_recovery_retains_journal_and_can_be_retried(): void
    {
        $first = $this->host->deploy();
        $this->host->httpStatus = '503';
        try {
            $this->host->deploy();
            $this->fail('Expected recovery rejection');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Recovery also failed', $exception->getMessage());
        }
        $journal = $this->host->config['baseDir'].'/.deployment/transaction.json';
        $this->assertFileExists($journal);
        try {
            $this->host->deployment()->run('deploy');
            $this->fail('Expected journal guard');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('needs recovery', $exception->getMessage());
        }
        $this->host->httpStatus = '200';
        $this->host->deployment()->run('rollback');
        $this->assertSame($first, readlink($this->host->config['baseDir'].'/current'));
        $this->assertFileDoesNotExist($journal);
    }

    public function test_app_lock_blocks_concurrent_operations(): void
    {
        $lock = fopen($this->host->config['baseDir'].'/.deploy.lock', 'c');
        flock($lock, LOCK_EX);
        try {
            $this->host->deployment()->run('check');
            $this->fail('Expected lock rejection');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('holds the app lock', $exception->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function test_retention_keeps_current_previous_and_unmanaged_directories(): void
    {
        $this->host->config['keepReleases'] = 2;
        $first = $this->host->deploy();
        $second = $this->host->deploy();
        $unmanaged = dirname($first).'/unmanaged';
        mkdir($unmanaged);
        $third = $this->host->deploy();
        $this->assertDirectoryDoesNotExist($first);
        $this->assertDirectoryExists($second);
        $this->assertDirectoryExists($third);
        $this->assertDirectoryExists($unmanaged);
        $this->host->deployment()->run('releases');
        $this->assertStringContainsString(basename($third), ob_get_contents());
    }

    public function test_rollback_rejects_unmanaged_release(): void
    {
        $this->expectExceptionMessage('Select a retained managed release');
        $this->host->deployment()->run('rollback', release: '../outside');
    }

    public function test_numeric_node_selector_is_required(): void
    {
        $this->host->nodeSelection = 'lts/*';
        $this->expectExceptionMessage('numeric Node version');
        $this->host->deploy();
    }

    public function test_runtime_identity_is_enforced(): void
    {
        $this->host->identity = 'root';
        $this->expectExceptionMessage('configured deploy user');
        $this->host->deployment()->run('setup');
    }

    public function test_setup_bundles_safe_defaults_and_canonical_url(): void
    {
        $this->host->deployment()->run('setup');
        $example = file_get_contents($this->host->config['baseDir'].'/.deployment/environment.example');
        $this->assertStringContainsString('APP_URL=https://hayleyokelly.com', $example);
        $this->assertStringContainsString('QUEUE_CONNECTION=sync', $example);
        $this->assertStringContainsString("'SESSION_SECURE_COOKIE' => 'true'", file_get_contents(base_path('.meta/deployment/setup.sh')));
    }

    public static function unsafeEnvironments(): array
    {
        return [
            ['QUEUE_CONNECTION', 'redis'], ['SESSION_CONNECTION', 'default'],
            ['REDIS_HOST', '127.0.0.1'], ['REDIS_PORT', '6379'], ['SESSION_SECURE_COOKIE', 'false'],
            ['REDIS_URL', 'redis://another-app'], ['APP_DEBUG', 'true'],
        ];
    }

    #[DataProvider('unsafeEnvironments')]
    public function test_preflight_rejects_unsafe_environment(string $key, string $value): void
    {
        $path = $this->host->config['baseDir'].'/.env';
        $contents = file_get_contents($path);
        $contents = preg_replace('/^'.preg_quote($key, '/').'=.*$/m', $key.'='.$value, $contents, count: $count);
        file_put_contents($path, $contents.($count === 0 ? $key.'='.$value."\n" : ''));
        $this->expectException(RuntimeException::class);
        $this->host->deployment()->run('check');
    }

    public function test_build_lock_is_held_during_npm_execution(): void
    {
        $checked = false;
        $this->host->failure = function (array $command) use (&$checked): void {
            if (basename($command[0]) === 'npm') {
                $lock = fopen($this->host->config['buildLock'], 'c');
                $this->assertFalse(flock($lock, LOCK_EX | LOCK_NB));
                fclose($lock);
                $checked = true;
            }
        };
        $this->host->deploy();
        $this->assertTrue($checked);
    }

    public function test_rollback_restores_saved_php_runtime_and_site_configuration(): void
    {
        $first = $this->host->deploy();
        $this->host->config['phpVersion'] = '8.5';
        $this->host->config['phpBinary'] = $this->host->root.'/bin/php8.5';
        $this->host->config['fpmSocket'] = $this->host->root.'/php8.5.sock';
        $this->host->deploy();
        $this->host->calls = [];
        $this->host->deployment()->run('rollback', release: basename($first));
        $this->assertStringContainsString('php8.4.sock', file_get_contents($this->host->root.'/etc/nginx/sites-available/hayley-website.conf'));
        $reloads = array_filter($this->host->calls, fn (array $call): bool => in_array('php8.4-fpm', $call['arguments'], true));
        $this->assertNotEmpty($reloads);
    }

    public function test_rendered_nginx_configuration_is_valid(): void
    {
        if (! is_executable('/usr/sbin/nginx')) {
            if (getenv('CI') === 'true') {
                $this->fail('Install Ubuntu Nginx for configuration validation');
            }
            $this->markTestSkipped('Ubuntu Nginx is validated in CI');
        }
        $release = $this->host->deploy();
        $certificate = $this->host->config['certificate'];
        $key = $this->host->config['privateKey'];
        Process::run(['openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes',
            '-keyout', $key, '-out', $certificate, '-days', '1', '-subj', '/CN=hayleyokelly.com'])->throw();
        $site = file_get_contents($release.'/.deployment/nginx.conf');
        $this->assertStringContainsString('listen 80;', $site);
        $this->assertStringContainsString('listen 443 ssl http2;', $site);
        // Validate the real template as an unprivileged CI user on loopback ports.
        $site = strtr($site, [
            'listen 80;' => 'listen 127.0.0.1:18080;',
            'listen [::]:80;' => 'listen [::1]:18080;',
            'listen 443 ssl http2;' => 'listen 127.0.0.1:18443 ssl http2;',
            'listen [::]:443 ssl http2;' => 'listen [::1]:18443 ssl http2;',
            'include fastcgi_params;' => 'include /etc/nginx/fastcgi_params;',
        ]);
        file_put_contents($this->host->root.'/site.conf', $site);
        $root = $this->host->root;
        file_put_contents($root.'/nginx.conf', "pid $root/nginx.pid;\nerror_log stderr;\nevents {}\nhttp {\naccess_log off;\nclient_body_temp_path $root/body;\nproxy_temp_path $root/proxy;\nfastcgi_temp_path $root/fastcgi;\nuwsgi_temp_path $root/uwsgi;\nscgi_temp_path $root/scgi;\ninclude $root/site.conf;\n}\n");
        $result = Process::run(['/usr/sbin/nginx', '-t', '-p', $root, '-c', $root.'/nginx.conf']);
        $this->assertTrue($result->successful(), $result->errorOutput());
    }
}
