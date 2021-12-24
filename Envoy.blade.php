@setup

$hostMap = [
'production' => 'hayleyokelly.com',
'staging' => 'hayley.hardycode.com.au',
];

if(is_null($dest)) {
$host = $hostMap['staging'];
} else {
$host = $hostMap[$dest];
}

if($uc) {
$branch = exec('git rev-parse --abbrev-ref HEAD');
}

$servers = ['switchblade.aaronheath.io'];
$user = $user ?: 'aaronheath';
$project = $project ?: 'hayley-website';
$branch = $branch ?: 'master';
$repository = "aaronheath/{$project}";
$baseDir = $path ?: "/var/www/{$host}";
$releasesDir = "{$baseDir}/releases";
$currentDir = "{$baseDir}/current";
$newReleaseName = date('Y_m_d_H_i_s');
$newReleaseDir = "{$releasesDir}/{$newReleaseName}";

function logMessage($message) {
return "echo '\033[32m" .$message. "\033[0m';\n";
}
@endsetup

@servers($servers)

@macro('deploy')
clone repository
run composer
update config
perform migrations
optimise installation
update symlinks
build js
update permissions
link new release
mark release
bless new release
clean old releases
@endmacro

@task('clone repository')
{{ logMessage('start clone repository') }}
[ -d {{ $releasesDir }} ] || mkdir {{ $releasesDir }}
cd {{ $releasesDir }}

# Create the release dir
mkdir {{ $newReleaseDir }}

# Clone the repo
git clone --depth 1 --single-branch -b {{ $branch }} github-hayley-website:{{ $repository }} {{ $newReleaseName }}

# Configure sparse checkout
cd {{ $newReleaseDir }}
git config core.sparsecheckout true
echo "*" > .git/info/sparse-checkout
echo "!storage" >> .git/info/sparse-checkout
echo "!public/css" >> .git/info/sparse-checkout
echo "!public/js" >> .git/info/sparse-checkout
git read-tree -mu HEAD
@endtask

@task('mark release')
# Mark release
cd {{ $currentDir }}
git rev-parse HEAD | tr -d '\n' > VERSION
@endtask

@task('run composer')
{{ logMessage('start run composer') }}
cd {{ $newReleaseDir }}
composer install --prefer-dist --no-scripts -q -o
@endtask

@task('update symlinks')
{{ logMessage('start update symlinks') }}
# Remove the storage directory and replace with persistent data
rm -rf {{ $newReleaseDir }}/storage
cd {{ $newReleaseDir }}
ln -nfs {{ $baseDir }}/storage storage
#ln -nfs {{ $baseDir }}/telescope.sqlite database

#cp {{ $baseDir }}/webapps/standard.config.js {{ $newReleaseDir }}/resources/assets/js/standard/config.js
@endtask

@task('update config')
{{ logMessage('start linking .env config') }}
cd {{ $newReleaseDir }}
ln -nfs {{ $baseDir }}/.env .env
#ln -nfs {{ $baseDir }}/.npmrc .npmrc
@endtask

@task('perform migrations')
{{ logMessage('start migrations') }}
cd {{ $newReleaseDir }}
#php artisan migrate --force
@endtask

@task('optimise installation')
{{ logMessage('start optimise installation') }}
cd {{ $newReleaseDir }};
php artisan clear-compiled
@endtask

@task('build js')
{{ logMessage('start building js') }}
cd {{ $newReleaseDir }};
#mkdir -p public/docs
npm ci
npm run production
#npm run production-docs
rm -rf node_modules
@endtask

@task('update permissions')
{{ logMessage('start updatePermissions') }}

chgrp -R www-data {{ $newReleaseDir }}
find {{ $newReleaseDir }} -type d -exec chmod 775 {} \;
find {{ $newReleaseDir }} -type d -exec chmod g+s {} \;
find {{ $newReleaseDir }} -type f -exec chmod 664 {} \;
chmod 777 {{ $newReleaseDir }}/bootstrap/cache
@endtask

@task('link new release')
{{ logMessage('start link new release') }}
ln -nfs {{ $newReleaseDir }} {{ $currentDir }}
@endtask

@task('bless new release')
{{ logMessage('start bless new release') }}
cd {{ $currentDir }}
rm -rf bootstrap/cache/*
php artisan storage:link
php artisan view:clear
php artisan config:cache
php artisan route:cache
php artisan queue:restart

chmod 664 {{ $newReleaseDir }}/bootstrap/cache/*

sudo systemctl reload php8.0-fpm
#sudo php artisan horizon:terminate
@endtask

@task('clean old releases')
{{ logMessage('start clean old releases') }}
# Delete all but the 3 most recent.
cd {{ $releasesDir }}
export RELEASE_PATH={{ $releasesDir }}
#sudo -E /usr/bin/env bash {{ $newReleaseDir }}/infrastructure/cleanup.sh
@endtask
