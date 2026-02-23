# PostgreSQL on Ubuntu – Install, Secure, and Create App Database

Step-by-step guide to install PostgreSQL on Ubuntu, secure it, and create a dedicated user and database for this Laravel app.

---

## 1. Install PostgreSQL

```bash
# Update package list
sudo apt update

# Install PostgreSQL and contrib package (useful extensions)
sudo apt install -y postgresql postgresql-contrib

# Check that the service is running
sudo systemctl status postgresql
```

You should see `active (exited)` or `active (running)`.

---

## 2. Switch to the PostgreSQL User and Open psql

PostgreSQL creates a system user `postgres`. Use it to run admin commands:

```bash
# Switch to postgres user and open the PostgreSQL shell
sudo -u postgres psql
```

You'll see a prompt like `postgres=#`.

---

## 3. Create a Database User (Role) for Your App

**Replace `brainitive_user` and `YourSecurePassword123!` with your own values.**

In the `psql` shell:

```sql
-- Create a user (role) with login and a password
CREATE USER brainitive_user WITH LOGIN PASSWORD 'YourSecurePassword123!';

-- Optional: allow the user to create databases (e.g. for running migrations or creating test DBs)
ALTER USER brainitive_user CREATEDB;
```

Use a strong password and store it somewhere safe (e.g. password manager). You'll put it in `.env` later.

---

## 4. Create the Database

```sql
-- Create the database (use a name that matches your app, e.g. brainitive)
CREATE DATABASE brainitive OWNER brainitive_user;

-- Grant all privileges on the database to your user (so Laravel can create tables, etc.)
GRANT ALL PRIVILEGES ON DATABASE brainitive TO brainitive_user;

-- PostgreSQL 15+: grant usage on the public schema (needed for Laravel)
\c brainitive
GRANT ALL ON SCHEMA public TO brainitive_user;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO brainitive_user;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO brainitive_user;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO brainitive_user;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO brainitive_user;
```

Replace `brainitive` with your desired database name if different.

---

## 5. (Optional) Restrict Access – "Secure" PostgreSQL

### 5.1 Only allow local connections (recommended if app and DB are on same server)

Edit `pg_hba.conf`:

```bash
# Find the config file (usually)
sudo -u postgres psql -t -P format=unaligned -c 'SHOW hba_file;'
# Or typically:
sudo nano /etc/postgresql/16/main/pg_hba.conf
```

Use your PostgreSQL version (e.g. `16`) instead of `16` if different. Check with:

```bash
psql --version
```

In `pg_hba.conf`, ensure you have a line like this for local TCP connections (and no weaker `trust` line for the same type):

```
# TYPE  DATABASE        USER            ADDRESS                 METHOD
host    brainitive      brainitive_user 127.0.0.1/32            scram-sha-256
host    all             all              127.0.0.1/32            scram-sha-256
```

- `scram-sha-256` = password authentication (recommended).
- If your app connects via Unix socket only, the default `local` lines are enough; the `host` line above is for `127.0.0.1` (e.g. `DB_HOST=127.0.0.1`).

### 5.2 Listen only on localhost (if you don't need remote DB access)

Edit `postgresql.conf` (path often `/etc/postgresql/16/main/postgresql.conf`):

```bash
sudo nano /etc/postgresql/16/main/postgresql.conf
```

Set:

```
listen_addresses = 'localhost'
```

### 5.3 Restart PostgreSQL after config changes

```bash
sudo systemctl restart postgresql
```

---

## 6. Verify the User Can Connect

Exit psql with `\q`, then:

```bash
# Test login with your app user (replace password and database name)
PGPASSWORD='YourSecurePassword123!' psql -h 127.0.0.1 -U brainitive_user -d brainitive -c '\conninfo'
```

You should see connection info. Then run a quick Laravel check from your app directory:

```bash
cd /path/to/your/laravel/app
php artisan db:show
```

---

## 7. Configure Your Laravel App (.env)

On the server, in your app root, set:

```env
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=brainitive
DB_USERNAME=brainitive_user
DB_PASSWORD=YourSecurePassword123!
```

Then:

```bash
php artisan config:clear
php artisan migrate --force
```

---

## 8. Quick Reference – All SQL in One Block

If you prefer to run everything in one go (replace user, password, and database name):

```bash
sudo -u postgres psql << 'EOF'
CREATE USER brainitive_user WITH LOGIN PASSWORD 'YourSecurePassword123!';
ALTER USER brainitive_user CREATEDB;
CREATE DATABASE brainitive OWNER brainitive_user;
GRANT ALL PRIVILEGES ON DATABASE brainitive TO brainitive_user;
\c brainitive
GRANT ALL ON SCHEMA public TO brainitive_user;
GRANT ALL PRIVILEGES ON ALL TABLES IN SCHEMA public TO brainitive_user;
GRANT ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public TO brainitive_user;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON TABLES TO brainitive_user;
ALTER DEFAULT PRIVILEGES IN SCHEMA public GRANT ALL ON SEQUENCES TO brainitive_user;
EOF
```

---

## 9. Install PHP PostgreSQL Extension (Required for Laravel)

Your server needs the PHP `pdo_pgsql` extension:

```bash
# PHP 8.x (adjust version if needed, e.g. php8.1, php8.2, php8.3)
sudo apt install -y php-pgsql

# If you use a specific PHP version:
# sudo apt install -y php8.2-pgsql

# Restart PHP-FPM if you use it (e.g. for Nginx)
sudo systemctl restart php8.2-fpm
```

Check:

```bash
php -m | grep pdo_pgsql
```

You should see `pdo_pgsql`.

---

## Summary Checklist

- [ ] PostgreSQL installed (`postgresql` + `postgresql-contrib`)
- [ ] App user created with a strong password
- [ ] Database created and owned by that user
- [ ] Grants on `public` schema (PostgreSQL 15+)
- [ ] `pg_hba.conf` uses `scram-sha-256` (no `trust` for production)
- [ ] `listen_addresses = 'localhost'` if no remote DB access
- [ ] PHP `pdo_pgsql` extension installed
- [ ] `.env` updated and `php artisan migrate` runs successfully
