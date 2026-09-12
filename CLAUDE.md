# NLP Services

Drupal 11 custom module ("NLP Services") supporting the Neighborhood Leader Program — voter turf management, data entry, reporting, and coordinator/NL communication tooling for state-level organizing sites. GitHub: https://github.com/sjpacker21355/NLP_Services (this directory is that repo's clone — commit/push from here directly).

## Branch strategy

`NLP_Services_V7_3` is the active development branch — default to it for any new work. Other branches on the remote (`NLP_Services_V7_0_1`, `_V7_0_2`, `_V7_1_0`, `_V7_2_0`, `_V7_2_1`) are earlier version snapshots; `main` is the GitHub default branch but not necessarily where active work happens. Module version in `nlpservices.info.yml` (`7.3`) tracks the branch name.

## Dev / test / deploy workflow

Deploys to **five** live NLP sites via FTP (per `.idea/webServers.xml` / `.idea/deployment.xml`, both already configured): `ftp.oregonnlp.org`, `ftp.sandboxnlp.org`, `ftp.specialnlp.org`, `ftp.mtdemsnlp.org`, `ftp.mississippinlp.org`, all port 21 with explicit FTPS, deploying to `/modules/custom/nlpservices` on each. This app can't handle the FTP passwords (password-based auth, credentials live in PhpStorm's own store). If asked to help deploy, hand over ready-to-run `curl --ftp-ssl-reqd --ftp-pasv -u <user> -T <file> ftp://<host>:21/modules/custom/nlpservices/<path>` commands for the user to run themselves, or point them to PhpStorm's Deployment menu.

`NlpReadMe.md` documents full from-scratch site setup (SSL, database, private file path, required contrib modules — autologout, mpdf, bartik, views_bulk_operations, token, smtp, real_aes, key, file_entity, encrypt — SMTP config, theme setup). `readme.md` is just Drupal core's stock README (not NLP-specific) — ignore it.

## Local dev environment

This module lives inside the **same** disposable Drupal 11 scaffold used by `dog_check`, at `~/code/drupal-site`, checked out at `web/modules/custom/NLP_Services` (note: directory name is `NLP_Services`, machine name is `nlpservices`). The ddev project is named `dog-check-dev` — both modules run side by side on it. See `../dog_check/CLAUDE.md` for the shared site's basic DDEV setup/recreate instructions; only what's specific to this module is covered below.

```bash
cd ~/code/drupal-site
ddev start
ddev launch                          # http://dog-check-dev.ddev.site
ddev drush uli                       # admin/admin
```

**Runtime deps this module needed that the shared site didn't have yet** (added 2026-09-12): `ext-imap` (added to `.ddev/config.yaml` as `webimage_extra_packages: ["php${DDEV_PHP_VERSION}-imap"]`, requires an image rebuild to bake in permanently — see caveat below), `smalot/pdfparser` and `mpdf/mpdf` (added to the site's root `composer.json`). `guzzlehttp/guzzle` was already present as a transitive Drupal core dependency.

**`$settings['file_private_path']` is required** — the module stores API key files under `private://` and refuses to install without it. Set in `web/sites/default/settings.php` (appended after the `settings.ddev.php` include):
```php
$settings['file_private_path'] = '/var/www/html/private-files';
```
(directory created at `/var/www/html/private-files` inside the container).

### `ddev` CLI is unreliable on this machine — use `docker exec` directly

