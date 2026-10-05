#!/usr/bin/env bash
# Invoked by Envoy through sudo; arguments contain configuration, never secrets.
set -Eeuo pipefail
umask 027
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin

[[ $EUID == 0 && $# == 10 ]] || { echo 'Run app provisioning through Envoy setup.' >&2; exit 1; }
action=$1 app=$2 deploy_user=$3 base=$4 php_version=$5 system_root=$6 children=$7 php_memory=$8 valkey_memory=$9 memcached_memory=${10}
[[ $action == setup || $action == pool ]] || exit 1
[[ $app =~ ^[a-z][a-z0-9-]{0,26}$ && $deploy_user =~ ^[a-z][a-z0-9_-]*$ && $php_version =~ ^8\.[2345]$ ]] || exit 1
for directory in "$base" "$system_root"; do
    [[ $directory =~ ^/[a-zA-Z0-9/_.-]+$ && $directory != *'/../'* && ! -L $directory ]] || exit 1
done
for value in "$children" "$php_memory" "$valkey_memory" "$memcached_memory"; do
    [[ $value =~ ^[1-9][0-9]{0,4}$ ]] || exit 1
done
[[ $(cat "$system_root/laravel-host/contract-version") == 1 ]] || { echo 'Provision host contract v1 first.' >&2; exit 1; }
runtime_user="app-$app"
php_binary="/usr/bin/php$php_version"
app_config="$system_root/laravel-host/apps/$app"
work=$(mktemp -d)
trap 'rm -rf -- "$work"' EXIT

if [[ $action == setup ]]; then
    if ! id "$runtime_user" >/dev/null 2>&1; then
        useradd --system --user-group --home-dir "$base" --no-create-home --shell /usr/sbin/nologin "$runtime_user"
    fi
    [[ $(getent passwd "$runtime_user" | cut -d: -f6-7) == "$base:/usr/sbin/nologin" ]] || { echo 'Runtime account has unexpected home or shell.' >&2; exit 1; }
    passwd --lock "$runtime_user" >/dev/null
    install -d -o "$deploy_user" -g "$runtime_user" -m 2750 "$base" "$base/releases" "$base/.deployment"
    install -d -o root -g "$runtime_user" -m 0750 "$app_config"
    # Values stay inside PHP and the protected environment file, never shell arguments or output.
    "$php_binary" -- "$base" "$app" "$deploy_user" "$runtime_user" <<'PHP'
<?php
[$script, $base, $app, $deployUser, $runtimeUser] = $argv;
$environmentPath = $base.'/.env';
if (is_link($environmentPath)) {
    fwrite(STDERR, "Shared environment must be a regular file.\n");
    exit(1);
}
$databaseName = str_replace('-', '_', $app);
try {
    if (!is_file($environmentPath)) {
        $environment = file_get_contents($base.'/.deployment/environment.example');
        $values = [
            'APP_ENV' => 'production', 'APP_DEBUG' => 'false',
            'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)),
            'LOG_LEVEL' => 'warning', 'LOG_STACK' => 'daily',
            'DB_CONNECTION' => 'mariadb', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '3306',
            'DB_DATABASE' => $databaseName, 'DB_USERNAME' => $databaseName,
            'DB_PASSWORD' => bin2hex(random_bytes(32)),
            'QUEUE_CONNECTION' => 'sync', 'CACHE_STORE' => 'redis', 'SESSION_DRIVER' => 'redis',
            'SESSION_CONNECTION' => 'sessions', 'SESSION_SECURE_COOKIE' => 'true',
            'REDIS_DB' => '0', 'REDIS_CACHE_DB' => '1', 'REDIS_SESSION_DB' => '2',
            'REDIS_CACHE_LOCK_CONNECTION' => 'cache',
            'REDIS_CLIENT' => 'phpredis', 'REDIS_HOST' => '/run/valkey-'.$app.'/valkey.sock', 'REDIS_PORT' => '0',
            'MEMCACHED_HOST' => '/run/memcached-'.$app.'/memcached.sock', 'MEMCACHED_PORT' => '0',
        ];
        foreach ($values as $key => $value) {
            $line = $key.'="'.$value.'"';
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $environment = preg_match($pattern, $environment) ? preg_replace($pattern, $line, $environment) : $environment."\n".$line;
        }
        $temporary = $environmentPath.'.initial';
        file_put_contents($temporary, rtrim($environment)."\n");
        chmod($temporary, 0640);
        chown($temporary, $deployUser);
        chgrp($temporary, $runtimeUser);
        rename($temporary, $environmentPath);
    }
    $environment = parse_ini_file($environmentPath, false, INI_SCANNER_RAW);
    if (!$environment || ($environment['DB_DATABASE'] ?? '') !== $databaseName || ($environment['DB_USERNAME'] ?? '') !== $databaseName || empty($environment['DB_PASSWORD'])) {
        throw new RuntimeException('Database settings do not match the app.');
    }
    $admin = new PDO('mysql:unix_socket=/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $password = $admin->quote($environment['DB_PASSWORD']);
    $admin->exec("CREATE DATABASE IF NOT EXISTS `$databaseName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $admin->exec("CREATE USER IF NOT EXISTS '$databaseName'@'127.0.0.1' IDENTIFIED BY $password");
    $admin->exec("GRANT ALL PRIVILEGES ON `$databaseName`.* TO '$databaseName'@'127.0.0.1'");
    new PDO('mysql:host=127.0.0.1;dbname='.$databaseName, $databaseName, $environment['DB_PASSWORD'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    chmod($environmentPath, 0640);
    chown($environmentPath, $deployUser);
    chgrp($environmentPath, $runtimeUser);
} catch (Throwable $exception) {
    fwrite(STDERR, "App database/environment provisioning failed. Check the protected .env and MariaDB using sudo. Existing passwords were not changed.\n");
    exit(1);
}
PHP
    install -d -o "$deploy_user" -g "$deploy_user" -m 0700 /var/lib/laravel-deploy/keys
    key="/var/lib/laravel-deploy/keys/$app"
    if [[ ! -f $key ]]; then
        runuser -u "$deploy_user" -- ssh-keygen -q -t ed25519 -N '' -C "$app deploy key" -f "$key"
    fi
    [[ ! -L $key ]] || exit 1
    chmod 0600 "$key"
    printf '\nRegister this public key as a read-only GitHub deploy key:\n'
    ssh-keygen -y -f "$key"

    cat > "$work/valkey.conf" <<EOF
port 0
unixsocket /run/valkey-$app/valkey.sock
unixsocketperm 600
daemonize no
supervised no
logfile ""
dir /var/lib/valkey-$app
appendonly yes
appendfsync everysec
maxmemory ${valkey_memory}mb
maxmemory-policy noeviction
save ""
EOF
    printf 'MEMORY_MB=%s\n' "$memcached_memory" > "$work/memcached.env"
    for name in valkey.conf memcached.env; do
        # Operator tuning survives setup reruns. Edit and restart these app services deliberately.
        if [[ ! -e $app_config/$name ]]; then
            install -o root -g "$runtime_user" -m 0640 "$work/$name" "$app_config/$name"
        fi
    done
    systemctl enable --now "laravel-valkey@$app" "laravel-memcached@$app"
    systemctl is-active --quiet "laravel-valkey@$app" "laravel-memcached@$app"
    if [[ ! -e $system_root/logrotate.d/laravel-$app ]]; then
        cat > "$work/logrotate" <<EOF
$base/storage/logs/scheduler.log {
    daily
    rotate 14
    compress
    delaycompress
    missingok
    notifempty
    su $runtime_user $runtime_user
    create 0660 $runtime_user $runtime_user
}
EOF
        install -m 0644 "$work/logrotate" "$system_root/logrotate.d/laravel-$app"
    fi
fi

id "$runtime_user" >/dev/null
pool="$system_root/php/$php_version/fpm/pool.d/$app.conf"
cat > "$work/pool.conf" <<EOF
[$app]
user = $runtime_user
group = $runtime_user
listen = /run/php/php$php_version-$app.sock
listen.owner = $runtime_user
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = $children
pm.process_idle_timeout = 10s
pm.max_requests = 500
php_admin_value[memory_limit] = ${php_memory}M
php_admin_value[upload_tmp_dir] = $base/storage/framework/tmp
php_admin_value[sys_temp_dir] = $base/storage/framework/tmp
security.limit_extensions = .php
clear_env = yes
catch_workers_output = yes
EOF
[[ ! -L $pool ]] || exit 1
if [[ ! -f $pool ]] || ! cmp -s "$work/pool.conf" "$pool"; then
    if [[ -f $pool ]]; then cp -p "$pool" "$work/pool.previous"; fi
    install -m 0644 "$work/pool.conf" "$pool.next"
    mv -f "$pool.next" "$pool"
    if ! "/usr/sbin/php-fpm$php_version" --test; then
        if [[ -f $work/pool.previous ]]; then cp -p "$work/pool.previous" "$pool"; else rm -f "$pool"; fi
        echo 'FPM rejected the pool; previous configuration restored.' >&2
        exit 1
    fi
    systemctl reload-or-restart "php$php_version-fpm"
fi
systemctl start "php$php_version-fpm"
systemctl is-active --quiet "php$php_version-fpm"
for ((attempt = 0; attempt < 120; attempt++)); do
    if [[ -S /run/php/php$php_version-$app.sock ]]; then exit 0; fi
    sleep 1
done
echo 'FPM did not create the app socket within 120 seconds; inspect the FPM journal.' >&2
exit 1
