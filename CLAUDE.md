# NLP Services

Drupal 11 custom module ("NLP Services") supporting the Neighborhood Leader Program — voter turf management, data entry, reporting, and coordinator/NL communication tooling for state-level organizing sites. GitHub: https://github.com/DemocraticPartyofOregon/NLP_Services (this directory is that repo's clone — commit/push from here directly).

## Branch strategy

`NLP_Services_V7_3` is the active development branch — all work goes here. Other branches on the remote (`NLP_Services_V7_0_1`, `_V7_0_2`, `_V7_1_0`, `_V7_2_0`, `_V7_2_1`) are earlier version snapshots; `main` is the GitHub default branch but not necessarily where active work happens. Module version in `nlpservices.info.yml` (`7.3`) tracks the branch name.

**`NLP_Services_V7_2_1` is frozen** (as of 2026-11-15, after the election): no further changes, no bug-fix backports, no cherry-picks in either direction. Errors reported from the production sites (which still run V7_2_1 until December) should be diagnosed against V7_3 and fixed there only.

## Dev / test / deploy workflow

Six NLP sites are configured for deploys. The five `ftp.*` hosts (per `.idea/webServers.xml` / `.idea/deployment.xml`, both already configured) use explicit FTPS on port 21 and deploy to `/modules/custom/nlpservices`; nlpdebug uses SFTP on port 22 (see below; its PhpStorm SSH settings are in `.idea/sshConfigs.xml`):

