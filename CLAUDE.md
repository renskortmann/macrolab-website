# CLAUDE.md

Context for Claude Code sessions on this repository. README.md is the full
manual (architecture, deployment runbook, operations); read it before changing
behaviour. This file records what README.md does not: the live deployment state
and how deployment has been done in practice.

**This file is committed to a public GitHub repo.** Keep account names, IPs,
database names/users, filesystem paths, passwords, keys and tokens out of it.
Put machine-specific private details in `CLAUDE.local.md` (gitignored) or the
user's password manager.

## Project in one paragraph

PHP 8.2+ / MySQL app for the TU Delft Macrolab with two independent systems
behind one sign-in and a hub at `/`: equipment booking at `/booking`, and time
registration at `/time`, where lab technicians log hours per activity
(maintenance, teaching support, tidying the lab, ...) so lab management can see
how their time is spent. The activities are called "projects" in the code. No
framework; PSR-4 `Macrolab\` -> `app/src/`, with `Macrolab\Booking` and
`Macrolab\Time` in their own folders, which never use each other; routes in
`app/routes.php`, plain PHP views in `app/views/`, migrations in
`app/migrations/`. Only
`public_html/index.php` is web-reachable. TU Delft SSO ("stage 2") is NOT
built: routes, columns, setting and config are scaffolding, and
`Settings::authMode()` stays `local` until `Macrolab\Auth\SamlProvider` exists. Composer deps must be installed
without a shell on the server (see "Getting vendor/ onto the server").

- Tests: `composer install && vendor/bin/phpunit` (integration suite is skipped
  unless `MACROLAB_TEST_DB_NAME` is set, see `tests/test-config.php`).
- Local run: see README.md "Local development" (`php -S localhost:8000 -t public_html`).
- `app/config.php` is gitignored and machine-specific. Never commit it or any
  generated key/token.

## Hosting

| | |
|---|---|
| Domain | `macrolab.citg.tudelft.nl` |
| Server | TU Delft shared LAMP hosting (Plesk) |
| Panel | Plesk, reachable only from campus network or eduVPN |
| PHP | 8.2.34, run as "FPM application served by Apache" (so `.htaccess` works) |
| Document root | `public_html` (changed from Plesk's default `httpdocs` in Hosting Settings) |
| Access | Plesk web UI (File Manager, Databases, SSL, Scheduled Tasks, Git). FTP(S). SSH access "Forbidden" (Hosting Settings, not changeable by the subscription; verified 2026-10-03), so no shell. No outbound mail |

## Deployment strategy: Plesk Git, no zip

Decision (2026-10-02): deploy from GitHub with Plesk's Git extension, because
many small changes are expected. The zip bundle approach was abandoned and the
earlier `~/macrolab-deploy.zip` must not be used (it has the old `httpdocs/`
layout and its install token was shown in a chat, so treat it as burnt).

Layout on the server, with the repo deployed to the subscription root `/`:

```
/ (subscription root)
    public_html/   <- document root: index.php, .htaccess, assets/ (from the repo)
    app/           <- from the repo; beside the document root, not web-reachable
    vendor/        <- NOT in git; see below
    app/config.php <- NOT in git; created once on the server
    tests/ docs/ composer.json ...  (harmless, outside the document root)
