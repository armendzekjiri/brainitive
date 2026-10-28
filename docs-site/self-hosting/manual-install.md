---
title: Manual install
description: Install Brainitive by hand on Ubuntu with nginx, PHP-FPM and PostgreSQL. Build, .env, migrations, nginx, queue worker and scheduler.
order: 20
---

# Manual install

This guide installs Brainitive on Ubuntu with nginx, PHP-FPM and PostgreSQL, without Docker. It takes longer than the [Docker setup](./docker), and you are responsible for updates of every piece.

The examples install Brainitive into `/opt/brainitive` and run it as the `www-data` user. Replace `brainitive.example.com` with your domain.

## 1. Install the packages

Ubuntu 24.04 ships PHP 8.3. For PHP 8.4 (what the Docker image uses) add the `ondrej/php` PPA. PHP 8.2 or newer works.

```bash
sudo apt update
sudo apt install -y software-properties-common curl git unzip
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update

# PHP with the extensions Brainitive needs
sudo apt install -y php8.4-fpm php8.4-cli php8.4-pgsql php8.4-mbstring php8.4-xml \
  php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath php8.4-gmp

# nginx and PostgreSQL (postgresql-contrib brings pg_trgm and unaccent for search)
sudo apt install -y nginx postgresql postgresql-contrib

# Composer
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node.js 22 (frontend build + realtime server)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt install -y nodejs
```

Optional: `redis-server` and `php8.4-redis` if you want Redis for cache, sessions and the queue. Without Redis, Brainitive uses the database for all three.

## 2. Create the database

Create a user and a database owned by that user. Use your own strong password.

```bash
sudo -u postgres psql <<'SQL'
CREATE USER brainitive WITH LOGIN PASSWORD 'change-me';
CREATE DATABASE brainitive OWNER brainitive;
SQL
```

::: tip Why the owner matters
The search migration creates the PostgreSQL extensions `pg_trgm` (typo tolerance) and `unaccent` (accent folding). On PostgreSQL 13 and newer, the database owner may do that. If it can't, search still works, just without those two features. See [Search](./search).
:::

For a database on the same server, keep PostgreSQL listening on `localhost` only (the default on Ubuntu) and use password authentication (`scram-sha-256`) in `pg_hba.conf`.

## 3. Get the code

```bash
sudo mkdir -p /opt/brainitive
sudo chown "$USER": /opt/brainitive
git clone https://github.com/armendzekjiri/brainitive.git /opt/brainitive
cd /opt/brainitive
```

## 4. Install dependencies and build

```bash
composer install --no-dev --optimize-autoloader
npm ci
npm run build
npm --prefix realtime ci --omit=dev    # only if you run the realtime server
```

## 5. Configure `.env`

```bash
cp .env.example .env
php artisan key:generate
```

`.env.example` is made for development. Change at least these values:

```ini
APP_NAME=Brainitive
APP_ENV=production
APP_DEBUG=false
APP_URL=https://brainitive.example.com

LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=brainitive
DB_USERNAME=brainitive
DB_PASSWORD=change-me

MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=brainitive@example.com
MAIL_PASSWORD=your-smtp-password
MAIL_FROM_ADDRESS=brainitive@example.com
MAIL_FROM_NAME="${APP_NAME}"
```

The queue, cache and sessions use the database by default (`QUEUE_CONNECTION=database`, `CACHE_STORE=database`, `SESSION_DRIVER=database`). That is fine for most teams. All variables are in [Configuration](./configuration).

::: danger Keep APP_KEY safe
`php artisan key:generate` writes `APP_KEY` into `.env`. Brainitive encrypts stored secrets with it: the SMTP password from the admin console, helpdesk mailbox passwords, calendar and GitHub connections, webhook secrets. If you lose it, they can't be read anymore. Back up `.env` together with the database. See [Backups](./backups).
:::

## 6. Set permissions and prepare the app

