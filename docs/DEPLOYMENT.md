# Automatic deployment with GitHub

This guide sets up **fully automatic deployment**: every push to the `main` branch triggers a deploy to your server via GitHub Actions.

---

## What you need

- A **Linux server** (VPS) with SSH access (e.g. DigitalOcean, Linode, AWS EC2, or any host with SSH).
- Your **GitHub repo** with this project (the workflow is already in `.github/workflows/deploy.yml`).

---

## Step 1: Prepare your server

### 1.1 Install required software

On the server (Ubuntu/Debian example):

```bash
# PHP 8.2+, Composer, Git
sudo apt update
sudo apt install -y php8.2-cli php8.2-fpm php8.2-mbstring php8.2-xml php8.2-mysql php8.2-sqlite3 php8.2-curl php8.2-zip
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer

# Node.js 18+ (for npm run build)
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt install -y nodejs

# Git (if not already)
sudo apt install -y git
```

Adjust PHP/Node versions to match your stack. You also need a web server (e.g. Nginx or Apache) with the document root pointing to your app’s `public` folder.

### 1.2 Deploy user

You’ll SSH as the user that owns the app (e.g. `ubuntu`). Use that username as `DEPLOY_USER` in **Step 3**.

### 1.3 App directory

Your app lives at **`/var/www/brainitive`**. Use this as `DEPLOY_PATH` in **Step 3**.

---

## Step 2: Put your code on the server and configure it

### 2.1 Clone the repo (if not already)

Your app is already at `/var/www/brainitive`. If you need to clone from scratch (e.g. new server):

```bash
cd /var/www/brainitive
git clone https://github.com/YOUR_USERNAME/YOUR_REPO.git .
```

Replace `YOUR_USERNAME` and `YOUR_REPO` with your GitHub org/repo.  
If the repo is private, use either:

- **Deploy key** (recommended): add the server’s **public** SSH key as a deploy key in the repo (Settings → Deploy keys), and use the **private** key as `DEPLOY_KEY` in **Step 3**, or  
- **Personal access token (HTTPS):**  
  `git clone https://TOKEN@github.com/YOUR_USERNAME/YOUR_REPO.git .`

### 2.2 Configure environment

```bash
cp .env.example .env
php artisan key:generate
```

Edit `.env` with production values (database, `APP_ENV=production`, `APP_DEBUG=false`, `APP_URL`, etc.).

### 2.3 Set permissions

```bash
cd /var/www/brainitive
chmod -R 775 storage bootstrap/cache
# Ensure web server can read; adjust group if needed (e.g. www-data for Nginx)
sudo chown -R ubuntu:www-data storage bootstrap/cache
```

### 2.4 First-time deploy (optional but useful)

Run the same steps the workflow will run, so you know the path is correct:

```bash
cd /var/www/brainitive
composer install --no-dev --optimize-autoloader
npm ci
npm run build
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Point your web server’s document root to `/var/www/brainitive/public` and ensure PHP-FPM (or Apache) is configured for this app.

---

## Step 3: Add GitHub Actions secrets

In GitHub: open your repo → **Settings** → **Secrets and variables** → **Actions** → **New repository secret**. Add:

| Secret name     | Value | Required |
|-----------------|--------|----------|
| `DEPLOY_HOST`   | Server IP or hostname (e.g. `vps-d7379e14.your-domain.com` or the raw IP) | Yes |
| `DEPLOY_USER`   | SSH user: **`ubuntu`** (the user that owns `/var/www/brainitive`) | Yes |
| `DEPLOY_KEY`    | Full contents of the **private** SSH key used to log in as `ubuntu` (paste the whole key including `-----BEGIN ... KEY-----` and `-----END ... KEY-----`) | Yes |
| `DEPLOY_PATH`   | **`/var/www/brainitive`** (where the app lives on the server) | Yes |
| `DEPLOY_PORT`   | SSH port if not 22 (e.g. `2222`); omit if you use port 22 | No |

To get the private key for `DEPLOY_KEY`:

- On your **local machine** you use a key to run `ssh ubuntu@your-server`. The **private** part of that key (e.g. `~/.ssh/id_rsa` or `~/.ssh/id_ed25519`) is what you paste into `DEPLOY_KEY`. Run `cat ~/.ssh/id_rsa` (or the key file you use) and paste the entire output.
- Alternatively, generate a key **on the server** for the deploy user: `ssh-keygen -t ed25519 -C "github-deploy"` in `ubuntu`’s `~/.ssh`, add the **public** key to GitHub (Settings → Deploy keys), and paste the **private** key into `DEPLOY_KEY`.
- Never commit the private key; only store it in GitHub Secrets.

---

## Step 4: Trigger a deploy

- **Automatic:** Push (or merge) to the `main` branch. The “Deploy” workflow will run and deploy to your server.
- **Manual:** Repo → **Actions** → **Deploy** → **Run workflow** → **Run workflow**.

Check the run log to confirm each step (git pull, composer, npm build, migrate, caches) succeeded.

---

## Summary checklist

- [ ] Server has PHP 8.2+, Composer, Node 18+, Git, and a web server pointing to `public/`.
- [ ] App is cloned on the server, `.env` is set, and `storage`/`bootstrap/cache` are writable.
- [ ] GitHub repo has secrets: `DEPLOY_HOST`, `DEPLOY_USER`, `DEPLOY_KEY`, `DEPLOY_PATH` (and `DEPLOY_PORT` if needed).
- [ ] Push to `main` runs the Deploy workflow and the site works.

---

## Changing the branch or path

- To deploy on another branch (e.g. `production`), edit `.github/workflows/deploy.yml`: under `on.push.branches` use that branch, and in the `script` change `git pull origin main` to `git pull origin production`.
- If you use a different app path, update the `DEPLOY_PATH` secret (and ensure the server path matches).

---

## Troubleshooting

- **Permission denied (publickey):** The key in `DEPLOY_KEY` must match the key (or deploy key) the server accepts for `DEPLOY_USER`. Test with `ssh -i key_file DEPLOY_USER@DEPLOY_HOST`.
- **composer: command not found:** Install Composer on the server and ensure it’s in the `PATH` for the user that runs the deploy (e.g. `deploy`).
- **npm not found:** Install Node/npm for that user and ensure `PATH` includes them (e.g. via nvm or NodeSource).
- **Migration or cache errors:** Run the same commands manually on the server as the deploy user and fix `.env` or file permissions.
