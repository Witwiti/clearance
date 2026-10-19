# Deploying on Hostinger (hPanel)

This project is a **PHP + MySQL** application. Hostinger's *Deploy* button that asks for a
`package.json` is the **Node.js / static-site** import flow - a PHP app must be served by
Hostinger's PHP runtime (LiteSpeed), not by their static/Node server.

The repository now contains a **no-op `package.json`** (no dependencies, `npm run build` only
prints a message) so the Hostinger wizard accepts the repository and performs a **full import**
instead of the static fallback. The app itself is still 100% PHP - no Node.js is required and
nothing in the repository depends on npm.

**Do not click "Deploy as static"** - that publishes the files without executing PHP, so every
`.php` page would be served as plain text.

---

## 0. Hostinger deployment wizard (the screen in the screenshot)

1. Select the repository **Witwiti/clearance** → the warning disappears (the `package.json` is
   now present) and the wizard offers the full import.
2. **Build command**: `npm run build` (it only echoes a message) - or leave it empty if the
   field allows.
3. **Output / root directory**: the repository root (`.`), so that `index.php`, `admin/`,
   `student/`, `assets/`, `lib/`, `config/` end up in the domain's document root.
   Do **not** point it to `dist/` or `build/` - those folders do not exist.
4. Finish the wizard. The files are cloned into `public_html` (or the folder you selected)
   and Hostinger's PHP runtime serves them.

Then continue with steps 2, 4 and 5 below (database, credentials, permissions).

**Alternative (simplest):** hPanel → **Websites → Manage → Git** → connect the repository with
an empty build command. It clones the repo straight into the document root - no npm involved.

---

## 1. Requirements

| Item | Value |
| --- | --- |
| PHP | 8.1 or newer (hPanel: *Websites → Manage → Advanced → PHP*) |
| Database | MySQL / MariaDB (hPanel: *Databases → Management*) |
| Extensions | `mysqli`, `mbstring`, `iconv`, `zlib`, `fileinfo` (all enabled by default) |
| Repo | `https://github.com/Witwiti/clearance.git` (branch `main`) |

## 2. Create the database

1. hPanel → **Databases → Management → Add Database**
   - Name: e.g. `u123456789_scms`
   - Host: `localhost`
2. Create a database user with a strong password and grant it **all privileges** on that
   database (the app self-creates/alters tables on first request, so it needs `CREATE`/`ALTER`).
3. hPanel → **Databases → Management → phpMyAdmin** → select the new database →
   **Import** → choose `database/school_clearance_db.sql` from the repo → **Go**.
4. Note the four values: host, database name, user, password.

## 3. Upload the code

### Option A - hPanel Git integration (recommended, auto-updates)

1. hPanel → **Websites → Manage → Git**
2. **Connect** → GitHub → authorise `Witwiti/clearance`
3. Repository `Witwiti/clearance`, branch `main`, access `Public`
4. **Deploy**: leave the build command **empty** (there is nothing to compile)
5. The repository is cloned into `public_html` (or the deployment path you chose).

### Option B - FTP / File Manager

Upload the **contents** of the repository (not the `.git` folder) to `public_html` with the
FTP account from hPanel → *Files → FTP Accounts*, or through *File Manager*.

### Option C - Git from your own computer

```bash
cd public_html
git clone https://github.com/Witwiti/clearance.git .
git checkout main
```

## 4. Point the app at the database

On the server, create `config/db.local.php` (this file is **git-ignored**, so a later
`git pull` never overwrites it). Copy `config/db.local.example.php` and fill in the
values from step 2:

```php
<?php
return [
    "host" => "localhost",
    "username" => "u123456789_scms",
    "password" => "the-password-you-chose",
    "name" => "u123456789_scms",
];
```

The app resolves the credentials in this order:

1. `config/db.local.php` (per server, never committed)
2. Environment variables `DB_HOST`, `DB_USER`, `DB_PASSWORD`, `DB_NAME`
3. Local XAMPP defaults (`localhost`, `root`, empty password, `school_clearance_db`)

## 5. Permissions

```bash
chmod 755 assets/uploads assets/uploads/profiles
chmod 600 config/db.local.php
```

`assets/uploads/profiles` must stay writable, otherwise avatars cannot be uploaded.

## 6. First login and the admin account

Open `https://your-domain.tld/admin/login.php` and sign in with the account that comes
with the SQL dump: **admin@school.edu** (username `admin`).

If that password is not known, reset it from hPanel → phpMyAdmin → SQL tab:

```sql
UPDATE users SET password = '<BCRYPT HASH>' WHERE username = 'admin';
```

Generate a hash with PHP (any PHP 8 CLI or a one-off file on the host):

```bash
php -r "echo password_hash('YourNewPassword', PASSWORD_DEFAULT), PHP_EOL;"
```

## 7. Go-live checklist

- [ ] SSL active (hPanel → *Websites → Manage → SSL*) and `https://` working
- [ ] Admin → **Settings**: `maintenance_mode = 0`, `allow_clearance_requests = 1`
- [ ] Admin → **School Terms**: set the current term (it is printed on the form and on the
      certificate)
- [ ] Admin → **Form Configuration**: check school name/address/contact and the office list
- [ ] Admin → **Users**: create the office signatory accounts, link them to their offices
- [ ] Test the student flow: log in as a student → *My Clearance* → *Print form (PDF)*

## 8. Updating the site later

```bash
cd public_html && git pull origin main
```

`config/db.local.php` is not tracked by Git, so it survives every pull.

## Troubleshooting

| Symptom | Fix |
| --- | --- |
| *"This repository is missing a package.json file"* | Fixed: the repository now ships a no-op `package.json`, so the wizard offers the full import. If you still see it, pull the latest `main`. Never use "Deploy as static" (PHP would not run). |
| *"Database connection failed"* | Wrong values in `config/db.local.php` (host is `localhost`, the user name is prefixed with your hPanel account id). Check the phpMyAdmin login works with the same credentials. |
| White page / 500 error | Wrong PHP version: set PHP 8.1+ in hPanel, then check the `error_log` in *Websites → Manage → Advanced → Error Log*. |
| Landing page loads but login fails | The database is empty: import `database/school_clearance_db.sql` again (step 2). |
| Avatars fail to upload | `assets/uploads/profiles` is not writable (step 5). |
| "Print form (PDF)" downloads a broken file | `lib/` folder missing or `ext-zlib` disabled in the PHP configuration. |
| Emails/notifications not arriving | The app stores notifications in the database; it does not send email. No mail configuration is required. |

## Security notes

- Database credentials are **never** stored in the repository - they live in
  `config/db.local.php` (git-ignored) or in environment variables.
- `.htaccess` files block direct web access to `config/`, `database/` and `lib/`, disable
  directory listings and stop uploaded files from being executed as PHP.
- Set the file permissions of `config/db.local.php` to `600`.