- **`ftp.sandboxnlp.org`** and **`nlpdebug.org`** — run `NLP_Services_V7_3` on Drupal 11. Until the December migration, **V7_3 work deploys to these two and nowhere else.** **nlpdebug's live site is `/var/www/drupal/web`** (found 2026-11-17 with `find / -name NlpReadMe.md -size <bytes>c` over SSH), so it **deploys over SFTP (SSH, port 22, user `nlpadmin`) to `/var/www/drupal/web/modules/custom/nlpservices`**. FTP can't reach it: vsFTPd (plain FTP, no TLS) starts `nlpadmin` in its home folder. `~/nlpdebug/web` there used to be a **second Drupal install the website doesn't serve** — on 2026-11-17 an afternoon of uploads went there and changed nothing. On 2026-11-22, after checking that no symlink, web server config, `settings.php` or cron job referred to it, it was **renamed to `~/nlpdebug-unused`**, to be deleted with `sudo rm -r /home/nlpadmin/nlpdebug-unused` (it's owned by `www-data`) once the live site has run a few days without it. An empty, unused `~/private_files` was removed the same day. PhpStorm's `NLPDebug` profile was switched from plain FTP to SFTP at the same time. **nlpdebug's private files are in `/var/www/private`** (keys in `/var/www/private/keys`, owned by `www-data`, folders `750`) — the one exception to the `~/private-files` standard, because it's a plain Ubuntu server with the site under `/var/www`, not a cPanel account. (A stray `/var/www/drupal/var/www/drupal/web/modules/custom/NlpReadMe.md` also exists from some past mis-aimed upload.) When a deployed change has no effect, run `deploy.sh <site> --find` first: it lists every copy of the module, which folder holds Drupal's `core/`, and which copy the website actually serves (by matching `NlpReadMe.md`'s size over HTTPS). For SFTP sites `deploy.sh` uses OpenSSH's `sftp`/`ssh`, not `curl`: Git Bash's curl (libssh2) supports neither nlpdebug's ed25519 host key nor any key exchange its OpenSSH 10.2 allows. It verifies uploads by MD5 checksum over `ssh` (exact, not just size). `ssh`/`sftp` prompt for the password once per connection (up to two per run); an SSH key (`ssh-keygen -t ed25519`, then `ssh-copy-id nlpadmin@nlpdebug.org`) removes the prompts. **Never test `deploy.sh` with the real `ssh` reachable** — a botched simulation on 2026-11-17 made three failed logins against nlpdebug. The sftp profiles have no stored password, so an upload waits on a password box at the top of the VS Code window — if the spinner never stops after "Using profile", that box was dismissed; reload the window and retry. The extension also gives **no clear per-file failure status** — some uploads failed silently on 2026-11-17 — so prefer `.vscode/deploy.sh <nlpdebug|sandbox> [--check] [--all | --since <ref>] [files]` (gitignored, run by the user in git-bash; prompts for the password): it reports OK/FAILED per file, then verifies sizes against the server; `--check` uploads nothing and lists stale files. VS Code uploads go through the `natizyskunk.sftp` extension, configured with one profile per site in `.vscode/sftp.json` (gitignored). Its FTP library defaults every timeout to 10s and, when the passive data connection times out, just sends `ABOR` with **no visible error** — the file silently isn't uploaded (seen 2026-11-17 on a connection kept alive >1 hour). `sftp.json` therefore sets `"connTimeout": 30000, "pasvTimeout": 30000, "keepalive": 60000`; after changing it, **Developer: Reload Window** (config is read at startup, and this also drops a stuck connection). A file uploaded only if its `> STOR …` line in the SFTP output log is followed by `< 226 Transfer complete.`
- **The sandbox's module is installed by composer from GitHub** (`drupal/nlpservices:dev-NLP_Services_V7_3`, as the production sites will be after December), and FTP uploads to it are still used on purpose, to try out a single file before committing it. The next `composer update drupal/nlpservices` replaces those uploads with the branch's version, which is intended; don't suggest stopping FTP deploys to the sandbox.
- **`ftp.oregonnlp.org`, `ftp.specialnlp.org`, `ftp.mtdemsnlp.org`, `ftp.mississippinlp.org`** — production sites. specialnlp is **held in reserve for special elections** (unused during the 2026 election, none coming up), so it's production but idle. **All four will be on Drupal 11 and V7_3 after December 2026.** Until that migration happens, don't deploy V7_3 code to them piecemeal (V7_3 needs Drupal 11).
  - oregonnlp, mtdemsnlp and mississippinlp are believed to be on frozen `NLP_Services_V7_2_1` / Drupal 10 — **not yet confirmed site by site**; check `drush status --field=drupal-version` and the module's `nlpservices.info.yml` before relying on this.
  - **specialnlp is not**: on 2026-11-22 it was found already on Drupal 11 and an early V7_3 (composer-managed, at `ba8adae`; private files already at `~/private-files`); nobody recalls upgrading it. The same day it was patched to core 11.4.13 and brought up to the current V7_3 with `composer update drupal/nlpservices` (updates 10006–10008 ran; 10005 had already run). That update also installed `drupal/token` through composer for the first time, so check for a second, hand-installed copy (`find web -name token.info.yml`). Still to do: remove the old public `web/sites/default/files/keys` once Step 2 verifies the keys. Because it never ran the V7_2_1 → V7_3 changes, it **can't stand in for the rehearsal** (checklist step 0, which must use a staging copy of a V7_2_1 site, e.g. oregonnlp).
  - **The repo is public.** Don't commit cPanel account names, key file names or other site specifics beyond what's needed; keep those in local notes.
  - V7_2_1 keeps API key files in the public `web/sites/default/files/keys`. Each production site has an `.htaccess` there that denies web access (checklist step 1); V7_3's private files make it unnecessary.

This app can't handle the FTP passwords (password-based auth, credentials live in PhpStorm's own store). If asked to help deploy, hand over ready-to-run `curl --ftp-ssl-reqd --ftp-pasv -u <user> -T <file> ftp://<host>:21/modules/custom/nlpservices/<path>` commands for the user to run themselves, or point them to PhpStorm's Deployment menu.

`NlpReadMe.md` documents full from-scratch site setup (SSL, database, private file path, required contrib modules — autologout, mpdf, bartik, token, smtp, real_aes, key, encrypt — SMTP config, theme setup). `readme.md` is just Drupal core's stock README (not NLP-specific) — ignore it.

## Local dev environment

This module lives in its own disposable Drupal 11 scaffold at `C:\DemocraticPartyofOregon\drupal-site` (git-bash: `/c/DemocraticPartyofOregon/drupal-site`), checked out at `web/modules/custom/NLP_Services` (note: directory name is `NLP_Services`, machine name is `nlpservices`). The ddev project is named **`nlp-dev`**; its containers are `ddev-nlp-dev-web` and `ddev-nlp-dev-db`. (It used to share the `dog-check-dev` scaffold with `dog_check`; that's no longer the case — `dog_check` isn't in this site.)

