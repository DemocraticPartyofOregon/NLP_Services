# Setting up a new site

*Rev 7.3 (8 Nov 2026)*

If you are to have a secure site, purchase and install an SSL certificate. This will permit the use of the https scheme.   If you want users who attempt to access the site using http to be automatically switched to https. Find the .htaccess file in the public_html directory.   Edit this file and find the line RewriteEngine on.  Immediately after that line, add the following three lines.

    RewriteCond %{HTTPS} off
    RewriteCond %{HTTP_HOST} ^(www.)?nlpdebug.org
    RewriteRule ^(.*)$ https://www.nlpdebug.org/$1 [R,L]

Install the latest version of Drupal 11 using Composer.  Using Composer for the core provides the ability to use it for the many available modules.  Several of which are used by NLP Services and require Composer.

First, install a TTY console on your local system such as PuTTY.  Access your account on your hosting server and get the TTY login: Username and Password. Then login into the user using PuTTY and the login credentials.  Some hosting services provide a terminal app with cPanel.  Using that is
typically easier.

Then install Composer on your site.

    mkdir myStateName
    cd myStateName

    echo 'alias composer="php -d allow\_url\_fopen=On ${HOME}/composer.phar"' >> ~/.bashrc
    source ~/.bashrc
    cd ~
    curl -k -O https://getcomposer.org/installer
    php -d allow\_url\_fopen=On installer
    composer -V
    composer self-update

## Setting up the database

Before installing the Drupal core, set up a database for your site.   Follow the instructions for creating the database from the Drupal website.  This process will create a database name and specify or create a username and password.  Save these. For example:

    Database name:  mysite_drup002
    Username:       dbadmin
    Password:       something secure

Now install the core Drupal:

Use your TTY app to redefine the public HTML directory (In the commands below, change Oregon to your state):

    mv ~/public_html ~/public_html_backup
    ln -s ~/myStateName/web/ ~/public_html

Create a directory for the private files for saving API keys and set the permissions:


    mkdir -p private_files/keys
    chmod 755 private_files private_files/keys

    mkdir -p /home/myStateName/private_files/keys
    chown -R www-data:www-data /home/myStateName/private_files
    chmod 755 /home/myStateName/private_files /home/myStateName/private_files/keys

Then use composer to get the core:

    composer create-project drupal/recommended-project myStateName

Once the composer has finished, go to your site's main page: mysite.sfx, e.g., oregonnlp.org.

There you will complete the installation of Drupal.   You will need the information about the email for the administrator and the Drupal database.

## Control who can create an account

From the admin menu, select Configuration.  In the PEOPLE section, select Account settings.  In the "Registration and cancellation" section, use the "Who can register accounts" to select "Administrators only".  This removes the option to request a new account on the user login block.  And, it removes the most common way that trolls attempt to get access to the site.

## Upload the NLP Services module

NLP Services is distributed as a compressed file.   Using FTP or File Manager, upload the NLP Compressed file to the directory oregon/web/modules/custom.   Then decompress the uploaded file in that directory to create the nlpservices directory.  When completed, you can delete the compressed file.

## FTP deploy account permissions

If ongoing deploys (e.g., from PhpStorm's Deployment feature, as used for ORDems' own live sites) will use a dedicated FTP account rather than the SSH/install account, that FTP account needs write access into the tree the site was installed under, not just into new files. The Drupal install itself (composer, git, drush) is normally run over SSH as your own login (e.g., `sjpacker`), which leaves every file owned by that user. A separate FTP-only account created later for automated uploads (e.g., via vsftpd, running as e.g., `ftpuser`) is a different Linux user and, unless explicitly granted access, can only create new files inside directories it has write permission on — it cannot overwrite files it doesn't own or share a group with. This is easy to miss because the very first full-tree deploy succeeds (it's all new files); it's only a later deploy that re-uploads an existing file that fails, with something like `553 Could not create file`.

To avoid this, when the FTP account is created, add it to the group that owns the installed site files and make that tree group-writable:

    sudo usermod -aG sjpacker ftpuser
    sudo chmod -R g+w /home/myStateName/myStateName

Replace `sjpacker` and `ftpuser` with the actual install user and FTP user for the site. The FTP client (PhpStorm) needs a fresh login after this to pick up the new group membership.

## Add the private file path to the settings.php file

Do this before installing NLP Services — the module won't install without it. Edit the `settings.php` file located in `myStateName/web/sites/default/` and add the following line:

    $settings['file_private_path'] = '/home/myStateName/private_files';

Then clear all caches (Configuration > Performance > Clear all caches, or `drush cr`). Drupal only registers the `private://` file system when it rebuilds its caches, so until then API keys can't be saved even though the setting is in place.

## Install and enable nlpservices module

Use the Extend tab to install the NLP Services module.

## Now install the support modules

This version of NLP Services uses three additional modules: "SMTP Authentication Support", "Automated Logout" and "User Redirect".  These modules should be installed using composer.

    composer require 'drupal/autologout:^1.4'
    composer install
    composer require mpdf/mpdf
    composer require 'drupal/bartik:^1.1'
    composer require 'drupal/token:^1.17'
    composer require 'drupal/smtp:^1.4'
    composer require 'drupal/real_aes:^2.6'
    composer require 'drupal/key:^1.22'
    composer require 'drupal/encrypt:^3.2'