```

`public_html/index.php` detects `app/` one level above itself, so nothing
needs configuring. Plesk's original `httpdocs/` folder was deleted on
2026-10-04.

Plesk -> Git -> Create repository settings:
- **Remote repository**, URL = the GitHub repo. If it is private, Plesk shows an
  SSH public key after creation: add it as a read-only **deploy key** in GitHub
  and use the SSH URL.
- Repository name: anything unique (Plesk suggests `macrolab.git`).
- **Deployment mode: Manual.** Automatic needs GitHub to call a webhook on the
  Plesk server, which is campus-only and unreachable from GitHub. Click "Pull
  updates" in Plesk (on campus/eduVPN) to deploy.
- **Deployment directory: `/`** (not `/httpdocs`).
- Leave "post deployment actions" off: the README says the hosting does not
  allow shell commands.

The repo was renamed from `luna-booking-system` to `macrolab-website` (planned
2026-10-02, check `git remote -v`); GitHub redirects the old name but never
create a new repo with the old name. Enter the final URL in Plesk.

### Getting vendor/ onto the server: Plesk PHP Composer (works, 2026-10-02)

`vendor/` is gitignored and there is no shell, so Plesk's PHP Composer
extension builds it on the server (domain dashboard -> PHP Composer). Verified
on the first deployment:
- It found `composer.json` in the subscription root ("Folder: /").
- **Mode: Production** installs without dev dependencies (no phpunit).
- **Install** (not Update) used the versions from `composer.lock` and created
  `vendor/` beside `app/`, with `autoload.php` and only the production packages.

After a pull that changes `composer.lock`, run Install again. (Done after the
2026-10-03 deploy that moved classes into `Booking/` and `Time/`.) Never click
Update on the server: it ignores `composer.lock`. Change dependencies locally
with `composer update`, commit the lock file, then pull and Install.

Fallback if the extension ever stops working: a `deploy` branch containing
`vendor/` (`composer install --no-dev --optimize-autoloader`, `git add -f
vendor`, deploy that branch).

### app/config.php (created once on the server)

Not in git, and deployment does not delete untracked files (verified
2026-10-03: after a Pull + Deploy, `config.php` kept its contents and
permissions 600, and `vendor/` stayed; files deleted from the repo were removed
from the server). Create it in Plesk File
Manager by copying `app/config.example.php` to `app/config.php`, then set:
- `app.base_url` = `https://macrolab.citg.tudelft.nl`
- `app.key` and `app.install_token`: run `php app/cli/generate-key.php` locally
  twice and paste the outputs. Never reuse values that appeared in chat.
- `db`: host `localhost`, port `3306`; database name, user and password are in
  the user's password manager (see "Database" below).
- permissions 600.

### PHP environment (verified from phpinfo, 2026-10-02)

phpinfo from Plesk (`docs/PHP 8.2.34 - phpinfo().pdf`, gitignored) was taken
first while PHP ran as "FPM served by nginx", then again after switching to
"FPM application served by Apache". The second shows `SERVER_SOFTWARE =
Apache` (nginx proxies in front), the same PHP version, ini files and
`open_basedir`, and `HTTPS = on`, so `.htaccess` should now be honoured.
That PDF still shows `DOCUMENT_ROOT = .../httpdocs`, so it predates the
document-root change. Its `REMOTE_ADDR`, `X-Real-IP` and `SERVER_ADDR` are all
the server itself, because Plesk's "PHP info" link fetches the page
server-side. So it says nothing about what real visitors' addresses look like
(see go-live step 7):

- PHP 8.2.34, FPM, memory_limit 256M, upload/post 16M, max_execution_time 60.
- All required extensions are loaded: `pdo_mysql`, `mbstring`, `openssl`
  (1.1.1k), `dom`/`xml`/`libxml`, `json`, and `sodium` (libsodium 1.0.18, so
  Argon2id and sodium-based encryption are available). `zip` is also loaded.
- `open_basedir = <subscription folder>/:/tmp/` and `HOME` is that same
  folder, so `{WEBSPACEROOT}` is the subscription folder and PHP can read `app/` and `vendor/` beside
  `public_html/`. If `/install` ever reports an `open_basedir` problem, the
  fallback is the restricted layout in README.md "Fallbacks".
- Default timezone UTC (the app sets its own).
- Sessions: `session.save_path = /var/lib/php/session`, `session.gc_maxlifetime
  = 1440`, `gc_probability = 0`. Plesk's PHP Settings page (checked
  2026-10-03) offers no field for `gc_maxlifetime` and no "additional
  directives" box, so the subscription cannot raise it. Decision (2026-10-03):
  accept it. The idle limits (`auth.*_session_idle_minutes`) are 24 for members
  and the admin, so the app's rule and the host's cleanup agree. The host's
  cleanup is coarse (a session was still alive after 28 idle minutes), so the
  app's own check is what enforces the limit. That check never fired until
  2026-10-03: `Session::start()` refreshed "last seen" on every read, before
  `isAlive()` compared it (fixed; covered by `AuthGateTest`). The server's
  `app/config.php` was created with the old 480/30 values: change them to 24.

