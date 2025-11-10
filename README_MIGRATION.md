# Migration Guide: Roles, Sectors, Inventory, and Audit Log

This release splits identity/governance data from application data, adds an inventory module, and introduces auditing.

## 1. Databases

Create two MySQL databases (names are suggestions):

- `core_db` – stores users, roles, sectors, and the activity log.
- `apps_db` – stores punch list data plus the new inventory tables.

Run the provided schema files:

```sql
SOURCE db/core_db.sql;
SOURCE db/apps_inventory.sql;
```

> Existing punch list tables remain unchanged. If you want to scope tasks by sector, add a nullable `sector_id` column to `tasks` and backfill existing rows as needed.

## 2. Configuration

Update `config.php` (or the corresponding environment variables) with DSNs and credentials for each database:

- `APPS_DSN`, `APPS_DB_USER`, `APPS_DB_PASS` – for application data.
- `CORE_DSN`, `CORE_DB_USER`, `CORE_DB_PASS` – for identity/audit data.

By default these fall back to the legacy single-database values, so the app keeps working until you change them.

## 3. Initial Root User

Seed at least one root user so the administrative UI becomes accessible. You can insert directly into `core_db.users` and `apps_db.users` (keep the same `id` value) or temporarily enable registration. Example CLI snippet:

```php
<?php
$hash = password_hash('ChangeMe123!', PASSWORD_DEFAULT);
$pdoCore = new PDO(getenv('CORE_DSN'), getenv('CORE_DB_USER'), getenv('CORE_DB_PASS'));
$pdoApps = new PDO(getenv('APPS_DSN'), getenv('APPS_DB_USER'), getenv('APPS_DB_PASS'));
$pdoCore->exec("INSERT INTO users (id, email, pass_hash, role_id) VALUES (1, 'root@example.com', '$hash', (SELECT id FROM roles WHERE key_slug='root'))");
$pdoApps->exec("INSERT INTO users (id, email, password_hash, role) VALUES (1, 'root@example.com', '$hash', 'root')");
```

After logging in as root you can manage users, set roles/sectors, and reset passwords from the new admin pages.

## 4. File Downloads

Public S3 links have been replaced by `download.php`, which logs download events before redirecting to the object. Update any external bookmarks to use the new endpoint.

## 5. Inventory Module

Use `inventory.php` to manage stock. Admins manage items within their sector; root users see and edit all sectors. Viewer accounts can only browse inventory.

## 6. Roles & Sectors

Root users can manage:

- Users (`admin/users.php`)
- Sectors (`admin/sectors.php`)
- Activity log (`admin/activity.php`)

Assign each user a role (`viewer`, `admin`, `root`) and an optional sector. Suspended users are blocked on login.

## 7. Function Baseline Guard

The repository now includes `.github/workflows/guard.yml` and `scripts/export_functions.php`. Run the script locally to refresh `baseline_functions.json` if you add new functions in guarded files.
