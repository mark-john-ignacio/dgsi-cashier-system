# Deploying to Coolify

1. Push this repo to a Git remote Coolify can reach (GitHub/Gitea).
2. In Coolify: **New Resource → Application → Dockerfile** build pack, pick this repo/branch.
3. **Set "Ports Exposes" to `8080`.** The `serversideup/php:*-fpm-nginx` base image runs
   unprivileged, so nginx listens on 8080, not 80. Leaving it at 80 yields a dead proxy.
4. Add a **MySQL 8** resource; note the internal connection details. Coolify's internal
   hostname for the database is its resource UUID, which is what `DB_HOST` must be set to.
5. Application environment variables:
   - APP_ENV=production, APP_DEBUG=false, APP_URL=https://<your-domain>
   - APP_KEY= (run `php artisan key:generate --show` locally, paste result)
   - DB_CONNECTION=mysql, DB_HOST=<coolify mysql resource uuid>, DB_PORT=3306,
     DB_DATABASE=dgsi, DB_USERNAME=..., DB_PASSWORD=...
   - ADMIN_EMAIL / ADMIN_PASSWORD (first admin login; change the password in-app after first login)
   - SESSION_DRIVER=database, CACHE_STORE=database, QUEUE_CONNECTION=sync
   - LOG_CHANNEL=stderr (so `docker logs` shows application errors)
6. Post-deployment command (Coolify app settings). **Production:**
   `php artisan migrate --force && php artisan db:seed --force && php artisan config:cache && php artisan route:cache`

   `db:seed` runs `DatabaseSeeder` — the admin user and base fee types only. It is idempotent.
   Never point production at `DemoDataSeeder`.
7. Attach your domain; Coolify provisions HTTPS automatically.
8. **Backups (non-negotiable):** in the Coolify MySQL resource, enable Scheduled Backups
   (daily, keep 14). Also schedule an off-VM copy (e.g. S3-compatible storage in Coolify's
   backup settings) — one copy on one VM is not a backup strategy for money records.
9. Smoke test: log in as admin, create the school year + fee structures, add one real
   student, record a test payment, void it (reason: "deployment smoke test"), check the
   daily report shows both, then check the automatic backup ran.

## Why the demo seeder avoids model factories

The image is built with `composer install --no-dev`, which omits `fakerphp/faker`. Model
factories call `fake()`, so anything that touches `Model::factory()` fatals at runtime in a
deployed container even though the test suite passes locally. `DemoDataSeeder` therefore
builds its rows by hand. If you add seed data, do not reach for a factory.

## Staging

A staging deployment lives in the `staging` environment of the
**School Cashier System - Dei Gratia** project, at https://dgsi-staging.markjohnignacio.com,
tracking branch `feat/cashier-mvp-implementation`.

Its post-deployment command seeds demo data and is safe to re-run:

```
php artisan migrate --force && php artisan db:seed --class=DemoDataSeeder --force
```

`DemoDataSeeder` is idempotent — once students exist it returns without touching them, so a
redeploy will not duplicate students or collide on an OR number.

Staging logins are throwaway demo credentials (`cashier@dgsi.local` / `password`). Do not
reuse them anywhere real, and do not treat staging as private.