The web server user must be able to write to `storage` and `bootstrap/cache`:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R ug+rwX storage bootstrap/cache
```

Run the first setup as `www-data`, so the files it creates belong to the web server:

```bash
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan db:seed --force --class='Database\Seeders\InstanceSettingsSeeder'
sudo -u www-data php artisan db:seed --force --class='Database\Seeders\ItemTypeSeeder'
sudo -u www-data php artisan db:seed --force --class='Database\Seeders\ItemPrioritySeeder'
sudo -u www-data php artisan passport:keys       # OAuth keys for the MCP server
php artisan storage:link                        # public/storage → storage/app/public
sudo -u www-data php artisan optimize           # cache config, routes and views
```

The three seeders add the instance settings and the default issue types and priorities. They are safe to run again.

::: warning
Never run `php artisan migrate:fresh`, `migrate:reset` or `db:wipe` on a real installation. They delete every table.
:::

## 7. PHP settings

Imports and project archives can be up to 200 MB. Create `/etc/php/8.4/fpm/conf.d/99-brainitive.ini`:

```ini
memory_limit = 512M
upload_max_filesize = 200M
post_max_size = 210M
max_execution_time = 120
expose_php = Off
```

Then `sudo systemctl restart php8.4-fpm`.

## 8. nginx

Create `/etc/nginx/sites-available/brainitive`:

```nginx
server {
    listen 80;
    server_name brainitive.example.com;
    root /opt/brainitive/public;
    index index.php;

    charset utf-8;
    client_max_body_size 210m;

    add_header X-Content-Type-Options "nosniff";

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    # Uploads: never run them as code, sandbox anything that isn't passive media
    location ^~ /storage/ {
        location ~* \.(php\d?|phtml|phar|pht|inc)$ { return 404; }
        location ~* \.(pdf|png|jpe?g|gif|webp|avif|bmp|ico|heic|heif|tiff?|mp3|wav|ogg|oga|m4a|aac|flac|mp4|m4v|mov|webm|avi|mkv|zip|gz|tgz|7z|rar|bz2|tar|bin|docx?|xlsx?|pptx?|odt|ods|odp|csv|tsv|txt|md|json|rtf)$ {
            add_header X-Content-Type-Options nosniff always;
            try_files $uri =404;
        }
        add_header X-Content-Type-Options nosniff always;
        add_header Content-Security-Policy "default-src 'none'; img-src data:; style-src 'unsafe-inline'; sandbox" always;
        try_files $uri =404;
    }

    # Live co-editing and live updates: WebSocket to the realtime server (see "Realtime server")
    location = /collab {
        proxy_pass http://127.0.0.1:1234;
        proxy_http_version 1.1;
        proxy_set_header Upgrade $http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_set_header Host $host;
        proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto $scheme;
        proxy_read_timeout 3600s;
        proxy_send_timeout 3600s;
        proxy_buffering off;
    }

    location = /favicon.ico { access_log off; log_not_found off; }
    location = /robots.txt  { access_log off; log_not_found off; }

    error_page 404 /index.php;

    location ~ ^/index\.php(/|$) {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
        fastcgi_hide_header X-Powered-By;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

Enable it and get a certificate, for example with Certbot:

```bash
sudo ln -s /etc/nginx/sites-available/brainitive /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx
sudo apt install -y certbot python3-certbot-nginx
sudo certbot --nginx -d brainitive.example.com
```

If you don't run the realtime server, leave out the `/collab` block. The port `1234` must match `COLLAB_PORT` of your realtime server.

## 9. Queue worker

The queue worker sends emails and runs background jobs. Without it, nothing is sent. Create `/etc/systemd/system/brainitive-queue.service`:

```ini
[Unit]
Description=Brainitive queue worker
After=network.target postgresql.service

[Service]
User=www-data
Group=www-data
WorkingDirectory=/opt/brainitive
ExecStart=/usr/bin/php artisan queue:work --tries=3 --max-time=3600
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
```

```bash
sudo systemctl daemon-reload
sudo systemctl enable --now brainitive-queue
```

Supervisor works too, see [Queue worker and scheduler](./queue-and-scheduler).

## 10. Scheduler

Add one cron line for `www-data` (`sudo crontab -u www-data -e`):

```bash
* * * * * cd /opt/brainitive && php artisan schedule:run >> /dev/null 2>&1
```

## 11. Realtime server (optional)

For live co-editing and live updates, set up the Node.js realtime server and add `COLLAB_URL` to `.env`. See [Realtime server](./realtime).

## 12. Your admin account

Open `https://brainitive.example.com`, register, then make yourself the instance admin:

```bash
cd /opt/brainitive
sudo -u www-data php artisan make:instance-admin you@example.com
```

This also marks your email as verified. Then open **avatar menu → Instance admin**. See the [Instance admin guide](/admin/).

## After changing `.env`

The configuration is cached. After every change to `.env`:

```bash
sudo -u www-data php artisan optimize
sudo -u www-data php artisan queue:restart
```

`queue:restart` makes the worker pick up the new settings after its current job.