```bash
cd /c/DemocraticPartyofOregon/drupal-site
ddev start
ddev launch                          # http://nlp-dev.ddev.site
ddev drush uli                       # admin/admin
```

**Runtime deps**: `ext-imap` (declared in `.ddev/config.yaml` as `webimage_extra_packages: [php8.3-imap]`, and loaded in the current container as of 2026-11-15), `smalot/pdfparser` and `mpdf/mpdf` (in the site's root `composer.json`). `guzzlehttp/guzzle` comes transitively from Drupal core.

**`$settings['file_private_path']` is required to install the module** — it stores API key files under `private://` and refuses to install without it. As of 2026-11-15 the `nlp-dev` site does **not** have it set and the module isn't enabled there (the test suites don't need either). To install it, append after the `settings.ddev.php` include in `web/sites/default/settings.php`:
```php
$settings['file_private_path'] = '/var/www/html/private-files';
```
and create that directory inside the container.

### `ddev` CLI is unreliable on this machine — use `docker exec` directly

On this Windows/git-bash setup (installed without admin rights; since the 2026-11-22 upgrade, DDEV 1.25.6 lives in `~/AppData/Local/Programs/DDEV` and Docker Desktop 4.99 in `~/AppData/Local/Programs/DockerDesktop`, not `~/bin`), state-changing `ddev` subcommands (`ddev restart`, `ddev composer require`, `ddev mutagen sync`) reliably **hang indefinitely with zero output** — confirmed by checking for the underlying process inside the container (never spawned) and on the host (an orphaned `ddev` process that has to be killed manually). Read-only commands (`ddev describe`, `ddev list`) work fine.

**`ddev start` does work** (2026-11-22), unless a DDEV installer is still running in the background: the 1.25.6 installer reported success but its process stayed alive, and `ddev start` hung after `Starting nlp-dev...` until that process was ended in Task Manager and the installer rerun. A DDEV upgrade removes the project's containers but keeps its images and volumes (including `nlp-dev-mariadb`), so `ddev start` is all that's needed afterwards. `DDEV_DEBUG=true ddev start` shows where a hang happens. The `nlp-dev` Drupal site itself is **not installed** (the `db` database is empty and `/` redirects to `install.php`); the test suites don't need it.

**Workaround: bypass the `ddev` wrapper and talk to the container directly.** This worked instantly every time it was tried:
```bash
# Any docker command with a path argument (e.g., -w /var/www/html) needs this env var
# on git-bash/MSYS, or the path gets mangled into a Windows path before docker sees it:
MSYS_NO_PATHCONV=1 docker exec -w /var/www/html ddev-nlp-dev-web composer require <pkg>
MSYS_NO_PATHCONV=1 docker exec -w /var/www/html ddev-nlp-dev-web vendor/bin/drush <cmd>
MSYS_NO_PATHCONV=1 docker exec -w /var/www/html ddev-nlp-dev-web vendor/bin/phpunit -c web/modules/custom/NLP_Services/phpunit.xml --testsuite nlpservices
```
Mutagen file sync also appears to silently stop propagating container→host changes after this kind of manual intervention — after editing files inside the container (composer.json, settings.php), reconcile the host copies explicitly with `docker cp ddev-nlp-dev-web:/var/www/html/<file> "<host-path>"` rather than trusting sync to catch up.

If the site stops serving after a `docker restart ddev-nlp-dev-web`, check `docker exec ddev-nlp-dev-web ps aux | grep -E 'nginx|fpm'` and start whichever is missing (`php-fpm8.3 -D` / `nginx`) — this happened on the old scaffold after a live apt-install of imap; with imap now baked into the image it may no longer occur. If "Call to undefined function imap_open()" ever appears, the container was built without the `webimage_extra_packages` entry.

### Composer setup — mind the scaffold plugin