### Database (created 2026-10-02)

MariaDB 10.11 at `localhost:3306`, one database plus one user scoped to it,
linked to the site `macrolab.citg.tudelft.nl` in Plesk. Plesk added no name
prefix. Name, user (it contains a hyphen; quote it in config.php) and password
are in the user's password manager. The database is empty until `/install`
runs.

## Deployment status (as of 2026-10-03)

**Live.** `macrolab.citg.tudelft.nl` is a CNAME to the shared hosting server
(ICT created it; the zone is not managed in Plesk). A Let's Encrypt certificate
was issued 2026-10-02 (expires 2026-12-31, Plesk renews it). `/install` ran on
2026-10-03, the administrator signs in with password + TOTP, and
`install_token` is blank again (`/install` answers 503 "Installer not
enabled"). Checked from outside: HTTP redirects to HTTPS; unknown paths get the
app's own 404 (so `.htaccess` routing works); `/assets/app.css` 200;
`/app/config.php` 403; `/login` and `/admin/login` 200; security headers and
the secure session cookie are sent. Plesk's site Preview shows the server's
default page, not this site, so it is no use for testing.

The first member sign-in (2026-10-04) landed on Plesk's "Domain Default
page": Plesk had put an `index.html` into the new `public_html/`, and Apache
served it for `/` (the hub) because `/` is a real directory and so is not
rewritten. Admin sign-in goes to `/admin`, so it went unnoticed. Fixed by
deleting that file and by `DirectoryIndex index.php` in `.htaccess`.

The first install attempt exposed a bug, since fixed: the installer's rate
limit queried `login_attempts` before the installer had created it, so
`/install` failed with a 500 on an empty database (`InstallerTest` covers it).

Done: database created; PHP set to Apache mode; phpinfo verified; document root
`public_html` (confirmed on the PHP Settings page); Plesk Git deploy (keeps
untracked files, removes deleted ones); PHP Composer Install; `app/config.php`
(permissions 600, idle limits 24); certificate (without `www`, which is not in
DNS); `/install`.

To do:
1. ~~Client addresses~~ - verified 2026-10-03: the audit log shows the
   visitor's real public address (Plesk's nginx passes it to Apache/PHP), so
   `app.trusted_proxy_header` stays null.
2. ~~Git deployment mode~~ - confirmed Manual (2026-10-03).
3. ~~Scheduled Task~~ - daily "Run a PHP script" `app/cli/prune.php` (PHP 8.2)
   set up 2026-10-03; Run Now printed the expected "Pruned: ..." line, so CLI
   PHP works under `open_basedir`.
   **Backups: open.** Backup Manager has no remote storage available (no FTP
   server, no other remote target), so Plesk backups could only sit on the
   same server, inside the 1000 MB quota, and would not survive losing it.
   Options to decide later: download Plesk backups regularly, or export the
   database from phpMyAdmin (SQL) on a schedule and keep it off the server; ask
   ICT whether the hosting has server-level backups. `app/config.php` values
   are in the password manager; the code is in git.
