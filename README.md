# ASTPP Voicemail to Email

As described at the following link

https://freeswitch.org/confluence/display/FREESWITCH/PHP+email

## Requirements
* Requires PHP v7.x
* Tested on FreeSWITCH v1.10 but should work on older versions.

## Install and Configure

Copy the files into `/var/www/html/fs/lib` and make sure the webserver has read permissions (ie. `chmod 644` for example).

Enable and configure SMTP in ASTPP GUI `Configuration > Settings > Notifications > Email` as per ASTPP requirements.

For example, gmail host configuration should be in the form `ssl://smtp.googlemail.com`

Edit `/etc/freeswitch/autoload_configs/switch.config.xml` as follows

```
<param name="mailer-app" value="/usr/bin/php /var/www/html/fs/lib/vmailer.php"/>
<param name="mailer-app-args" value=""/>
```
FreeSWITCH will now send voicemail-to-email according to ASTPP SMTP settings.

## Simulation

To send a basic test email run the following from a cli. Replace the `To:` email with your email address.
```
echo -e '\n Content-Type: text/plain; boundary=x\nTo: somemail@somedomain.com\nSubject: some subject\n\n--xContent-Type: multipart/alternative;\nboundary="z"\n\n --zContent-Type: text/html;\n\nsome text' | runuser -u www-data -- /usr/bin/php /var/www/html/fs/lib/vmailer.php
```
To do a full simulation, including voicemail attachment, use the `test.txt` file,  replacing `To:` with your email address.  The `test.txt` file is an actual email output captured from FreeSWITCH. 
```
printf '%b\n' "$(cat test.txt)"| runuser -u www-data -- /usr/bin/php /var/www/html/fs/lib/vmailer.php
```
## Log
The last output is logged at `/tmp/vmailer.log`.