This module's `composer.json` **allows** the scaffold plugin and is used for both IDE support *and* running the real test suite (`require-dev` pulls in `phpunit/phpunit` and `drupal/core`). Because scaffold is allowed, running `composer install`/`composer update` directly in this module directory dumps a full Drupal skeleton into it (`modules/`, `profiles/`, `recipes/`, `sites/`, `themes/`, plus loose files like `index.php`, `.htaccess`). The existing `.gitignore` already excludes the loose top-level files, but **not** the directories — after any composer operation here, check `git status` and `rm -rf modules profiles recipes sites themes` if they reappear (confirmed happening 2026-09-12; also touches `.gitattributes`/`.gitignore` via merge but with no real content, and safely revertible with `git checkout -- .gitattributes`).

Also: `phpunit/phpunit: ^10` in this module's own `require-dev` is now **stale** relative to Drupal 11.4 — Drupal 11.4's `drupal/core-dev` requires PHPUnit ^11.5, and the version mismatch causes a hard crash (`Call to undefined method ... identifyIssueTrigger()`) if you run the module's own bundled `vendor/bin/phpunit`. **Run tests through the site's PHPUnit (from `drupal/core-dev`) instead**, pointed at this module's config:
```bash
MSYS_NO_PATHCONV=1 docker exec -w /var/www/html ddev-nlp-dev-web vendor/bin/phpunit -c web/modules/custom/NLP_Services/phpunit.xml --testsuite nlpservices
```
Pass `--testsuite nlpservices` — running phpunit with no testsuite also picks up the Kernel tests without their required env vars (below), giving ~18 spurious errors. This requires `drupal/core-dev:^11.4` on the **site's root** `composer.json` (it's there).

As of 2026-11-22: Unit suite passes — **3357 tests, 8696 assertions, 0 failures, 2 skipped** (the two fixture-replay tests, see Tier 2 below). Kernel suite: 25 tests, **1 known failure** in `DatabaseFunctionsTest::testGetColumnNamesReturnsRealColumnsInOrder` (column-order mismatch, not yet investigated).

### Kernel tests (added 2026-09-13)

