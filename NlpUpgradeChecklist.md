# Upgrading a site from Drupal 10 / V7_2_1 to Drupal 11 / V7_3

This is the checklist for the in-place upgrade of the production sites (oregonnlp.org first, then the other three).  Each site keeps its database, users and content.  For a brand-new site, follow NlpReadMe.md instead.

Throughout, `myAccount` is the cPanel account, `myStateName` is the directory holding `composer.json`, `vendor` and `web`, and `drush` is the alias from "Add a drush alias for the SSH shell" in NlpReadMe.md.  Run every command over SSH from `/home/myAccount/myStateName` unless it says otherwise.

## Before the first site: fix the module's own requirements

Two things in V7_3 itself must be fixed before step 5 works on any site:

- [x] `composer.json` requires `drupal/autologout: ^1.4`, which keeps composer from installing V7_3 next to the autologout 2.x that step 4 installs.  It must allow `^2.0`.
- [x] `nlpservices.info.yml` says `core_version_requirement: ^10 || ^11`, but V7_3 uses code that exists only in Drupal 11.2 and later.  It should say `^11.2`, so Drupal refuses to enable V7_3 on an older core instead of failing with a PHP error.

## 0. Rehearse on a copy first

Do the whole checklist on a copy of the site before doing it on the real one.  The sandbox can't stand in for this: it is already on V7_3, so it never runs the V7_2_1 to V7_3 database updates in step 6.

- [ ] In cPanel, create a subdomain (for example `staging.oregonnlp.org`) and an empty database.
- [ ] Copy the site directory: `cp -a ~/myStateName ~/myStateName-staging`.
- [ ] Copy the database: `drush sql:dump > ~/staging.sql`, then import it into the new database with `mysql -u <user> -p <newdb> < ~/staging.sql`.
- [ ] In the copy's `web/sites/default/settings.php`, change the database settings to the new database, and add the subdomain to `trusted_host_patterns`.
- [ ] Point the subdomain's document root at `~/myStateName-staging/web`.
- [ ] Turn off the copy's cron tasks (Enable Cron tasks page) and don't add a cron job for it, so the copy doesn't process the real MiniVAN and matchback emails or email NLs.

Note anything that goes wrong on the copy here, so the real upgrade doesn't hit it.

## 1. Before you start

- [ ] Pick a quiet time.  Turn off the cron job in cPanel for the duration (comment it out), so nothing runs halfway through the upgrade.
- [ ] Back up the database: `drush sql:dump --gzip --result-file=$HOME/backup-$(date +%F).sql` (it writes `backup-<date>.sql.gz`).
- [ ] Back up the files: `tar czf ~/backup-$(date +%F)-files.tgz ~/myStateName`.  Or make a full cPanel backup (Files → Backup).
- [ ] PHP is 8.3 or newer for **both** the website (cPanel → Select PHP Version) and the command line (`php -v`).  Drupal 11 requires it.
- [ ] The imap extension is on for both: `php -m | grep imap`, and Select PHP Version → Extensions for the website.  Without it on the command line, composer refuses to install V7_3; without it on the website, the email cron tasks fail.
- [ ] Composer is version 2.7 or newer: `composer --version`.
- [ ] Composer has enough memory.  If a command stops with "Allowed memory size exhausted", run it as `php -d memory_limit=-1 $(which composer) ...`.

## 2. Find what blocks Drupal 11

- [ ] `composer require drupal/upgrade_status` then `drush en upgrade_status`.
- [ ] Open Reports → Upgrade status.  Scan the contrib modules and themes.  Note each one that isn't Drupal 11 compatible and the version that is.
- [ ] Drupal 11 removed some core modules (Actions UI, Book, Forum, Statistics, Tour, Activity Tracker among them).  If the site has any of them turned on, either uninstall them or `composer require` their contrib replacement before step 4.
- [ ] Ignore what Upgrade status says about `nlpservices` itself; V7_3 replaces it in step 5.

## 3. Bring Drupal 10 up to date

Drupal 11 can only be reached from a current Drupal 10.

- [ ] `composer update "drupal/core-*" --with-all-dependencies`
- [ ] `drush updb -y` then `drush cr`
- [ ] Reports → Status report shows no new errors.  The site still works.

## 4. Upgrade core to Drupal 11

- [ ] In `composer.json`, change these constraints (versions are those tested on the dev site in November 2026):

  | Package | Constraint |
  |---|---|
  | `drupal/core-recommended`, `drupal/core-composer-scaffold`, `drupal/core-project-message` | `^11.4` |
  | `drupal/autologout` | `^2.0` |
  | `drupal/encrypt` | `^3.3` |
  | `drupal/key` | `^1.23` |
  | `drupal/token` | `^1.18` |
  | `drush/drush` | `^13` |
  | anything step 2 flagged | the Drupal 11 version it named |

