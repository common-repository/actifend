=== ActiFend Security Monitoring and Recovery ===
Contributors: DSDInfosec
Author URI: http://www.actifend.com/
Author: DSDInfosec
Tags: Security, Detection, Ransomware, Backup, Restore, Intrusion, Intrusion Detection, Vulnerability, Scan, Attack, Hack, BruteForce, Active Defense, Block, Event Correlation, DSD InfoSec, actifend, Alerts
Requires at least: 4.5
Requires at least php: 5.4
Tested up to: 4.8.2
Stable tag: 1.6.2
Version: 1.6.2
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

ActiFend Security Monitoring and Recovery is a web security plugin that detects attacks, blocks attackers, takes backups, alerts you of intrusions and restores from backups.

== Description ==

ActiFend Plugin has the ability to detect changes to your website as well as backup / restore files on request. This helps you recover immediately from hacks / ransomware attacks using the ActiFend Mobile App.

ActiFend defends your website through a powerful combination of this plugin, a security analytics platform in the cloud, and a mobile app. It detects attacks, blocks attackers, takes backups, detects changes to your website and enables one-click recovery using backups.

In short, ActiFend gives you the eyes & ears to monitor your website and its security. Untethered from you office and laptop. From the convenience of a mobile app.

Getting started is easy. Takes less than 2 minute to complete.

* Download and activate this plugin; or
* Download and follow the "add asset" instructions from within the mobile app.

You can try ActiFend for free. ActiFend comes with a 2 week free trial period. You also get a free vulnerability scan during the free period to detect the most common vulnerabilities in your website.

Annual subscription plan starts from as low as $4 per month.

= Key Features =

* 24 x 7 monitoring from your mobile phone. Attackers can attack anytime, so your monitoring can't be limited to your office time. With ActiFend Mobile App, you can monitor your web security anywhere, anytime.

* Attack detection and blocking
ActiFend detects common attacks and blocks the attackers. ActiFend also manages the attacker lists to ensure your website doesn't slow down for your visitors.

* Backup and restore
You can now backup your website from within your mobile app; and restore from any of the last 3 backups with just one click.

* Get an instant alert when your website is hacked
ActiFend detects website defacements (even if it is not visible on any of the web pages) and ransomware attacks - and alerts you instantly on your mobile phone. This allows you to restore from a healthy backup within seconds - avoiding embarrassing exposure to visitors and preserving your brand image.

* Get an alert when a plugin / theme you are using is outdated or vulnerable
ActiFend periodically checks your website for vulnerabilities and send you alerts on your mobile app. You also get to know whether the WordPress version, the plugins and themes you are using are old or vulnerable.

* Enterprise-grade Security Visibility
ActiFend uses a multi-tenant in-the-cloud extension of MozDef SIEM - the same platform that Mozilla uses for their own security.

*[SIEM]: Security Information and Event Management System

== Installation ==
1. Download and activate ActiFend plugin.
2. From left menu select the ActiFend tab if it is not already selected.
3. Link email to the registration
   * Provide your email address,
   * select the checkbox for use of the email address, and
   * click on "PROCEED TO FINAL STEP" button.

4. Download the Mobile App and login with any Google/LinkedIn/FaceBook account linked to the email address provided in step 2 above.
   * If all the above steps were completed successfully, your asset will appear automatically in the ActiFend Mobile app dashboard.

Note:
* We use your email address to authenticate you on the ActiFend mobile app and send security alerts.
* We do not share your email address with any third party.
* Our Privacy Policy: http://www.actifend.com/privacy.html

== Screenshots ==
1. ActiFend (before selection of the opt-in checkbox to complete activation)
2. ActiFend (after selection of opt-in checkbox to complete activation)
3. ActiFend (after completion of activation)

== Changelog ==
= 1.6.2 =
Improved file change detection to remove false positive alert when core update happens.

= 1.6 / 1.6.1 =
This release includes upgrades to your protection as well as support for more hosting service providers (who use NGINX for better performance).
1) Improved detection of bad bots
2) Improved protection against bots and attacks
3) Added support to WordPress hosted on NGINX (where .htaccess is not an option)

= 1.5.2 =
Bug fix in admin notices causing issues for non-admins.

= 1.5.1 =
Misc. bug fixes.

= 1.5 =
New: Recovery of file system in case of file integrity problems

= 1.4.7.4 =
Bug Fix in restore, because of folder looping

= 1.4.7.2 =
Revised cron execution order for the functions

= 1.4.7.1 =
Bug Fix in event scheduling

= 1.4.7 =
Improvements to the intrusion detection, backup and restore functions.

= 1.4.6.4 =
1) Bug Fix in the email linking form overlap with other pages
2) Added domain check against MX records at the time of linking email
3) Bug Fix in selecting files to Quarantine

= 1.4.6.3 =
Name change from ActiFend to ActiFend Security Monitoring and Recovery

= 1.4.6.1 =
Minor correction to email linking form to Actifend

= 1.4.6 =
Change in UX for the forms

= 1.4.5.2 =
Change in logic in identifying the current user.

= 1.4.5 =
1. Improved: Backup & Restore features
2. New: Incremental Backups -> Ability to take incremental backups via Actifend App.
3. New: Quick Restore -> Ability to restore incremental backups via Actifend app.
4. Improved: Full Restore -> Ability to restore both full and incremental backups together
5. New: Quarantine feature in case of an intrusion for further investigation.

= 1.4.4.3 =
1. Actifend-storage HEAD check, before upload, sometimes fails. Doing a recheck when it fails.
2. Added uploads folder to db backup.

