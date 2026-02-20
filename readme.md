# Setting up a new site
*Rev 7.0 (9 Sept 2025)*  

If you are to have a secure site, purchase and install an SSL certificate. This will permit the use of the https scheme.   If you want users who attempt to access the site using http to be automatically switched to https. Find the .htaccess file in the public_html directory.   Edit this file and find the line RewriteEngine on.  Immediately after that line, add the following three lines. 

    RewriteCond %{SERVER_PORT} 8  
    RewriteCond %{HTTP_HOST} ^(www.)?mysite.sfx  
    RewriteRule ^(.*)$ https://www.mysite.sfx/$1 [R,L]

Install the latest version of Drupal 10 using Composer.  Using Composer for the core provides the ability to use it for the many available modules.  One of which is used by NLP Services and requires Composer.

First, install a TTY console on your local system such as PuTTY.  Access your account on your hosting server and get the TTY login: Username and Password. Then login into the user using PuTTY and the login credentials.

Then install Composer on your site.

    echo 'alias composer="php -d allow\_url\_fopen=On ${HOME}/composer.phar"' >> ~/.bashrc  
    source ~/.bashrc  
    cd ~  
    curl -k -O https://getcomposer.org/installer  
    php -d allow\_url\_fopen=On installer  
    composer -V  
    composer self-update

## Setting up the database:

Before installing the Drupal core, set up a database for your site.   Follow the instructions for creating the database from the Drupal website.  This process will create a database name and specify or create a username and password.  Save these. For example:

    Database name:  mysite_drup002  
    Username:       dbadmin  
    Password:       something secure

Now install the core Drupal:

Use your TTY app to redefine the public html directory (In the commands below, change Oregon to your state):

    mv ~/public_html ~/public_html_backup  
    ln -s ~/oregon/web/ ~/public_html

Then use composer to get the core:

    composer create-project drupal/recommended-project oregon

Once composer has finished, go to your sites main page:  mysite.sfx, e.g. oregonnlp.org.

There you will complete the installation of Drupal.   You will need the information about the email for the administrator and the Drupal database.


## Control who can create an account

From the admin menu, select Configuration.  In the PEOPLE section, select Account settings.  In the "Registration and cancellation" section, use the "Who can register accounts" to select "Administrators only".  This removes the option to request a new account on the user login block.  And, it removes the most common way that trolls attempt to get access to the site.

## Upload the NLP Services module

NLP Services is distributed as a compressed file.   Using FTP or File Manager, upload the NLP Compressed file to the directory oregon/web/modules/custom.   Then decompress the uploaded file in that directory to create the nlpservices directory.  When completed, you can delete the compressed file.

## Install and enable nlpservices module

Use the Extend tab to install the NLP Services module.

## Configuring Drupal Theme

NLP Services was developed and tested with the Bartik theme.  There should be no specific dependency on theme but the Bartik standard options produce pleasing NLP displays.

The NLP Services pages all assume they have the full width to display information.  If the site has any blocks on either the right or left sidebars, suppress display of these sidebar blocks.  From the Admin menu, navigate to Structure, Block layout, find the sidebar blocks that are active.  Click on configure for each and set the "Show block on specific pages" to except and enter nlp*.

From the menu bar, click Appearance, select Bartik as the default theme and then click Settings for the Bartik theme. Remove the selection for the default logo and use the one provided by NLP Services.  It can be found at modules/custom/nlpservices/img/nlp_logo.png.  Then unselect the favicon option and use this one:  modules/custom/nlpservices/img/nlp_favicon.ico.  Then click Save configuration.

From the menu bar, click Configuration and then click the Site Information link.   In Site Details set the Site name to NLP Services and the E-mail address to notifications@nmysite.sfx.

## Now install the support modules.

This version of NLP Services uses three additional modules: "SMTP Authentication Support", "Automated Logout", and "User Redirect".  These modules should be installed using composer.

    composer require 'drupal/smtp:^1.2'  
    composer require 'drupal/autologout:^1.4'  
    composer require 'drupal/user\_redirect:^2.0'
    composer install
    composer require mpdf/mpdf

The SMTP module must be configured to permit the nlpservices module to send emails.   You will have to get the SMTP information from the email vendor. To use the SMTP module you will have to set an email account.  This account is for sending emails and can be named notifications@nlpservices.org.  The email name is configurable and includes the actual name of the site, i.e. notifications@mysite.com. Your vendor hosting the site will provide an adequate service, and you should use it. When you create the email account, you will need to get the following from your vendor:
```
SMTP server -  typically mail.mysite.sfx  
SMTP port - typically 25
email address - notifications@mtsite.sfx  
password - to log into the email account.  
E-mail from address - The same as the email you created.
```
The use of the site's email and the setup will help ensure delivery of emails. This helps avoid spam filters.

Configure the autologout module to inhibit auto logout for the administrator and the NLP Admin.

The redirect module should not be installed at this time.  There is a fix needed to support the request for a new password.
## Configure NLP Services

When logged in as the NLP Admin, click on the Drupal Configuration menu item then select the NLP Services Configuration.   The config functions are non-destructive and can be run multiple times.

### Step 1: Create and prep file folders.

The first step is to create the file folders for information for the counties. A file of county names and associated house districts in a YAML for is needed. Locate the example YAML file and edit it for your state. The example YAML can be found in the nlpservices/src/config folder and is named: county_names_oregon.yml.  It has the format of:

    State: 'Oregon'  
    Baker: [58,60]  
    Benton: [15,16,23]

### Step 2: Build the database for API keys.

NLP Services reports voter contacts to VoteBuilder and this requires authorization for each committee.  A YAML formatted file is used to input the needed authorization credentials.   The credentials are provided by VANNGP and include the Url, App Name and API Key.  The format is as follows:
```
State Committee:  
  Url: "api.securevan.com/v4"  
  App Name: "dpo.neighbors.api"  
  API Key: "xxxx919d"  
Posey:  
  Url: "api.securevan.com/v4"  
  App Name: "dpo.neighbors.api"  
  API Key: "yyyy4ffe"  
Vanderburgh:  
  Url: "api.securevan.com/v4"  
  App Name: "dpo.neighbors.api"  
  API Key: "zzzz874f"
```     
### Step 2a: Verify that the API Keys work.
If an API key is not used regularly, it will be invalidated by VANNGP and a new key is needed to restore functionality.  You can invoke this function at any time and validate the assigned keys.  Testing keys should happen quarterly to key them valid.
### Step 3: Set up the activist codes.

Once the State Committee key is available to NLP Services, the available activist codes can be displayed and a selection made for NLP Voter and NLP Hostile.

### Step 4: Set up the canvass response codes.

Similarly, for response codes, the available list is displayed and a subset selected.

### Step 5: Set up the election date and name.

An election cycle has an identifier in the form of yyyy-mm-t, where t is G, P, S or U.  These signify General, Primary, Special and Undefined. Typically, U is for a ballot measure.  The election is also defined by the date, the date when early ballots are sent, and the date when NLs should be making voter contact and reporting results.  The purpose of these dates is to get the NL participation rate above 80%.

### Step 6: Select the survey question.

The survey question provides a method of recording the "pledge to vote" question. The question is new for each election so this function provides a method for selection.

### Step 7: Set up the email used by the site.

The email address used to send turfs to the NLs is set up here.