- [ ] `composer update --with-all-dependencies`.  If composer reports a conflict, it names the package holding things back; raise that constraint and run it again.
- [ ] `drush updb -y` then `drush cr`
- [ ] Reports → Status report: no errors, apart from any about NLP Services.
- [ ] Log in, open the NLP pages, check the site still runs on V7_2_1.  If it doesn't, go straight on to step 5 rather than debugging V7_2_1 on Drupal 11.

## 5. Install V7_3 with composer

The production sites got NLP Services by FTP.  From V7_3 on, composer installs and updates it from GitHub, so later updates are one `composer update` (see NlpReadMe.md, "Install NLP Services with composer").

- [ ] Move the old copy **out of the web tree**, so Drupal never sees two copies of the module: `mv web/modules/custom/nlpservices ~/nlpservices-V7_2_1`.
- [ ] `composer config repositories.nlpservices vcs https://github.com/DemocraticPartyofOregon/NLP_Services`
- [ ] `composer require 'drupal/nlpservices:dev-NLP_Services_V7_3'`
- [ ] Check it landed in the right place: `ls web/modules/custom/nlpservices/nlpservices.info.yml`, and `grep version web/modules/custom/nlpservices/nlpservices.info.yml` says `7.3`.

Don't run `drush updb` yet.

## 6. Move the API keys to private files, then update the database

V7_2_1 kept the API key files in `web/sites/default/files/keys`, which can be downloaded over the web.  V7_3 keeps them in the private files directory and won't run without one.

- [ ] Create the private directory outside `web`: `mkdir -p ~/myStateName/private/keys && chmod 750 ~/myStateName/private`.
- [ ] In `web/sites/default/settings.php`, set (or uncomment) `$settings['file_private_path'] = '/home/myAccount/myStateName/private';`
- [ ] `drush cr` so Drupal registers `private://`.
- [ ] Move the key files: `mv web/sites/default/files/keys/*.key ~/myStateName/private/keys/`.  Update 10002 repoints each key only if its file is already there, so do this **before** `drush updb`.
- [ ] `drush updb -y`.  These updates should run, with these messages:

  | Update | What it does | Message |
  |---|---|---|
  | 10001 | Creates `private://keys` | (none) |
  | 10002 | Points each key at its file in `private://keys` | (none) |
  | 10003 | Makes the NLP welcome page the front page | (none) |
  | 10004 | Adds `cd` to the ballot count key | Changed the nlp_ballot_count primary key to (cd, county, party). |
  | 10005 | Converts the county names list | Converted the county names to a list of N counties. |
  | 10006 | Adds `hd` to the ballot counts | Added nlp_ballot_count.hd. Changed the nlp_ballot_count primary key to (cd, county, hd, party). |
  | 10007 | Adds `nlNameNotFound` to the reports | Added nlp_results.nlNameNotFound. |
  | 10008 | Turns off the account activation email | Turned off the account activation email. |

  Write down the messages.  For 10005, N should be the number of counties the site had configured (all 36 for Oregon).
- [ ] `drush cr`
- [ ] Reports → Status report: no NLP Services errors (private file path, HTML email).
- [ ] NLP configuration → Step 2, "Verify the API keys work".  Every committee's key verifies.
- [ ] Only once Step 2 passes: `rm -r web/sites/default/files/keys`.

## 7. Set up what's new in V7_3

- [ ] NLP configuration → Step 3, the map keys for the walk sheets (see "Get the keys for the walk sheet map" in NlpReadMe.md).  Save, then "Test the stored keys".
- [ ] Update 10003 made the NLP welcome page the front page.  If the site used a different front page, set it back under Configuration → Basic site settings.
- [ ] Turn the cron job in cPanel back on.  After 15 minutes, the Enable Cron tasks page shows the last run.  Re-select the cron tasks the site used.

## 8. Test

- [ ] An NLP admin, a coordinator and an NL can each log in, and see their own pages.
- [ ] A coordinator's turf list, and assigning a turf to an NL.
- [ ] A walk sheet PDF: instructions, map page, voter pages.
- [ ] NL data entry: record a contact; the historical contacts show the NL's name.
- [ ] An email to an NL arrives, as HTML (SMTP).
- [ ] The cron email tasks: a MiniVAN or matchback email is processed (needs imap).
- [ ] Import a crosstab file.  The message gives the counts, counties and house districts.  The summary by county and by HD shows VoteBuilder counts.
- [ ] Reports → Recent log messages: no new PHP errors.

## 9. If it goes wrong

- [ ] Restore the files: `rm -rf ~/myStateName && tar xzf ~/backup-<date>-files.tgz -C /`.
- [ ] Restore the database: `gunzip < ~/backup-<date>.sql.gz | drush sql:cli`.
- [ ] `drush cr`, and turn the cron job back on.

Keep the backups and `~/nlpservices-V7_2_1` until the site has run cleanly for a couple of weeks.