= 1.4.4.2 =
Bug Fix in updating the status of the IPs that are banned

= 1.4.4.1 =
Reverted the change to email field in step 2 as it will set limitation

= 1.4.4 =
Strengthening of the email field in step 2

= 1.4.3 =
1. Daily database backups stored at Actifend-cloud upto last 3 days
2. Code refactoring to make the plugin more robust

= 1.4.2 =
Bug Fix in creation of file system baseline for integrity check

= 1.4.1 =
Bug Fix in log response code identification

= 1.4 =
1. UX refactoring for activation and onboarding
2. Introducing admin notices for onboarding
3. Plugin activation without providing email; optin as a second step
4. fix for occasional disappearence of cron events

= 1.3.7.2 =
1. Identifies onboarding completion
2. Code refactoring to make the plugin more robust

= 1.3.7.1 =
Bug Fix in the way folders are identified for checking privileges.

= 1.3.7 =
1. Introduced PclZip based compression when php-zip extension is not loaded
2. File integrity check now differentiates changes to folders and files and informs the mobile app user accordingly
3. Restoring a site backup, now, recognizes when permissions are insufficient to restore the files and lets admin do the restore manually. Database will be restored by Actifend.
4. Code refactoring to make the plugin more robust.

= 1.3.6 =
Bug fix for unexpected output errors.

= 1.3.5 =
Dependency on phar and curl extensions removed.

= 1.3.4 =
Updated the activation screen to clarify how email address is used and meet opt-in guidelines.

= 1.3.3 =
Updated to support windows installation of wordpress

= 1.3.2 =
Updated array definitions to not throw syntax errors if php version is less than 5.4

= 1.3.1 =
Updated file integrity monitoring to check folders as well

= 1.3 =
1. Backup Functionality takes a full backup of the wordpress site
2. Restore Functionality does a restore of a selected full backup
3. File Integrity Monitoring / Intrusion detection

= 1.2 =
1. Includes installed themes version information
2. New concise log format for the event logs

= 1.1.3 =
Changed the method used to send plugin info.

= 1.1.2 =
Enabled non-login events to be sent for events processing.

= 1.1.1 =
Bug Fix for argument in plugin version identification

= 1.1 =
Updated version with new features.

= 1.0 =
Initial Version

== Upgrade Notice ==
= 1.0 =
Initial Version


== Frequently Asked Questions ==

= How does ActiFend work? =
The ActiFend WordPress Plugin sends access events in real time from your web server to ActiFend. These events are processed to detect attacks. Any alerts generated in the process are delivered through the ActiFend Mobile App. More information on what data is collected and how it is used is described below.

= How is my email address used for Mobile App Authentication? =
On the ActiFend mobile app, when you authenticate through a Google / Facebook / LinkedIn account, ActiFend takes it as proof that the primary email address linked to that account is in your control. When that email address matches the email address you gave at plugin activation time, it is taken as proof that you have administrative control of that website. This linkage forms the basis for ActiFend to show you that website's securiy dashboard in the mobile app.

= What data is collected from my website and how is it used? =
1. During registration, your email address and your website meta-data are transmitted to the ActiFend server. These include the server hostname, server public name (FQDN) and server IP address.
2. During normal operation, your website visitor information such as their IP address, the URL they accessed, referrer and user-agent string are sent to ActiFend server (Security Analytics platform) for attack detection and alert generation.
3. Initially and later whenever the WP Core / a plugin / a theme is added or updated, their meta data (name and version number) is sent so that any known vulnerabilities can be identified and corresponding security alerts generated.
4. When a backup is requested through the Mobile App, the website data is compressed, encrypted and uploaded to a secure storage account on ActiFend infrastructure.
5. When any change is detected in the website content, the change meta data (directory and file names) is sent - so that a SUSPECTED INTRUSION alert can be generated by ActiFend.

NOTE: Any forms data submitted by your visitors to your website through a POST request is NEITHER captured NOR sent by this plugin to any servers in any manner.

= What other CMS / Web Platforms does ActiFend support? =
At this stage, WordPress is the first and only CMS supported by ActiFend. Support for other platforms is planned for near future.

= I run WordPress on an Apache / NGINX Server. Can I use ActiFend without the plugin? =
Yes, it is possible to use ActiFend on any Linux web server running Apache/NGINX without a plugin. However, automated defense, backup and recovery features are only available through the WordPress plugin. Windows / IIS Server support is planned for the future.

= What is an event (hit / access log entry)? =
When a visitor to your web site accesses any page on our web site, your server sends one or more resources (HTML pages, included image files, any javascript / stylesheet files, etc). Each such access to a resource counts as one event.

= Will ActiFend stop attackers? =
Yes. ActiFend detects and blocks certain types of attacks (e.g., bruteforce login attempts). Attackers are blocked immediately. The blocked attacker list is dynamically managed to ensure that only the most recent (and relevant) entries are retained. This helps in making sure the website performance is not adversely affected while still making it very difficult for attackers to succeed.

= Where are the attack alerts? =
ActiFend no longer reports unsuccessful attacks and attackers. Profiles of attackers are maintained and used for further attack detection as well as blocking. There are plans to give an on demand / periodic summary of blocked attacks & attackers in one of the future versions.

= What are the vulnerability alerts? =
ActiFend peridically and non-intrusively checks your website for certain commonly occurring vulnerabilities. These include any use of old versions of WordPress Core, PlugIns and Themes. These vulnerabilities are reported through the ActiFend Mobile App.
