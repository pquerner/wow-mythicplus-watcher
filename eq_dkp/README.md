MythicPlus Watcher
======

This symfony3 based project watches your guilds mythic+ keys run by your Members.
This application does not offer any database actions, it'll just gets the newest information available everytime(*) you run it.


## How to run it

1) This application is web-based, and needs a server able to run PHP scripts.
2) You need to have an API key from https://dev.battle.net/.
You then have to enter the API key here in the file "eq_dkp/app/config/parameters.yml" 
3) I used apache2 as webserver and this is the vhost file:


<VirtualHost *:80>
    ServerName example.com
    ServerAlias www.example.com

    DocumentRoot "<DIRECTOR>\web"
    <Directory "<DIRECTORY>\web">
		Options Indexes FollowSymLinks MultiViews Includes ExecCGI
        AllowOverride None
        Require all granted

        <IfModule mod_rewrite.c>
            Options -MultiViews
            RewriteEngine On
            RewriteCond %{REQUEST_FILENAME} !-f
            RewriteRule ^(.*)$ app_dev.php [QSA,L]
        </IfModule>
    </Directory>

    # uncomment the following lines if you install assets as symlinks
    # or run into problems when compiling LESS/Sass/CoffeeScript assets
    # <Directory /var/www/project>
    #     Options FollowSymlinks
    # </Directory>

    # optionally disable the RewriteEngine for the asset directories
    # which will allow apache to simply reply with a 404 when files are
    # not found instead of passing the request into the full symfony stack
    <Directory I:\INSYNC\eq_dkp\eq_dkp\web\bundles>
        <IfModule mod_rewrite.c>
            RewriteEngine Off
        </IfModule>
    </Directory>
    ErrorLog "logs/eqdkp-error.log"
    CustomLog "logs/eqdkp-access.log" common
</VirtualHost>

3) Call the application in your webbrowser like
http://domain/?guild=uprising&realm=zuluhed&granks=8,4,9,0&region=eu
4) Needed GET Parameters are:
- guild - represents the guild name
- realm - realm name
- granks - only those guild ranks will be "looked after" (Comma seperated list, or 'any')
- region - which region the server/guild combo is in (Currently supported: eu, us)
5) Which then hopefully yields and status output in your browser.