Unlike the rest of the suite, `tests/src/Kernel/` (currently `DatabaseFunctionsTest`, `NlpMcidRecoveryTest`, `McidReassignmentTest`, `TurfSyncCleanupTest`) boots the real Drupal kernel against a real database rather than mocking `Connection`/`Select`/`Merge` — see the class-level docblocks for why each was picked (e.g. for why each was picked (`DatabaseFunctions` runs raw MySQL-only SQL — `SHOW COLUMNS`, `information_schema.COLUMNS` — that a mock can't validate at all; `NlpMcidRecovery` is a security-sensitive account-recovery flow where the real risk is in boundary conditions mocks always get to skip).

Two things Kernel tests need that Unit tests don't:

1. **A real MySQL/MariaDB connection**, because `DatabaseFunctions`' raw SQL doesn't understand SQLite (Drupal's default `SIMPLETEST_DB` fallback). Point it at ddev's own database container:
   ```bash
   MSYS_NO_PATHCONV=1 docker exec -w /var/www/html -e SIMPLETEST_DB=mysql://db:db@db/db -e NLPSERVICES_KERNEL_BOOTSTRAP=1 ddev-nlp-dev-web vendor/bin/phpunit -c web/modules/custom/NLP_Services/phpunit.xml --testsuite nlpservices-kernel
   ```
2. **`NLPSERVICES_KERNEL_BOOTSTRAP=1`** — `tests/bootstrap.php` unconditionally stubs `nlp_debug_msg()`, `nlp_debug_msg_safe()`, `batch_set()`, and several `NLP_*` constants for the Unit tests' benefit (they don't boot a kernel, so nothing else defines these). A Kernel test boots the real kernel, which loads the module's actual `nlpservices.module` (the real `nlp_debug_msg()` etc.) and core's `form.inc` (the real `batch_set()`) — without this env var gating those stubs out, you get fatal "Cannot redeclare" errors. Omit the var for the `nlpservices` (Unit) testsuite; it must be set for `nlpservices-kernel`.

Run the full suite (both testsuites) by invoking phpunit twice with/without these env vars — there's no single invocation that satisfies both, since the Unit suite doesn't have `drupal/core-dev`'s MySQL fixture running by default and doesn't need it.

### Tier 2 API fixture tests (added 2026-09-13)

The `Api*` classes (`ApiVoter`, `ApiNls`, and eventually the rest) talk to the vendor's VAN-style REST API, protected by a per-committee key the module itself encrypts and stores (via `ApiKeyManagement`/the Key module). The vendor provides a sandbox for safe testing, but nothing in this app should ever see a real key — see the design discussion that led here for the full reasoning. The approach has two tiers:

- **Tier 1 (not yet built)**: live sandbox integration tests, gated by an env var holding the sandbox key, that skip themselves when the var is absent.
- **Tier 2 (this)**: fast, network-free tests that replay a *real, previously captured* sandbox response through the class's actual parsing logic and diff the result against a frozen snapshot. This exists specifically because the pre-existing `ApiVoterTest`/`ApiNlsTest` hand-type their fake API responses (see `ApiVoterTest::baseApiResult()`) — a hand-typed array only proves the parser handles the shape a developer imagined, not the vendor's actual field casing, null patterns, or extra/missing fields.

**Capturing fixtures** (`tests/bin/record-api-fixtures.php`, run via `drush scr` **on a live site** with sandbox credentials configured — never from this dev environment, which has none):
```bash
drush scr web/modules/custom/nlpservices/tests/bin/record-api-fixtures.php -- voter <vanid> [committee]
drush scr web/modules/custom/nlpservices/tests/bin/record-api-fixtures.php -- nl <mcid> [committee]
```
It reuses the exact same `nlpservices.api_key_management`/`http_client` services `ApiVoter`/`ApiNls` already use, so the decrypted key is used entirely inside that one PHP process on the live site and is never printed, logged, or written anywhere (the script deliberately avoids echoing raw exception messages, since Guzzle's connection/auth error messages embed the full request URI — which embeds the key as HTTP Basic Auth). It writes two files per capture under `tests/fixtures/api/{voter,nl}/`: the raw sandbox response body, and a frozen snapshot of what this module's own `processVoterResult()`/`processNlResult()` (invoked via reflection, since they're private) produced for it. Both are safe to commit — sandbox data, no key, no URL.

**Replaying them**: `tests/src/Unit/Service/ApiVoterFixtureReplayTest.php` and `ApiNlsFixtureReplayTest.php` glob whatever fixtures exist, mock the Guzzle client to return the raw captured body, call the real public method, and assert the result still matches the frozen snapshot. **This repo currently ships zero real fixtures** (this dev environment has no live sandbox access), so both tests skip cleanly with a message pointing at the recorder — that's expected, not a failure. Once someone runs the recorder on a live site and commits the resulting fixture files, these tests activate automatically with no code change needed. If a vendor contract change ever breaks something, re-running the recorder regenerates both files and the resulting diff shows exactly what changed.

## Key files

- `nlpservices.routing.yml` — ~60+ routes; `/nlpservices-welcome` is the module's landing page (`Controller\WelcomeController`).
- `NlpReadMe.md` — the real setup/deployment doc (not `readme.md`, which is Drupal core boilerplate).
- `src/Config/` — admin configuration forms (API, election, email, survey questions, response codes, activist codes, cron tasks, document config).
- `src/Batch/` — long-running batch operations (imports, exports, sync jobs for turf/NLs/matchbacks).
- `src/Nlp*.php` (dozens of files at `src/` top level) — the bulk of the domain logic: turf assignment/display/sync, voter data entry and lists, PDF generation (`PdfGenerator.php`, `NlpPdfParser.php` via `smalot/pdfparser` + `mpdf/mpdf`), email (`NlpSendEmail.php`, SMTP-dependent), IMAP inbox processing (`NlpImap.php`, needs `ext-imap`), encryption (`NlpEncryption.php`).
- `src/Api*.php` — Guzzle-based API clients (activist codes, export jobs, folders, key management/verification, map regions, NLs, response codes, saved lists, survey questions, voter data).
- `tests/bootstrap.php` — boots Drupal's own `core/tests/bootstrap.php` before defining test stubs/constants (`NLP_MODULE`, role IDs, `user_load_by_*` stubs, a fake `t()`). Because it chains into Drupal's real bootstrap, tests need the site's PHPUnit 11, not the module's own vendored PHPUnit 10 (see above).