The SMTP module must be configured to permit the nlpservices module to send emails.   You will have to get the SMTP information from the email vendor. To use the SMTP module you will have to set an email account.  This account is for sending emails and can be named <notifications@nlpservices.org>.  The email name is configurable and includes the actual name of the site, i.e. <notifications@mysite.com>. Your vendor hosting the site will provide an adequate service, and you should use it. When you create the email account, you will need to get the following from your vendor:

    SMTP server -  typically mail.mysite.sfx
    SMTP port - typically 25
    email address - <notifications@mtsite.sfx>
    password - to log into the email account.
    E-mail from address - The same as the email you created.

The use of the site's email and the setup will help ensure delivery of emails. This helps avoid spam filters.

Configure the autologout module to inhibit auto logout for the administrator and the NLP Admin.

Configure the editor so the NLP Admin and the NLP Coordinator have the "Full HTML" option available.  This is needed to permit the use of the "nlpservices" text format for the content that is used in the emails sent to the NLs.  Also, add additional buttons to the toolbar for the "Full HTML" format.  The buttons to add are: "Insert image", "Insert link", and "Remove link".  This will allow the use of images and links in the emails sent to the NLs.

Go to Configuration->User Interface->Navigation Settings.  Choose Custom logo and upload drupal_menu_logo.png.  It can be found at modules/custom/nlpservices/img/drupal_menu_logo.png.

## Set up the cron job in cPanel

NLP Services does its background work on Drupal cron: processing MiniVAN and matchback emails, forwarding notification replies, and the turf and activist code checks.  Each run does about 3 minutes of work, so cron must run every 15 minutes from a cron job on the server.  Drupal's automated cron runs only when someone visits a page and is not enough.  Set up the cron job before enabling any of the cron tasks.

In cPanel, open Cron Jobs (in the Advanced section).  Under "Add New Cron Job", set "Common Settings" to "Once Per Fifteen Minutes (*/15 * * * *)".  In the Command box, enter the following on one line:

    cd /home/myAccount/myStateName && /usr/local/bin/php vendor/drush/drush/drush.php --quiet --uri=https://www.mysite.org cron

Replace `myAccount` with the cPanel account name, `myStateName` with the directory the site was installed in (the one holding `vendor` and `web`), and `https://www.mysite.org` with the site's address.  Then click Add New Cron Job.

Notes on the command:

- Use `/usr/local/bin/php`.  It is the host's PHP selector and picks the PHP version set for the account.  Calling a PHP version directly, such as `/opt/alt/php83/usr/bin/php`, fails from cron.
- Use `vendor/drush/drush/drush.php`.  `vendor/bin/drush` and `vendor/drush/drush/drush` are shell scripts, so PHP prints them instead of running cron.
- `--uri` is needed, or Drupal builds links in emails with `http://default`.
- `--quiet` keeps cron from emailing the output of every run.  To see errors while testing, leave it off and set the Cron Email at the top of the Cron Jobs page.

Once the cron job runs, turn off automated cron: from the Admin menu, select Configuration, then Cron in the SYSTEM section, set "Run cron every" to Never and click Save configuration.

To check the cron job, wait 15 minutes and open the Enable Cron tasks page from the NLP configuration menu.  It shows when the cron job last ran, or a caution if no run has been seen in the last hour.

## Add a drush alias for the SSH shell

On cPanel hosts, running `drush` at the SSH prompt prints a page of `sh: error importing function definition` lines before its real output.  These come from helper functions (`which`, `module`, `scl`) that the cPanel login shell exports and that drush's subshell can't read.  They are harmless, but running drush through PHP directly, the same way the cron job does, avoids them.  To make that the default, add an alias to the account's `~/.bashrc`:

    echo "alias drush='/usr/local/bin/php /home/myAccount/myStateName/vendor/drush/drush/drush.php'" >> ~/.bashrc
    source ~/.bashrc

Replace `myAccount` and `myStateName` as in the cron command.  After this, `drush cr`, `drush uli`, and so on work quietly from any directory and always act on this site.

- If the alias isn't there after logging in again, the account's `~/.bash_profile` doesn't read `~/.bashrc`.  Add the same line to `~/.bash_profile` instead.
- The alias only applies at the SSH prompt.  Cron jobs and scripts don't see it, so they still need the full command.

## Configuring Drupal Theme

NLP Services was developed and tested with the Bartik theme.  There should be no specific dependency on theme but the Bartik standard options produce pleasing NLP displays.

The NLP Services pages all assume they have the full width to display information.  If the site has any blocks on either the right or left sidebars, suppress display of these sidebar blocks.  From the Admin menu, navigate to Structure, Block layout, find the sidebar blocks that are active.  Click on configure for each and set the "Show block on specific pages" to except and enter nlp*.

From the menu bar, click Appearance, select Bartik as the default theme and then click Settings for the Bartik theme. Remove the selection for the default logo and use the one provided by NLP Services.  It can be found at modules/custom/nlpservices/img/nlp_logo.png.  Then unselect the favicon option and use this one: modules/custom/nlpservices/img/nlp_favicon.ico.  Then click Save configuration.

From the menu bar, click Configuration and then click the Site Information link.   In Site Details set the Site name to NLP Services and the E-mail address to <notifications@nmysite.sfx>.

## Configure NLP Services

Navigate to the Manage County menu item.  Look for the link for downloading documents.  Click that and download the NlpConfiguration document and finish setting up NLP Services.
