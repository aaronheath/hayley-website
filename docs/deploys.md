# Deploying Hayley's website to elland-road

The repository supports the existing Blade/Laravel Mix application on elland-road. Deployment is adapted from [Cricket Loop revision 2fbc3f9](https://github.com/Heath-Solutions/cricket-loop/tree/2fbc3f9113dae8c472ea2bb238322ed66dbc5954), following the host's `new-laravel-application.md` guide. It does not install Horizon, Inertia SSR, background workers, or app Supervisor programs. Queues remain synchronous. The cron job invokes the existing, currently empty scheduler every minute.

| Setting | Value |
| --- | --- |
| Host | `deploy@85.155.189.223` (elland-road), host contract version 1 |
| Repository / default branch | `git@github.com:aaronheath/hayley-website.git` / `master` |
| Canonical domain / redirect | `hayleyokelly.com` / `www.hayleyokelly.com` |
| App / runtime account | `hayley-website` / `app-hayley-website` |
| Base / active release | `/var/www/hayley-website` / `/var/www/hayley-website/current` |
| Database / database account | `hayley_website` / `hayley_website` at `127.0.0.1` |
| PHP / Node | `/usr/bin/php8.4` / installed NVM version selected by `.nvmrc` (`22`) |
| FPM socket | `/run/php/php8.4-hayley-website.sock` |
| Repository SSH key | `/var/lib/laravel-deploy/keys/hayley-website` |
| Valkey / Memcached sockets | `/run/valkey-hayley-website/valkey.sock` / `/run/memcached-hayley-website/memcached.sock` |
| Resource limits | 4 FPM children, 256 MB PHP, 256 MB Valkey, 64 MB Memcached |
| Retention | 5 successful releases, protecting `current` and `previous` |

Only repository changes are automated by this PR. Host setup, certificate installation, production secrets, data transfers, DNS changes, backups, and monitoring are rollout operations below. Do not run production provisioning tests, re-provision the host, or reboot it as part of this application migration; other apps share it.

## Validate the reviewed revision

Use a trusted tooling checkout outside managed release directories with PHP 8.4, Node 22, and development Composer dependencies. All five Envoy tasks can also run in a separate tooling checkout on the host as `deploy` using `--local=1`.

```sh
composer install --no-interaction --prefer-dist
composer validate --strict --no-check-publish
composer check-platform-reqs
npm ci --include=dev
npm run production
vendor/bin/phpunit --do-not-cache-result
bash -n .meta/deployment/setup.sh
shellcheck .meta/deployment/setup.sh
vendor/bin/envoy run --pretend deploy --branch=master
```

Envoy built-in options go before custom options. Its pretend mode prints the script and exits with status 1; it does not deploy. CI additionally validates all Envoy scripts, real Ubuntu Nginx configuration, fresh MariaDB migrations, and a clean production install without development PHP packages. The Redis integration test starts its own disposable Unix-socket server, never connecting to an existing Redis instance.

Use `--branch=20261005-elland-road-deployments` if intentionally testing this reviewed remote branch before it is merged. Always specify the selected remote branch. Record its SHA, prevent it advancing during rollout, and keep the tooling checkout on the same revision: tooling supplies the deployment implementation/templates, while the host clones application code from the remote branch. There is no pinned `--sha` deployment option.

Locked `nette/schema` was upgraded from 1.3.0 to 1.3.6 to allow PHP 8.4 installation. Envoy is tooling-only. Existing dependency advisories, the old PHPUnit XML schema, and existing dependency deprecations are not resolved by this deployment change; assess dependency maintenance separately before cutover.

## Prepare application resources

First verify the existing host and that the application identity is unused:

```sh
ssh deploy@85.155.189.223 'sudo -n true && sudo /usr/local/sbin/laravel-host-provision check'
```

Confirm there is no unrelated `hayley-website` account/database/site or managed base directory. Check host capacity before adding its resource budgets. No SSR port is allocated. Run setup from the reviewed tooling checkout:

```sh
vendor/bin/envoy run setup
```

Setup creates the locked runtime account, isolated database and cache services, protected `.env`, shared storage, repository key, PHP FPM pool, and scheduler log rotation. It is safe to rerun for the same identity and preserves existing secrets/cache settings. Add the printed **public** key to this repository's GitHub Deploy keys with write access disabled. Keep `/etc/laravel-host/github_known_hosts`; do not use personal keys or disable SSH host verification.

Review the environment privately:

```sh
ssh -t deploy@85.155.189.223 'sudoedit /var/www/hayley-website/.env'
```

Preserve the generated database password and application key unless deliberately migrating the existing site's application key. Verify these nonsecret settings:

```dotenv
APP_NAME="Hayley Website"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://hayleyokelly.com
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hayley_website
DB_USERNAME=hayley_website
QUEUE_CONNECTION=sync
CACHE_STORE=redis
SESSION_DRIVER=redis
SESSION_CONNECTION=sessions
SESSION_SECURE_COOKIE=true
SESSION_DOMAIN=null
REDIS_CLIENT=phpredis
REDIS_HOST=/run/valkey-hayley-website/valkey.sock
REDIS_PORT=0
REDIS_DB=0
REDIS_CACHE_DB=1
REDIS_SESSION_DB=2
REDIS_CACHE_LOCK_CONNECTION=cache
MEMCACHED_HOST=/run/memcached-hayley-website/memcached.sock
MEMCACHED_PORT=0
LOG_CHANNEL=stack
LOG_STACK=daily
LOG_LEVEL=warning
```

Remove conflicting `DATABASE_URL`, `DB_URL`, and `REDIS_URL` overrides. Redis database 0 remains available for future app use; cache and locks use 1, sessions use 2. Check privately any real mail/storage/integration settings. Confirm `.env` is `deploy:app-hayley-website`, mode `0640`. Setup preserves existing `.env`; later example-file changes do not update production. Activate environment changes through deployment so cached configuration is rebuilt.

## Preserve the existing site before cutover

Take a verified backup of the current Ploi site's database, shared storage, and environment before transferring anything. The Ploi GitHub Actions trigger has been removed; the old host can remain available during migration.

- Transfer any required production database data into the isolated `hayley_website` database using a reviewed private import procedure. Retain the newly provisioned database account/password; do not copy old database credentials wholesale. Do not run seeders or destructive `migrate:fresh` against production.
- Preserve the old `APP_KEY` privately when migrating encrypted data or other state that depends on it. Never regenerate the key after migration. Plan for users' existing sessions to expire when moving to the private Valkey session store.
- Transfer durable files into `/var/www/hayley-website/storage`, including `app/public/cv/hayley-okelly-resume-20230802-001.pdf`, which the homepage links to. Restore runtime-group ownership, writable shared-storage permissions, and Nginx public-read ACLs; rerun setup to apply managed directory permissions.
- Confirm committed `public/img/leo` images remain in the release. No business-data seeding or admin-account creation is required to render this site.
- Keep database migrations and shared-environment changes compatible with retained releases. Rollback restores code/runtime/site configuration, not database changes or shared secrets/storage. The previous Ploi deployment is not an Envoy-managed rollback target.

## Install TLS and deploy before moving DNS

Create a matching Cloudflare Origin CA certificate/key covering both `hayleyokelly.com` and `www.hayleyokelly.com`. Install the certificate at `/etc/ssl/cloudflare/hayleyokelly.com.pem` as root, mode `0644`, and its private key at `/etc/ssl/cloudflare/hayleyokelly.com.key`, mode `0600`. Use authenticated private transfer; never commit the files. Verify issuer trust using the existing shared `/etc/ssl/cloudflare/origin-ca.pem`, expiry, SANs, and matching public-key digests without printing the private key. Do not replace the shared CA bundle in a way that breaks another application.

```sh
vendor/bin/envoy run check
git ls-remote origin refs/heads/master
vendor/bin/envoy run deploy --branch=master
```

Deployment installs production PHP dependencies with scripts disabled, explicitly discovers packages, resolves Node from NVM, runs locked npm installation including build dependencies, and executes `npm run production`. It validates `public/mix-manifest.json`, `public/css/app.css`, and `public/js/app.js`, caches the app with a release-specific writable view directory, runs migrations as the runtime user, and atomically switches `current`. Health checks require trusted loopback HTTP 200 from `/up` and `/`, with the expected homepage title. There is no SSR or worker health check.

The generated Nginx site is `/etc/nginx/sites-available/hayley-website.conf`; cron is `/etc/cron.d/hayley-website`. Mix URLs remain `/css/app.css?id=...` and `/js/app.js?id=...`. Nginx falls back to `previous/public` when an asset path is missing in `current`. Query-string hashes do **not** preserve older bytes when the same path exists in the new release; a page open across deployment may fetch the newer asset. No immutable per-release asset URLs are introduced.

Verify on the host before DNS cutover:

```sh
ssh deploy@85.155.189.223
cat /var/www/hayley-website/current/VERSION
sudo systemctl is-active php8.4-fpm laravel-valkey@hayley-website laravel-memcached@hayley-website
cd /var/www/hayley-website/current
sudo -u app-hayley-website /usr/bin/php8.4 artisan schedule:list
curl --fail --cacert /etc/ssl/cloudflare/origin-ca.pem \
  --resolve hayleyokelly.com:443:127.0.0.1 https://hayleyokelly.com/up
```

Compare `VERSION` to the reviewed remote SHA. Check `/`, CSS/JS and images, `/leo`, and the downloadable CV. Verify both aliases' HTTP and HTTPS redirects preserve paths/queries. The scheduler intentionally has no application tasks yet. Execute manual Artisan commands from `current` as `app-hayley-website` with `/usr/bin/php8.4`; `deploy` cannot access the private Valkey socket. Use Envoy to rebuild cached configuration rather than bare manual `optimize`/`config:cache`, which omit the release-specific view-path override.

After origin checks pass, preserve non-web DNS records, point the canonical **proxied A** record to `85.155.189.223`, set the proxied `www` alias, remove obsolete web AAAA records pointing elsewhere, and use Cloudflare **Full (strict)**. Public web access remains behind Cloudflare; normal browsers do not trust Origin CA certificates directly. Verify public HTTPS, all assets, redirects, `/up`, `/leo`, and the CV after cutover.

## Exercise rollback and recovery

Make two successful deployments, record their identifiers, roll back to the first, and reactivate the final release:

```sh
vendor/bin/envoy run deploy --branch=master
vendor/bin/envoy run releases
vendor/bin/envoy run rollback --release=FIRST_SUCCESSFUL_RELEASE
vendor/bin/envoy run rollback --release=FINAL_SUCCESSFUL_RELEASE
```

Substitute actual identifiers. Verify content, CV, shared storage, database, application key and credentials survive both transitions. A failed activation restores the previous state automatically. If recovery also fails, retain `/var/www/hayley-website/.deployment/transaction.json`, resolve the reported issue, and run `vendor/bin/envoy run rollback` **without** a release argument. Do not manually repoint symlinks or delete the journal.

## Install independent backups and monitoring

Use a new encrypted restic repository prefix `hayley-website` in the private `system-backups` R2 bucket. Prefixes separate organisation, not access control; never reuse another app's repository/password. Privately create root-owned, mode-`0600` `/root/hayley-website-backup.env`:

```dotenv
APP_BASE=/var/www/hayley-website
DATABASE=hayley_website
VALKEY_SOCKET=/run/valkey-hayley-website/valkey.sock
RESTIC_REPOSITORY=s3:https://ACCOUNT_ID.r2.cloudflarestorage.com/system-backups/hayley-website
AWS_ACCESS_KEY_ID=YOUR_ACCESS_KEY_ID
AWS_SECRET_ACCESS_KEY=YOUR_SECRET_ACCESS_KEY
AWS_DEFAULT_REGION=auto
KUMA_BACKUP_PUSH_URL=
KUMA_MAINTENANCE_PUSH_URL=
```

Replace placeholders privately. After a successful release:

```sh
sudo /usr/local/sbin/laravel-host-backup install hayley-website /root/hayley-website-backup.env
sudo /usr/local/sbin/laravel-host-backup init hayley-website
sudo /usr/local/sbin/laravel-host-backup backup hayley-website
sudo /usr/local/sbin/laravel-host-backup maintenance hayley-website
sudo systemctl list-timers 'laravel-*@hayley-website.timer'
```

Run `init` only for a new empty repository. The host policy runs backups daily at 05:30 Australia/Adelaide and checks/pruning Sunday at 06:30, retaining 7 daily, 4 weekly, and 6 monthly snapshots. Review cross-app capacity and scheduling. Copy `/etc/laravel-host/backups/hayley-website.password` over authenticated SSH into durable protected off-host storage with mode `0600`. R2 credentials alone cannot decrypt backups. Record a successful snapshot and test restoration into temporary files, a separate database, and an isolated Valkey instance; verify revision, shared environment/storage, CV, and permissions. Remove only test resources. Backups are individually consistent dumps, not a single atomic database/queue/file transaction.

Create external HTTPS and `/up` checks, homepage-content checks, backup/maintenance push monitors, and disk/memory/failed-service alerts. Test notifications and missed-run alerts. Monitor Origin CA expiry independently from Cloudflare's edge certificate. Record a separate-host recovery drill and any additional durable-data paths.

## Acceptance record

Record reviewed/deployed SHA, PHP/Node versions, CI results, origin/public HTTPS and asset checks, redirects, `/leo`, CV transfer, scheduler/service health, two deployments and rollback, persistent data/secret verification, backup snapshot and isolated restore, and monitoring alerts. List outstanding work explicitly. Host service/firewall persistence across a reboot must be verified during separately agreed maintenance because it affects every hosted app.