On this Windows/git-bash setup (ddev/docker installed without admin rights into `~/bin`, per `dog_check`'s CLAUDE.md), state-changing `ddev` subcommands (`ddev restart`, `ddev composer require`, `ddev mutagen sync`) reliably **hang indefinitely with zero output** — confirmed by checking for the underlying process inside the container (never spawned) and on the host (an orphaned `ddev` process that has to be killed manually). Read-only commands (`ddev describe`, `ddev list`) work fine.

**Workaround: bypass the `ddev` wrapper and talk to the container directly.** This worked instantly every time it was tried:
```bash
# Any docker command with a path argument (e.g. -w /var/www/html) needs this env var
# on git-bash/MSYS, or the path gets mangled into a Windows path before docker sees it:
MSYS_NO_PATHCONV=1 docker exec -w /var/www/html ddev-dog-check-dev-web composer require <pkg>
MSYS_NO_PATHCONV=1 docker exec -w /var/www/html ddev-dog-check-dev-web vendor/bin/drush <cmd>
MSYS_NO_PATHCONV=1 docker exec -w /var/www/html ddev-dog-check-dev-web vendor/bin/phpunit -c web/modules/custom/NLP_Services/phpunit.xml
```
Mutagen file sync also appears to silently stop propagating container→host changes after this kind of manual intervention — after editing files inside the container (composer.json, settings.php), reconcile the host copies explicitly with `docker cp ddev-dog-check-dev-web:/var/www/html/<file> "<host-path>"` rather than trusting sync to catch up.

**Caveat on the imap extension**: it was installed live into the *running* container (`apt-get install php8.3-imap` via `docker exec --user root`, then `phpenmod imap`), not baked into the image — this survives a plain `docker restart` (preserves the container's writable layer) but **will be lost** if the container is ever recreated from the image (`ddev restart` if it ever works again, a full `ddev poweroff`/`ddev start`, or rebuilding from scratch). The `.ddev/config.yaml` entry is already in place so a proper image rebuild will pick it up correctly — this is just a note that the live container and the declared config can drift until that rebuild actually happens. If pages start throwing "Call to undefined function imap_open()" again, redo the apt-get step or get `ddev restart` working.

Also note: after any container restart (`docker restart ddev-dog-check-dev-web`), php-fpm and nginx did **not** come back up on their own (likely disrupted by having manually apt-installed a package mid-session) — check with `docker exec ddev-dog-check-dev-web ps aux | grep -E 'nginx|fpm'` and manually run `php-fpm8.3 -D` / `nginx` inside the container if either is missing.

### Composer setup differs from dog_check — mind the scaffold plugin

Unlike `dog_check`'s IDE-support `composer.json` (which explicitly disables `drupal/core-composer-scaffold`), this module's `composer.json` **allows** the scaffold plugin and is used for both IDE support *and* running the real test suite (`require-dev` pulls in `phpunit/phpunit` and `drupal/core`). Because scaffold is allowed, running `composer install`/`composer update` directly in this module directory dumps a full Drupal skeleton into it (`modules/`, `profiles/`, `recipes/`, `sites/`, `themes/`, plus loose files like `index.php`, `.htaccess`). The existing `.gitignore` already excludes the loose top-level files, but **not** the directories — after any composer operation here, check `git status` and `rm -rf modules profiles recipes sites themes` if they reappear (confirmed happening 2026-09-12; also touches `.gitattributes`/`.gitignore` via merge but with no real content, and safely revertible with `git checkout -- .gitattributes`).

Also: `phpunit/phpunit: ^10` in this module's own `require-dev` is now **stale** relative to Drupal 11.4 — Drupal 11.4's `drupal/core-dev` requires PHPUnit ^11.5, and the version mismatch causes a hard crash (`Call to undefined method ... identifyIssueTrigger()`) if you run the module's own bundled `vendor/bin/phpunit`. **Run tests through the site's PHPUnit (from `drupal/core-dev`) instead**, pointed at this module's config:
```bash
MSYS_NO_PATHCONV=1 docker exec -w /var/www/html ddev-dog-check-dev-web vendor/bin/phpunit -c web/modules/custom/NLP_Services/phpunit.xml
```
This requires `drupal/core-dev:^11.4` on the **site's root** `composer.json` (added 2026-09-12; wasn't there before — `ddev composer require --dev drupal/core-dev:^11.4 --with-all-dependencies` was needed since a plain require couldn't resolve without upgrading `sebastian/diff` past what was locked).

As of 2026-09-12: full suite passes clean — **2658 tests, 5970 assertions, 0 failures**.

## Key files

- `nlpservices.routing.yml` — ~60+ routes; `/nlpservices-welcome` is the module's landing page (`Controller\WelcomeController`).
- `NlpReadMe.md` — the real setup/deployment doc (not `readme.md`, which is Drupal core boilerplate).
- `src/Config/` — admin configuration forms (API, election, email, survey questions, response codes, activist codes, cron tasks, document config).
- `src/Batch/` — long-running batch operations (imports, exports, sync jobs for turf/NLs/matchbacks).
- `src/Nlp*.php` (dozens of files at `src/` top level) — the bulk of the domain logic: turf assignment/display/sync, voter data entry and lists, PDF generation (`PdfGenerator.php`, `NlpPdfParser.php` via `smalot/pdfparser` + `mpdf/mpdf`), email (`NlpSendEmail.php`, SMTP-dependent), IMAP inbox processing (`NlpImap.php`, needs `ext-imap`), encryption (`NlpEncryption.php`).
- `src/Api*.php` — Guzzle-based API clients (activist codes, export jobs, folders, key management/verification, map regions, NLs, response codes, saved lists, survey questions, voter data).
- `tests/bootstrap.php` — boots Drupal's own `core/tests/bootstrap.php` before defining test stubs/constants (`NLP_MODULE`, role IDs, `user_load_by_*` stubs, a fake `t()`). Because it chains into Drupal's real bootstrap, tests need the site's PHPUnit 11, not the module's own vendored PHPUnit 10 (see above).
