# Deploying to Coolify

1. Push this repo to a Git remote Coolify can reach (GitHub/Gitea).
2. In Coolify: **New Resource → Application → Dockerfile** build pack, pick this repo/branch.
3. Add a **MySQL 8** resource; note the internal connection details.
4. Application environment variables:
   - APP_ENV=production, APP_DEBUG=false, APP_URL=https://<your-domain>
   - APP_KEY= (run `php artisan key:generate --show` locally, paste result)
   - DB_CONNECTION=mysql, DB_HOST=<coolify mysql host>, DB_PORT=3306,
     DB_DATABASE=dgsi, DB_USERNAME=..., DB_PASSWORD=...
   - ADMIN_EMAIL / ADMIN_PASSWORD (first admin login; change the password in-app after first login)
   - SESSION_DRIVER=database, CACHE_STORE=database, QUEUE_CONNECTION=sync
5. Post-deployment command (Coolify app settings):
   `php artisan migrate --force && php artisan db:seed --force && php artisan config:cache && php artisan route:cache`
6. Attach your domain; Coolify provisions HTTPS automatically.
7. **Backups (non-negotiable):** in the Coolify MySQL resource, enable Scheduled Backups
   (daily, keep 14). Also schedule an off-VM copy (e.g. S3-compatible storage in Coolify's
   backup settings) — one copy on one VM is not a backup strategy for money records.
8. Smoke test: log in as admin, create the school year + fee structures, add one real
   student, record a test payment, void it (reason: "deployment smoke test"), check the
   daily report shows both, then check the automatic backup ran.
