# Deploying Taga to shared hosting

This replaces the previous scattered guides (`HOSTINGER_SETUP_INSTRUCTIONS.md`,
`MANUAL_DEPLOYMENT.md`, `CRON_JOB_SETUP_GUIDE.md`, `UPDATED_CRON_JOBS.md`), which
describe deploy scripts that no longer exist.

## What changed and why

The project used to carry 28 PHP scripts in `public/` — `deploy.php`,
`run-migrations.php`, `process-queue.php` and so on. Each was guarded by a
password written into the file itself, so anyone with a copy of the source could
run migrations or seeders against the live site. A stale copy of `.env` was also
sitting in `public/`, fetchable over HTTP.

They are replaced by **one authenticated endpoint**, `/__ops/{command}`, whose
token lives in `.env` and never in source.

---

## 1. Document root

Point the domain's document root at **`backend/public`**, not `backend`.

This matters more than it sounds. `backend/.htaccess` only rewrites paths that do
not exist on disk, so with `backend` as the web root anything real is served
straight off the filesystem — including `.env` and
`storage/app/private/prescriptions/*.pdf`, which are patient records.

Both `.htaccess` files now carry deny rules as a safety net, but they are a
second line of defence. Getting the document root right removes the problem
instead of mitigating it.

## 2. Environment

In `backend/.env`:

```
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.yourdomain.com
FRONTEND_URL=https://yourdomain.com
ADMIN_URL=https://admin.yourdomain.com
AGENT_PORTAL_URL=https://riders.yourdomain.com
LOGISTICS_PORTAL_URL=https://logistics.yourdomain.com
```

`APP_DEBUG=false` is not optional — debug pages print stack traces containing
database credentials. `APP_ENV=production` also disables `/api-docs` and
`/swagger`, which otherwise enumerate every endpoint.

`FRONTEND_URL` drives the Paystack callback and the password-reset and
email-verification links. Point it at the wrong host and paying customers land
somewhere else after checkout.

## 3. Database

There are no migration files — the schema was squashed into
`database/schema/mysql-schema.sql`. Laravel's own `php artisan migrate` tries to
load that dump by shelling out to the `mysql` client binary, which shared hosting
usually does not have, so it fails on a fresh database.

Use **either**:

**a. phpMyAdmin** — create the database, then import
`database/schema/mysql-schema.sql`. The dump includes the `migrations` table and
its rows, so Laravel will consider the schema current afterwards.

**b. The ops endpoint** (below) — `POST /__ops/install`. It applies the same dump
over the existing PDO connection, no `mysql` binary needed, then runs any
migrations added since.

## 4. The ops endpoint

Generate a token (locally, then copy the value into the server's `.env`):

```bash
php artisan ops:token
```

The endpoint stays **disabled** — every request 404s — until `MAINTENANCE_TOKEN`
is set and at least 32 characters long.

Call it with the token in a header:

```bash
curl -X POST https://api.yourdomain.com/__ops/clear -H "X-Maintenance-Token: YOUR_TOKEN"
```

| Command | Does |
|---|---|
| `install` | Load the SQL schema, then run pending migrations |
| `migrate` | Run pending migrations |
| `migrate-status` | Show which migrations have run |
| `clear` | Clear config, route, view and application caches |
| `cache` | Rebuild config and route caches |
| `storage-link` | Create the `public/storage` symlink |
| `queue` | Process queued jobs until the queue is empty |
| `schedule` | Run any due scheduled tasks |

Properties worth knowing:

- A wrong token returns **404**, not 401, so probing reveals nothing.
- Only the commands above can run. There is no "run any command" option.
- Rate limited to 6 requests a minute per IP; every attempt is logged with its IP.
- Failures log the detail and return a generic message, because exception text
  can contain connection strings.

### Typical deploy sequence

```bash
curl -X POST https://api.yourdomain.com/__ops/install       -H "X-Maintenance-Token: $T"
curl -X POST https://api.yourdomain.com/__ops/storage-link  -H "X-Maintenance-Token: $T"
curl -X POST https://api.yourdomain.com/__ops/clear         -H "X-Maintenance-Token: $T"
curl -X POST https://api.yourdomain.com/__ops/cache         -H "X-Maintenance-Token: $T"
```

## 5. Cron

The old cron jobs pointed at `process-queue.php` and `run-schedule.php`. Their
replacements:

```
*/5 * * * *  curl -s "https://api.yourdomain.com/__ops/queue?token=YOUR_TOKEN"
* * * * *    curl -s "https://api.yourdomain.com/__ops/schedule?token=YOUR_TOKEN"
```

If your host offers real cron with a PHP binary, prefer that — it keeps the token
out of URLs entirely:

```
* * * * *  cd /home/USER/backend && php artisan schedule:run >> /dev/null 2>&1
```

> The endpoint accepts `?token=` for cron services that can only fetch a URL, but
> query strings appear in server access logs. Use the header where you can.

`queue` is bounded (`--stop-when-empty`, 50s cap) because an HTTP request must
not become a daemon.

## 6. Frontends

Each of the four apps builds to static files. Set `VITE_API_URL` at build time
per app, then upload the `dist/` contents:

| App | Build | Upload to |
|---|---|---|
| `frontend` | `npm run build` | main domain |
| `admin` | `npm run build` | admin subdomain |
| `agents` | `npm run build` | riders subdomain |
| `logistics` | `npm run build` | logistics subdomain |

They are single-page apps: the host must rewrite unknown paths to `index.html`,
or deep links will 404.

## 7. Before going live

- [ ] Document root points at `backend/public`
- [ ] `APP_DEBUG=false`, `APP_ENV=production`
- [ ] `MAINTENANCE_TOKEN` set on the server, not committed
- [ ] Confirm `https://yourdomain/.env` returns 403/404
- [ ] Confirm `https://yourdomain/storage/app/private/prescriptions/` is not listable
- [ ] Paystack switched from `pk_test_`/`sk_test_` to live keys
- [ ] **Rotate `APP_KEY`, database password, mail password and Paystack keys** if
      the previous deployment ever served `public/.env` — assume they leaked
- [ ] Terms and Privacy reviewed by a Nigerian lawyer
