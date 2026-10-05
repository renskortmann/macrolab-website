# Macrolab website

The web application of the TU Delft Macrolab, built to run on TU Delft LAMP
hosting. It contains **two independent systems** behind one sign-in:

| System | URL | Purpose |
|---|---|---|
| **Equipment booking** | `/booking` | Lab members reserve time on the lab's equipment. Each piece of equipment has a shared calendar; members book, change and cancel their own slots - and only their own. |
| **Time registration** | `/time` | Lab technicians log the hours they spend on their activities - maintaining equipment, supporting teaching, tidying up the lab, and so on - so that lab management can see how technician time is distributed over those activities. |

Both kinds of "time" appear, but they have nothing to do with each other:
booking reserves *equipment*, time registration records a person's *work*.
The two systems share the sign-in, the allowlist of who may sign in, the page
layout and the audit log - nothing else. No table, class or page of one refers
to the other, and the code is split accordingly (`app/src/Booking/`,
`app/src/Time/`; see [Layout](#layout)).

Signing in lands on the **hub** at `/`, which links to what the member's role
allows (see [Roles](#roles)), one tile each, side by side, with an icon: a
wrench for equipment booking, a clock for time registration, a spreadsheet for
the time overview, a head and shoulders for the account (and a gear for
Administration).

The screens always say **equipment booking**, never "booking" alone, because
"booking time" could just as well mean registering hours. Inside the equipment
booking pages, where the equipment is named anyway, a single reservation is
still "a booking". One administrator controls who may sign in and
with which role, manages the equipment and the equipment booking rules,
can create, change or delete any equipment booking, maintains the list of activities time is
logged against, and reads what everyone has logged (see
[Time registration](#time-registration)).

- **Stage 1 (now):** members sign in with their netID and a password they set
  themselves through a single-use link from the administrator.
- **Stage 2 (planned, not built yet):** members sign in with TU Delft SSO.
  Accounts are keyed on netID in both stages, so the switch will be a setting,
  not a migration. The routes, database columns and setting are in place; the
  SAML sign-in itself is not. See [the cutover](#stage-2-switching-to-tu-delft-sso).

The administrator's own sign-in never goes through SSO, so the lab keeps access
even when SSO is unavailable.

### Roles

Every member account has one of three roles, set by the administrator under
**Who may sign in**:

| Role | Equipment booking | Own time registration | Everyone's time (read-only, CSV) |
|---|---|---|---|
| **Lab user** | yes | - | - |
| **Lab technician** | yes | yes | - |
| **Lab manager** | yes | yes | yes, at `/time/overview` |

The hub and the top bar show only what the role allows, and the pages refuse
anyone whose role does not include them (403). A role change applies on the
member's next click. New accounts default to lab user; accounts that existed
before roles were introduced became lab technicians. Managing the activity
list stays with the administrator, who is not a member and has no role.

### Two separate sign-in pages

This trips people up, so it's worth stating plainly: there is no single sign-in
page.

| | URL | Who |
|---|---|---|
| Lab members | `/login` | netID + password (stage 1) or TU Delft SSO (stage 2) |
| Administrator | `/admin/login` | the one account created at `/install`, password + one-time code |

The administrator account is **not** a netID and is never on the allowlist -
entering its username at `/login` fails with the same generic
"Incorrect netID or password." that any unrecognised netID gets. Use
`/admin/login` instead.

---

## Requirements

| | |
|---|---|
| PHP | 8.2 or newer |
| PHP extensions | `pdo_mysql`, `mbstring`, `openssl`, `json`, `dom`/`xml`; `sodium` strongly preferred |
| Database | MySQL 5.7+ / MariaDB 10.4+ |
| Web server | Apache or nginx. `.htaccess` with `mod_rewrite` gives tidy URLs; without it there is a fallback that needs no rewrite rules |
| Shell on the server | **Not needed.** Installation, migrations and day-to-day operation all happen in the browser |
| Transport | HTTPS. The application redirects plain HTTP to the configured base URL |

Everything else is vendored: the booking calendar's JavaScript library is
served from this host, so the page needs no CDN and the content security policy
can stay at `'self'`.

`sodium` is preferred for two reasons: it gives Argon2id password hashing, and
it encrypts the administrator's TOTP secret. Without it the application falls
back to bcrypt and AES-256-GCM, which is sound - the install page and the
administration dashboard both tell you which one you got.

## Local development

```bash
composer install
cp app/config.example.php app/config.php
php app/cli/generate-key.php        # paste the output into app.key
# fill in db credentials and set app.base_url to http://localhost:8000
# and app.require_https to false

mysql -e 'CREATE DATABASE macrolab CHARACTER SET utf8mb4'
php app/cli/migrate.php
php app/cli/create-admin.php

php -S localhost:8000 -t public_html
```

`php -S` has no `.htaccess`, so it serves `index.php` for unknown paths by
itself - which is what we want. It does *not* apply the deny rules, so never
use it for anything but development.

`create-admin.php` prints a QR code's worth of secret and ten recovery codes
to the terminal, **once**. Scan the secret into an authenticator app (or add
it by hand - most apps offer "enter setup key" as an alternative to scanning)
before you close that terminal; there is no second copy anywhere. Then sign in
at `http://localhost:8000/admin/login`, not `/login` - see
[the two sign-in pages](#two-separate-sign-in-pages) above.

It also reads fine from a pipe, if you want to script the setup instead of
typing at the prompts (username, password, password again):

```bash
printf 'admin\nyour-password\nyour-password\n' | php app/cli/create-admin.php
```

### If `mysql -e '...'` refuses your connection

The one-liner above assumes passwordless `root` access, which on a stock
MariaDB install only works as the `root` *OS* user (it authenticates via
`unix_socket`, matching your Linux username to the MySQL username - so your
own login, and a plain `root`/empty-password guess over TCP, both get
"Access denied" even though the server is running fine). Two ways past it:

- Run the database setup itself as root: `sudo mysql -e 'CREATE DATABASE ...'`.
- Or create a dedicated account for the app instead of fighting `root`:

  ```bash
  sudo mysql -e "
  CREATE DATABASE IF NOT EXISTS macrolab CHARACTER SET utf8mb4;
  CREATE USER IF NOT EXISTS 'macrolab_dev'@'localhost' IDENTIFIED BY 'pick-a-password';
  GRANT ALL PRIVILEGES ON macrolab.* TO 'macrolab_dev'@'localhost';
  FLUSH PRIVILEGES;
  "
  ```

  Then put `macrolab_dev` / that password in `app/config.php`'s `db` block, and
  make sure `db.socket` is `null` there - a value left over from a different
  local MySQL instance (e.g. a scratch one from a previous test run) makes the
  app try to connect through a socket that no longer exists instead of over
  TCP, which fails the same way and is easy to mistake for a credentials
  problem.

### Tests

```bash
vendor/bin/phpunit                    # unit tests, no database needed

export MACROLAB_TEST_DB_NAME=macrolab_test    # a scratch database - it gets dropped
export MACROLAB_TEST_DB_USER=root
export MACROLAB_TEST_DB_PASS=
mysql -e 'CREATE DATABASE macrolab_test CHARACTER SET utf8mb4'
vendor/bin/phpunit                    # now the database tests run too

bash tests/concurrency.sh             # two processes race for one slot, 20x
```

The database tests rebuild the schema before every test, so point them at a
database you do not mind losing.

---

## Deploying to TU Delft LAMP hosting

The site is deployed from GitHub with Plesk's Git extension, and its
dependencies are installed with Plesk's PHP Composer extension. Neither needs a
shell on the server. FTP is the fallback, described at the end.

### What that hosting gives you, and what follows from it

| Offered | What it means here |
|---|---|
| Plesk panel, reachable **only from a campus network** | Deploy from campus or over eduVPN |
| **Git** extension | The deployment channel: Plesk pulls this repository from GitHub |
| **PHP Composer** extension | Builds `vendor/` on the server from `composer.lock` |
| **File Manager** | Where `app/config.php` is created and edited |
| **No SSH** | Hosting Settings shows SSH access as "Forbidden", and the subscription cannot change it. There is no command line: the schema and the administrator account are created in the browser, at `/install`, and later migrations are applied from Administration → System |
| FTP, unlimited users | Only a fallback. Use **FTPS** - plain FTP sends the password in the clear |
| 1000 MB webspace, 10 databases | Ample: this application plus its dependencies is a few MB, and it uses one database |
| SSL available | Required. The application refuses plain HTTP |
| **Mail not available** | Which is why this system sends none. Nothing here depends on it |
| You are responsible for backups | Plesk can schedule them; nobody else will |

### 1. Prepare the subscription in Plesk

All of this is under **Websites & Domains** → your domain.

- **PHP Settings**: PHP **8.2 or newer**, run as **FPM application served by
  Apache**. The "served by nginx" variant ignores `.htaccess`, which the
  routing and the deny rules depend on.
- **Hosting Settings**: change the document root from `httpdocs` to
  **`public_html`**, so it matches this repository's layout.
- **Databases**: add a database and a database user with rights on that
  database only. Write the name, user and password down in a password manager;
  they go into `app/config.php` in step 4.

### 2. Deploy the code with Plesk Git

**Git** → *Create repository*:

- **Remote repository**, with the GitHub URL of this repository. If the
  repository is private, Plesk shows an SSH public key after creation: add it
  in GitHub as a read-only **deploy key**, and use the SSH URL.
- **Deployment mode: Manual.** *Automatic* needs GitHub to call a webhook on
  the Plesk server, which is reachable from campus only.
- **Deployment directory: `/`**, the subscription root - not `/httpdocs`.
- Leave the *additional deployment actions* off: they run shell commands, which
  this hosting does not allow.

Then *Pull updates* and *Deploy now*. The subscription root ends up like this:

```
<subscription root>/
    public_html/     <- the document root: index.php, .htaccess, assets/
    app/             <- beside the document root, so unreachable over the web
    vendor/          <- not in git; step 3 creates it
    app/config.php   <- not in git; step 4 creates it
    tests/ docs/ composer.json ...   harmless: outside the document root
```

`public_html/index.php` finds `app/` one level up by itself; there is nothing
to configure. Plesk's original `httpdocs/` folder is no longer used and can be
deleted once the site works.

When the document root is changed, Plesk puts its "Domain Default page"
(`index.html`) into the new `public_html/`, and a deployment leaves that
untracked file alone. Delete it in File Manager. `.htaccess` names
`index.php` as the only index file, so the app is served at `/` either way,
but the file has no business there.

### 3. Build `vendor/` with Plesk PHP Composer

**PHP Composer** on the domain dashboard. It finds `composer.json` in the
subscription root. Choose **Mode: Production**, which leaves out the
development packages, and click **Install**.

Install uses the exact versions in `composer.lock`. **Never click Update** on
the server: it ignores the lock file and picks new versions. Dependencies are
changed on your own machine with `composer update`, and the new
`composer.lock` is committed and deployed like any other change.

### 4. Create `app/config.php`

In **File Manager**, copy `app/config.example.php` to `app/config.php` and fill
in:

- `app.base_url` - the final HTTPS address, no trailing slash
- `app.key` - run `php app/cli/generate-key.php` on your own machine and paste
  the output
- `app.install_token` - run `php app/cli/generate-key.php` again and paste that
  too; this is what protects `/install` between deployment and installation
- the `db` block - host `localhost`, port `3306`, and the database name, user
  and password from step 1. Quote values containing a hyphen.

Set the file's permissions to **600** (owner read/write only). `config.php` is
not in git, and Plesk's deployment leaves untracked files alone, so later
deployments do not touch it.

Generate fresh values for every installation. Never reuse a key or token that
has been pasted into a chat, an email or a ticket.

### 5. Get the HTTPS certificate

The hostname must resolve in DNS first. Records under `tudelft.nl` are managed
by ICT, not by Plesk, so ask ICT for the record (an A record to the hosting
server, or a CNAME to it) and wait until it resolves:

```bash
curl -s "https://dns.google/resolve?name=<host>&type=A"   # "Status": 0 with an "Answer"
```

Then **SSL/TLS Certificates** → *Let's Encrypt*. Untick the `www.` variant
unless that name is in DNS too, or issuance fails.

Do not install before HTTPS works: `/install` sends the install token and the
administrator's password, so never run it over a preview URL or a hosts-file
override.

### 6. Install, in the browser

Open:

```
https://<host>/install?token=<your install_token>
```

URL-encode the token: a generated one may contain `/`, `+` or `=`.

The page checks the server (PHP version, extensions, database connection,
whether `app/` is web-reachable), then loads the schema and creates the
administrator account in one step. It shows you the authenticator QR code and
your recovery codes **once, in that response, and nowhere else** - not by
email, not on a later page, not recoverable from the database. Scan the QR
code with your phone's authenticator app before you navigate away or close the
tab, and save the recovery codes somewhere durable.

**If you miss it anyway** - closed the tab, the page didn't load, whatever -
you are not locked out, but you cannot get the same QR code back:
1. Sign in at `/admin/login` with the username and password you just chose.
   The one-time-code step accepts a recovery code (shown further down on this
   same page) in place of a six-digit code, exactly once each.
2. Once in, go to **Administration → System** and re-enrol the authenticator.
   That issues a fresh secret and shows its QR code - again, once - which
   replaces the one you missed.

It then stops existing: with an administrator account on file, `/install`
returns 404. Afterwards, blank `install_token` in `app/config.php` in File
Manager.

### 7. Check routing and the deny rules

Visit `https://<host>/login`. If you get the sign-in page, routing works.
Then make sure the configuration is not served:

```bash
curl -i https://<host>/app/config.php
```

That must return 403 or 404 and never any content. With the layout from step 2
`app/` is outside the document root, so this is a check rather than a worry.

If `/login` gives a 404 from the web server, the site is being served through
nginx without honouring `.htaccess` - check the PHP setting from step 1 first.
Two ways out if it stays that way:

- **Plesk** → **Apache & nginx Settings** → *Additional nginx directives*:
  ```nginx
  location / {
      try_files $uri $uri/ /index.php$is_args$args;
  }
  location ^~ /app/    { deny all; }
  location ^~ /vendor/ { deny all; }
  ```
- Or avoid rewriting altogether: set `app.base_url` to
  `https://<host>/index.php`. Every link the application generates then goes
  through `/index.php/...`, which needs no rewrite rules at all. Slightly
  uglier URLs, nothing else changes.

### 8. Housekeeping

**Plesk** → **Scheduled Tasks** → add a task, type *Run a PHP script*, script
path `app/cli/prune.php`, daily. That trims the audit log to the retention
window and clears spent invite links. Nothing breaks if you skip it; the
database just grows slowly.

Also set up **Plesk** → **Backup Manager**, since backups are your
responsibility. The database is the part that matters - the code can be
deployed again from this repository at any time, but `app/config.php` cannot,
so include it or keep its values in a password manager.

### Updating later

1. Push the change to GitHub.
2. In Plesk (on campus or eduVPN): **Git** → *Pull updates*, then *Deploy now*.
3. If `composer.lock` changed, run **Install** again in **PHP Composer**. Do
   the same after a release that adds or moves classes under `app/src/`: it
   refreshes the optimised class map. (Classes missing from the map are still
   found by their folder, so the site keeps working in the meantime.)
4. If the release adds a database migration, apply it **straight away** from
   **Administration** → **System** → *Apply migrations*. New code may expect
   the new columns, so members can get errors until it is applied; the
   administration pages keep working. That page is behind your own sign-in,
   so it needs no install token and stays available for the life of the
   installation.

A deployment never overwrites `app/config.php` or `vendor/`.

### Fallbacks

**If the PHP Composer extension is unavailable**, deploy a branch that
contains `vendor/` instead of `main`:

```bash
git checkout -b deploy
composer install --no-dev --optimize-autoloader
git add -f vendor composer.lock
git commit -m "Deploy build"
git push origin deploy
```

and choose the `deploy` branch in Plesk Git. Rebuild and push it for every
release.

**If Git is unavailable**, upload over FTPS instead: build `vendor/` locally
with `composer install --no-dev --optimize-autoloader`, then upload
`public_html/`, `app/` and `vendor/` into the layout from step 2.

**If the document root cannot be moved and nothing can be written beside it**,
put `app/` and `vendor/` inside the document root, next to `index.php`.
`index.php` handles that layout too, but the protection of `app/` then rests
entirely on `.htaccess`, so the check in step 7 becomes essential. If
`/app/config.php` returns the file, **stop**: replace the real database
password with a placeholder, and ask ICT either to raise `AllowOverride` or to
let you write beside the document root.

## Running it

### Giving someone access

**Who may sign in** → enter their netID and choose a role (lab user,
technician or manager; see [Roles](#roles)) → you get a single-use link. Send it
however you like: Teams, email, in person. They open it, choose a password, and
sign in. You never see their password.

The link works once and expires after seven days. Issuing a new one
invalidates the previous one.

### Someone forgot their password

Same page, **Reset password**. Their old password keeps working until they set
a new one, so a link that never arrives cannot lock them out.

### Taking access away

**Suspend** blocks sign-in immediately - including in the middle of a session,
because the allowlist is checked on every request - and keeps the person's
bookings and registered time. **Remove** is only offered when they have no
bookings and no registered time at all.

### Equipment booking rules

**Rules** sets slot length, opening hours and days, minimum and maximum
booking length, how far ahead people may book, how many upcoming bookings each
may hold, and how much notice is needed to change one. They apply to lab
members; they do not apply to you. Overlapping bookings are refused for
everyone, including you.

### Audit log

Every equipment booking change, time entry change, activity change, allowlist change,
settings change and sign-in - including refused ones - is recorded with who, when and from which address. Entries are
kept for the number of days set in the rules (365 by default) and pruned by
`app/cli/prune.php`.

### If you lose your authenticator

Use a recovery code instead of the six-digit code; each works once. The
dashboard shows how many are left, and you can issue a fresh set from there.

Out of codes *and* out of authenticator, the account can only be recovered
through the database. In Plesk, **Databases** → **phpMyAdmin**, then:

```sql
DELETE FROM admin_account;   -- admin_recovery_codes cascades with it
```

Put an `install_token` back into `app/config.php` (File Manager), and open
`/install` again to create the account afresh. Bookings, time entries, users
and the audit log are untouched.

## Stage 2: switching to TU Delft SSO

> **Status: not implemented yet.** This version has the groundwork - the
> reserved `/auth/saml/*` routes (they answer 404 for now), the
> `users.saml_name_id` column, the sign-in mode setting, the `saml` block in
> `app/config.example.php` and `app/cli/purge-local-passwords.php` - but no
> SAML provider. Until one exists (`Macrolab\Auth\SamlProvider`, built on the
> already-required `onelogin/php-saml`), the settings page offers password
> sign-in only, and a stored SSO mode is ignored, so nobody can be locked out.
> The runbook below is the plan for when it is built.

1. Send ICT the request in [docs/ICT-REQUEST.md](docs/ICT-REQUEST.md). Do this
   early - registration takes time, and nothing else in the build waits on it.
2. Generate the service provider key pair **on your own machine** and upload
   the two files to `app/secrets/` with Plesk File Manager (or FTPS), setting
   `sp.key` to permissions 600. They are not in git, so deployments leave them
   alone:
   ```bash
   openssl req -x509 -newkey rsa:3072 -nodes -days 3650 \
       -keyout app/secrets/sp.key -out app/secrets/sp.crt -subj "/CN=<host>"
   ```
   Keep a copy of `sp.key` somewhere safe and out of version control: ICT
   registers the matching certificate, so losing the key means re-registering.
3. Paste the IdP signing certificate from
   `https://login.tudelft.nl/sso/saml2/idp/metadata.php` into
   `saml.profiles.tudelft.x509cert` in `app/config.php`. Take it from that URL
   yourself; do not accept a copy from anywhere else.
4. Set **Sign-in mode** to *Either password or TU Delft SSO* and sign in with a
   real netID. Check which attribute names the assertion actually carried
   (stage 2 is meant to include a debug page for this). If the netID did not
   arrive under `uid`, add the name you see to `saml.attr_map.netid` -
   configuration, not code.
5. Confirm that an SSO sign-in lands on the **existing** account, with its
   bookings and time entries intact, and that a netID which is not on the
   allowlist is still refused.
6. Set **Sign-in mode** to *TU Delft SSO only*. Password sign-in for lab
   members is then refused and logged. Your own sign-in is unaffected.
7. Once you are satisfied, clear the unused password hashes. With a shell:
   ```bash
   php app/cli/purge-local-passwords.php --force
   ```
   Without one, run this in Plesk's phpMyAdmin - it does the same thing, and
   only after the sign-in mode is already *TU Delft SSO only*:
   ```sql
   UPDATE users SET password_hash = NULL, password_changed_at = NULL;
   DELETE FROM user_invites;
   ```

The base URL is baked into the entityID and the ACS URL that ICT registers, so
**fix the hostname before step 1** - changing it later means asking ICT to
re-register.

---

## Notes on the design

Shared by both systems:

- **Times.** Every timestamp is stored in UTC and rendered in
  `app.display_timezone`. The one exception is the day a time entry is for,
  which is a plain calendar date and never shifted by a timezone.
- **Every card folds.** Each section (`<section class="card">`) has an open
  arrowhead in its upper right corner - up while open, down while folded -
  and clicking it or the heading folds the card. Cards start open, and nothing
  is remembered. `wireFoldableCards()` in `app.js` does this for every card
  that starts with its heading; `tests/Unit/CardsCanFoldTest.php` keeps it
  that way. Without the script every card is simply open.
- **Dates are shown and typed day first** (08-10-2026), or written out
  ("Thu 8 Oct 2026"). Browsers display their built-in date input in the
  browser's language - month first in an American one - and a page cannot
  change that, so date fields are plain text fields with a calendar button
  that opens the browser's picker (`date_field()` in `app/src/helpers.php`).
  The server reads dates with `Clock::isoDate()`: day first, or ISO from links,
  and never the American way. Links and the CSV export keep ISO dates
  (2026-10-08), which every spreadsheet reads correctly.
- **The allowlist gate** lives in `Auth::signIn()`, not in an authentication
  provider, so it cannot be bypassed by a bug in one provider and does not have
  to be reimplemented when SSO is added.
- **Authorisation.** Every write re-loads the record it changes and asks the
  system's policy - `BookingPolicy` or `TimeEntryPolicy` - whether this person
  may change it. A request naming somebody else's booking or time entry is
  refused with 403, whatever the interface offered.
- **Sessions** end after 24 idle minutes, for members and the administrator
  alike. That is the session lifetime of the TU Delft hosting, which deletes
  older session files and does not let the subscription change it, so the idle
  limits in `app/config.php` are set to match rather than promise more.
- **No 400 answers.** Invalid input - a wrong password, a stale form, a
  missing field - is answered with 422. The TU Delft hosting's firewall
  (ModSecurity, Comodo rules) has a rule, 243420, that turns a 400 answer to a
  form submission into a 403 and counts it towards banning the visitor's IP
  address from the whole server. The rule is switched off for this site, and
  the application does not rely on that. `tests/Unit/NoStatus400Test.php` keeps
  it that way.
- **Personal data** is limited to netID, display name and email address, plus
  each person's own bookings, their own time entries (with their notes) and
  the audit log. No email is sent and the application makes no outbound
  connections of any kind.

Equipment booking:

- **Opening hours** are compared in local wall-clock time, which is what
  "between 08:00 and 18:00" means, so the March and October DST transitions
  cannot produce an ambiguous booking.
- **Overlaps.** Each booking write takes a row lock on the piece of equipment first, so
  two requests cannot both find a slot free and then both fill it. Intervals
  are half-open: a booking ending at 10:00 and one starting at 10:00 do not
  clash.

## Equipment

The lab calls what it books **equipment**; one item is a **piece of
equipment**. The code and the database use the same word (`Booking\Equipment`,
the `equipment` table, `bookings.equipment_id`).

The administrator manages the bookable equipment at `/admin/equipment`. Each
booking belongs to one piece of equipment, and the calendar shows one piece at
a time. The heading "Equipment booking calendar" is followed directly by the
calendar's toolbar: the arrows and Today on the left, Week/Day/List on the
right, and in the middle a dropdown with the equipment, left of the dates
shown ("5 - 9 Oct 2026" for a week, "5 Oct 2026" for a day). Choosing another
piece shows its bookings at once, without reloading the page, in the same view
and on the same dates, and puts `?equipment=<slug>` in the address. The legend
sits centred under the calendar, and the booking rules are shown in the
booking dialog, under Purpose. A visit to `/booking` starts with no equipment selected:
the calendar stays empty and read-only until a piece is chosen, and trying to
book before that asks the member to choose the equipment first. Nothing is
remembered between visits, so nobody books the wrong equipment by accident.
`/booking?equipment=<slug>` links straight to one piece.

Under the calendar, members see **My upcoming bookings** for all equipment,
each with a link to that equipment's calendar. The list refreshes itself after
a booking is made, moved or cancelled.

A piece of equipment with bookings on record cannot be deleted, only retired -
the same reasoning as suspending a user rather than deleting them, so the
record of who used what stays intact. Retiring one hides it from the picker
and stops new bookings; the bookings it already has are untouched. The last
piece still in use cannot be retired.

The equipment booking rules are shared by all equipment. The per-person quota counts per
piece of equipment, so filling up one does not lock anybody out of the others.

## Time registration

Lab management wants to know how the lab technicians divide their time over
their activities: maintaining equipment, supporting teaching, tidying up the
lab, and so on. All of these fall under the general lab code. Time
registration collects exactly that. Technicians log the hours they spent at
`/time`: a day, an activity, a duration and an optional note. Hours can be typed as `3.5`, `3,5`, `3:30` or `3h30`, and are stored as
whole minutes, so nothing is lost to rounding.

The screens say **activities**, but the code, the database, the URL
`/admin/projects` and the audit log action names still say **projects**
(`Project`, `projects` table, `project_added`). They are the same thing. The
administrator keeps the list at `/admin/projects`, and every entry names one
activity, with an optional code of its own. Only lab technicians and lab
managers can open `/time`; lab users are not shown it (see [Roles](#roles)).

**This system is not connected to the equipment booking system.** A time entry names an
activity and never equipment. The two halves share the sign-in and nothing else,
which is deliberate: hours are booked to work, not to equipment. Time spent
maintaining a piece of equipment is logged against a maintenance activity, not
against that equipment's calendar.

Below the day sheet, **My time registrations** lists one month of the
technician's own entries, oldest first, with arrows to the previous and next
month and a total per activity.

Employees own their entries and can change or remove their own at any time -
and only their own. A request naming somebody else's entry is refused with 403
and recorded, the same discipline the bookings use.

The administrator maintains the activity list at `/admin/projects` and reads
what everyone has logged at `/admin/time`; lab managers get the same overview
at `/time/overview`. That view is **read-only**: there is no approval step,
and nobody edits somebody else's timesheet. It has two cards:

- **Time registrations table**: the activities down the side, and a column for
  everyone who logged time in the period, ordered by last name (the last word
  of the display name, so "van der Berg" sorts under B); more people than
  fit scroll sideways. Each cell holds the
  hours and that person's share of their own time in the period, so each
  column adds up to 100% (rounded by largest remainder), and a Total row
  closes the table. A cell whose entries carry notes has a small mark in its
  corner; hovering over it (or tabbing to it) shows those notes, each with its
  day and hours. Above it is a toolbar styled after the booking calendar's:
  previous and next, Today, the dates, and Week (Monday to Sunday, so weekend
  hours count) or Day. It opens on the current week. Its period is
  independent of the filter below it, and it always shows everyone. The
  address carries it as `period=week|day&date=<ISO date>`.
- **Export to CSV**: the filter on one line (date range, person, activity),
  the entries it selects, oldest first, five rows at a time with an
  always-visible scrollbar, and the download links. The CSV holds exactly
  those rows (columns `date`, `netid`, `name`, `activity`, `activity_code`,
  `hours`, `minutes`, `note`, `entry_id`), separated by commas or by
  semicolons.

An activity with time on record cannot be deleted, only retired - the same
reasoning as retiring a piece of equipment. For the same reason, an account with time
registered cannot be removed from the allowlist, only suspended.

The limits on entry length, on the daily total, and on how far ahead or back
time may be logged are set alongside the equipment booking rules at `/admin/settings`.

The CSV is UTF-8 with a byte-order mark, so Excel reads accented names
correctly. Any cell beginning with `=`, `+`, `-` or `@` is prefixed with an
apostrophe, because spreadsheets execute those on open and the note field is
typed by a user.

Time entries are **never pruned**. `app/cli/prune.php` trims logs and spent
tokens; hours are a business record.

---

## Layout

```
public_html/index.php        the only reachable PHP file; everything is routed
public_html/.htaccess        routing, deny rules, caching (security headers: Bootstrap)
public_html/assets/          stylesheet, script, vendored FullCalendar
app/config.php               local configuration (gitignored)
app/routes.php               the whole route table, one section per system
app/src/                     shared: sign-in, sessions, database, views, audit
app/src/Booking/             equipment booking (namespace Macrolab\Booking)
app/src/Time/                time registration (namespace Macrolab\Time)
app/src/Controller/          the HTTP handlers for both, named after their system
app/views/                   plain PHP templates; time registration in views/time/
app/migrations/              schema; 002 onwards adds time registration
app/cli/                     migrate, create-admin, generate-key, prune
docs/ICT-REQUEST.md          the SSO registration request to send ICT
tests/                       unit tests, database tests, concurrency probe
```

Classes in `Macrolab\Booking` never use classes in `Macrolab\Time`, and the
other way round. Both may use the shared classes in `Macrolab\`.

Shared (`app/src/`):

| Class | What it is for |
|---|---|
| `Auth`, `Actor` | who is signed in; the allowlist gate; what the role allows |
| `Role` | lab user, lab technician, lab manager, and what each may use |
| `Auth\LocalProvider` | stage 1 password sign-in |
| `Auth\ProviderInterface` | the seam TU Delft SSO will slot into (stage 2, not built yet) |
| `Invite` | single-use links for setting a password |
| `AdminAuth`, `Crypto` | the administrator's password and one-time codes |
| `Navigation` | the one list of destinations, shared by the hub and the top bar's tabs; the administration tabs of the second ribbon; which tab is current |
| `Settings` | what the administrator sets in the web UI: both systems' rules, the sign-in mode |
| `Clock` | UTC storage, display timezone, durations in words |
| `Audit`, `RateLimit` | the record, and login throttling |

Equipment booking (`app/src/Booking/`):

| Class | What it is for |
|---|---|
| `Equipment` | the equipment that can be booked |
| `Booking`, `Bookings` | one booking, and the queries that find them |
| `BookingRules`, `RuleSet` | the booking rules, as pure functions |
| `BookingService` | writes, with the lock and the overlap check |
| `BookingPolicy` | who may change which booking |

Time registration (`app/src/Time/`):

| Class | What it is for |
|---|---|
| `Project`, `Projects` | the activities time is logged against ("activities" on screen) |
| `TimeEntry`, `TimeEntries` | one entry, and the queries that find them |
| `TimeRules`, `TimeRuleSet` | the time rules and the hour/date parsing, as pure functions |
| `TimeEntryService` | time writes, with the daily cap and the audit entry |
| `TimeEntryPolicy` | who may change which time entry - the admin may not |
| `TimeFilter` | one filter behind the overview's entry list and the export |
| `TimeMatrix` | the overview's table: hours and shares per activity per person, for a week or a day |

`Csv` (shared) writes the time export, quoted and safe to open in a
spreadsheet.
