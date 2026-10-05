<?php

namespace Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class RuntimeConfigurationTest extends TestCase
{
    public function test_production_cache_sessions_and_locks_use_an_isolated_unix_socket(): void
    {
        if (! extension_loaded('redis') || ! Process::run(['redis-server', '--version'])->successful()) {
            if (getenv('CI') === 'true') {
                $this->fail('Install redis-server and PhpRedis for runtime integration tests');
            }
            $this->markTestSkipped('Redis runtime integration requires redis-server and PhpRedis');
        }
        $root = realpath(sys_get_temp_dir()).'/hayley-redis-'.bin2hex(random_bytes(4));
        mkdir($root, 0700);
        $socket = $root.'/redis.sock';
        $server = Process::start(['redis-server', '--port', '0', '--unixsocket', $socket,
            '--unixsocketperm', '700', '--dir', $root, '--save', '', '--appendonly', 'no']);
        try {
            for ($attempt = 0; $attempt < 100 && ! file_exists($socket); $attempt++) {
                usleep(10000);
                clearstatcache();
            }
            $this->assertFileExists($socket, $server->errorOutput());
            $code = <<<'CODE'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
            $cache = $app->make('cache')->store();
            $cache->put('deployment-test', 'cache-value', 60);
            $lock = $cache->lock('deployment-lock', 60);
            $acquired = $lock->get();
            $secondLock = $cache->lock('deployment-lock', 60)->get();
            $handler = $app->make('session')->driver()->getHandler();
            $handler->write('deployment-session', 'session-value');
            $redis = $app->make('redis');
            $values = [
                'driver' => config('database.connections.mariadb.driver'),
                'secure' => config('session.secure'),
                'cache' => $cache->get('deployment-test'),
                'session' => $handler->read('deployment-session'),
                'lock' => $acquired && !$secondLock,
                'defaultKeys' => count($redis->connection('default')->keys('*')),
                'cacheKeys' => count($redis->connection('cache')->keys('*')),
                'sessionKeys' => count($redis->connection('sessions')->keys('*')),
                'queue' => config('queue.default'),
                'log' => config('logging.channels.stack.channels'),
                'views' => config('view.compiled'),
            ];
            $lock->release();
            echo json_encode($values, JSON_THROW_ON_ERROR);
            CODE;
            $result = Process::env([
                'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
                'APP_KEY' => 'base64:'.base64_encode(str_repeat('x', 32)),
                'DB_CONNECTION' => 'mariadb', 'QUEUE_CONNECTION' => 'sync',
                'CACHE_STORE' => 'redis', 'SESSION_DRIVER' => 'redis', 'SESSION_CONNECTION' => 'sessions',
                'SESSION_SECURE_COOKIE' => 'true', 'REDIS_CLIENT' => 'phpredis',
                'REDIS_HOST' => $socket, 'REDIS_PORT' => '0', 'REDIS_PASSWORD' => 'null', 'REDIS_URL' => 'null',
                'REDIS_DB' => '0', 'REDIS_CACHE_DB' => '1', 'REDIS_SESSION_DB' => '2',
                'REDIS_CACHE_LOCK_CONNECTION' => 'cache', 'LOG_STACK' => 'daily',
                'VIEW_COMPILED_PATH' => $root,
            ])->run([PHP_BINARY, '-d', 'error_reporting=24575', '-r', $code]);
            $this->assertTrue($result->successful(), $result->errorOutput());
            $values = json_decode($result->output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('mariadb', $values['driver']);
            $this->assertTrue($values['secure']);
            $this->assertSame('cache-value', $values['cache']);
            $this->assertSame('session-value', $values['session']);
            $this->assertTrue($values['lock']);
            $this->assertSame(0, $values['defaultKeys']);
            $this->assertSame(2, $values['cacheKeys']);
            $this->assertSame(1, $values['sessionKeys']);
            $this->assertSame('sync', $values['queue']);
            $this->assertSame(['daily'], $values['log']);
            $this->assertSame($root, $values['views']);
        } finally {
            if ($server->running()) {
                $server->signal(SIGTERM)->wait();
            }
            (new Filesystem)->deleteDirectory($root);
        }
    }

    public function test_legacy_cache_driver_remains_supported(): void
    {
        $result = Process::env(['CACHE_STORE' => false, 'CACHE_DRIVER' => 'array', 'APP_ENV' => 'testing'])
            ->run([PHP_BINARY, '-d', 'error_reporting=24575', '-r', <<<'CODE'
            require 'vendor/autoload.php';
            $app = require 'bootstrap/app.php';
            echo (require 'config/cache.php')['default'];
            CODE]);
        $this->assertTrue($result->successful(), $result->errorOutput());
        $this->assertSame('array', $result->output());
    }
}