4. ~~Database grants and engine~~ - verified 2026-10-03: MariaDB 10.11.19,
   default engine InnoDB; the migrations create every table `ENGINE=InnoDB`
   (with `NO_ENGINE_SUBSTITUTION`), and migration 003 (`ALTER TABLE`, drop
   index) ran during `/install`, so the user has the rights migrations need.
   SSH: forbidden (verified 2026-10-03). CGI unticked in Hosting Settings
   (unused). PHP errors: the app's `error_log()` lines appear in Plesk ->
   Logs, Apache error log, as `AH01071: Got error 'PHP message: [macrolab]
   unhandled: ...'` (search for `[macrolab]`; verified 2026-10-03 with the
   pre-install "table doesn't exist" errors). nginx (Apache & nginx Settings,
   checked 2026-10-03): proxy mode on, smart static files processing on,
   "serve static files directly by nginx" OFF - keep it off, or `.htaccess`
   deny and caching rules stop applying to those extensions (verified: the
   7-day cache header on `/assets/app.css` comes from `.htaccess`). nginx
   caching OFF - keep it off, pages are per signed-in user. Body limit 128 MB
   (PHP's 16 MB applies first). nginx adds `X-Powered-By: PleskLin`, which
   `.htaccess` cannot remove; cosmetic.
   **Web Application Firewall: settled 2026-10-03.** ModSecurity 3.0 with
   the Comodo (free) rule set on nginx, mode **On** (it blocks scanners trying
   `/.env` and `/.git/config`). Rule **243420** ("Information disclosure
   vulnerability in Eclipse Jetty", CVE-2015-2080) is **switched off** for this
   site: it inspects responses and turned every 400 answer to a form
   submission (wrong password, stale form, validation error) into a 403 that
   counts towards a Fail2ban ban of the whole IP address - site and Plesk -
   which is what locked us out for an hour. After switching it off, a wrong
   password shows the app's own message. The app also no longer sends 400 at
   all: invalid input is 422 (`HttpException::unprocessable()`, guarded by
   `tests/Unit/NoStatus400Test.php`). JSON API requests with quotes,
   semicolons, `<`, `--`, "select"/"drop"/"union" in notes pass the firewall.
   **Never probe the live site with requests the firewall may block from the
   user's own network** (Claude runs in WSL on the same public IP): a ban locks
   the user out of the site and Plesk.
5. ~~Idle limit~~ - verified on the live site 2026-10-03 after the fix: after
   25 idle minutes the admin was asked to sign in again. (The Plesk panel has
   its own, unrelated idle timeout, 30 minutes by default.)
6. ~~`httpdocs/`~~ - Plesk's original document root, deleted 2026-10-04
   (Let's Encrypt uses `public_html/.well-known/`). Keep
   `public_html/.well-known/` and Plesk's `public_html/cgi-bin/`.
7. ~~First real use~~ - 2026-10-04: activities and equipment added in the
   admin views; as a member, bookings made and cancelled, time entries made,
   changed and deleted. All of it appears in the admin views and the audit
   log, and the firewall did not interfere.
8. ~~Member roles~~ - live 2026-10-04: lab user (booking only), lab
   technician (+ own time registration), lab manager (+ everyone's time at
   `/time/overview`, with CSV). Migration `004_user_roles.sql` applied from
   Administration -> System; existing accounts became lab technician. Tested
   on the live site: a lab user can book equipment, sees no time registration
   in the hub or top bar, and is refused at `/time`; a lab technician can
   register time but is not shown the time overview; a lab manager downloaded
   the time CSV.
9. **"Equipment" rename (pending deploy):** "machine"/"instrument"/"resource"
   became "equipment" ("piece of equipment" for one) in the UI, docs, code
   (`Booking\Equipment`, `equipmentId`, API field `equipment`) and database
   (migration `005_equipment.sql`: table `equipment`, `bookings.equipment_id`,
   audit actions `equipment_*`). Old addresses `/admin/machines` and
   `/booking?machine=` were dropped on purpose. Deploy: export the database
   first; Pull + Deploy; PHP Composer **Install** (class renamed); then sign in
   at `/admin/login` and go straight to `/admin/system` (the dashboard fails
   until the migration runs) and apply the migration.
10. Next time: backups (ask ICT about server-level backups first; see item 3).
   Until then, export the database from phpMyAdmin before each release that
   brings a migration.

## Updates after go-live

Push to GitHub, then Plesk -> Git -> Pull now, then Deploy now. If
`composer.lock` changed, or classes under `app/src/` were added or moved,
run Install in PHP Composer (the latter only refreshes the optimised class
map; PSR-4 still finds unmapped classes). If a release adds a
migration, apply it from Administration -> System -> Apply migrations. The
server's `app/config.php` is never overwritten by a pull; do not delete it.
