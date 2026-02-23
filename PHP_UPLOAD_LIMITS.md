# PHP upload limits (bookmark files up to 200MB)

Bookmark file uploads can be up to **200MB**. PHP must allow the request body; set both:

- `upload_max_filesize` ≥ 210M  
- `post_max_size` ≥ 210M  

Laravel uses PHP’s `post_max_size` for its check, so once PHP is configured, no extra app config is needed.

## Option 1: `php artisan serve` (recommended for local)

Use the project **`php.ini`** in the repo (root directory). From the **project root** run:

```bash
php -c . artisan serve
```

`-c .` makes PHP load `php.ini` from the current directory, so the 210M limits are applied to the built-in server. This is more reliable than `-d` flags on some systems.

## Option 2: `-d` flags (alternative for artisan serve)

If `-c .` is not possible, run:

```bash
php -d upload_max_filesize=210M -d post_max_size=210M artisan serve
```

If you still see "exceeds the limit of 8388608 bytes", your environment may be ignoring these. Use Option 1 or set the values in your system `php.ini` (Option 3).

## Option 3: `.user.ini` (PHP-FPM / CGI)

`public/.user.ini` is set to 210M. It is used when PHP runs under PHP-FPM/CGI. After changing it, restart PHP-FPM or wait for `user_ini.cache_ttl` (default 5 minutes).

## Option 4: System `php.ini`

Set in the `php.ini` that your web server or CLI uses:

```ini
upload_max_filesize = 210M
post_max_size = 210M
```

Then restart the web server or PHP-FPM.

## Check current limits

CLI (same binary as when you run `artisan serve`):

```bash
php -c . -i | grep -E 'upload_max_filesize|post_max_size'
```

Or without -c if you use system php.ini:

```bash
php -i | grep -E 'upload_max_filesize|post_max_size'
```

You should see **210M** (or 220200960 bytes). If you see **8M** (8388608), the server is not using the config and large uploads will fail.
