# Punch List Manager

A lightweight PHP 8.2+ punch list manager designed for LAMP stacks with S3-compatible storage.

## Setup

1. Run `composer install` to download dependencies (AWS SDK for PHP + Dompdf).
2. Create a MySQL database and run the schema in `schema.sql` (or from README instructions).
3. Copy `config.php.example` to `config.php` and fill in database and S3-compatible storage credentials.
4. Run `php seed.php` once to seed the admin user (`admin@example.com` / `admin123`) and sample data.
5. Point your web server's document root to this project directory.
6. Ensure the `/vendor` directory is web-accessible (if your deployment keeps it outside the web root, update autoload include paths accordingly).

## Self-hosted Object Storage

The application assumes you run your own S3-compatible object storage (no third-party cloud required). The example configuration in `config.php.example` targets a MinIO instance, but any software that speaks the S3 API will work.

### Example: MinIO on the same VPS

1. **Install MinIO**
   ```bash
   wget https://dl.min.io/server/minio/release/linux-amd64/minio
   chmod +x minio
   sudo mv minio /usr/local/bin/
   ```

2. **Create directories** for data and configuration:
   ```bash
   sudo mkdir -p /srv/minio/data
   sudo chown -R $USER:$USER /srv/minio
   ```

3. **Start MinIO** (replace credentials with strong values):
   ```bash
   MINIO_ROOT_USER=punchlist MINIO_ROOT_PASSWORD='change-me-strong' \
     minio server /srv/minio/data --console-address ":9090" --address ":9000"
   ```
   Run it as a systemd service for production. A minimal unit file:
   ```ini
   [Unit]
   Description=MinIO Storage Server
   After=network.target

   [Service]
   User=minio
   Group=minio
   Environment="MINIO_ROOT_USER=punchlist"
   Environment="MINIO_ROOT_PASSWORD=change-me-strong"
   ExecStart=/usr/local/bin/minio server /srv/minio/data --console-address :9090 --address :9000
   Restart=always

   [Install]
   WantedBy=multi-user.target
   ```

4. **Create a bucket** named `punchlist` via the MinIO console (`http://your-vps:9090`) or the `mc` CLI:
   ```bash
   mc alias set local http://127.0.0.1:9000 punchlist change-me-strong
   mc mb local/punchlist
   ```

5. **Generate access keys** dedicated to the web app. Inside the MinIO console, add a new user with `consoleAdmin` access or a custom policy that grants read/write to the `punchlist` bucket. Record the Access Key and Secret Key.

6. **Expose HTTPS**: put MinIO behind a reverse proxy such as Nginx or Caddy that terminates TLS. The app can connect to either HTTP or HTTPS endpoints, but HTTPS is strongly recommended.

7. **Update `config.php`** with your MinIO endpoint and credentials:
   ```php
   define('S3_ENDPOINT', 'https://minio.example.com');
   define('S3_KEY', 'APP_ACCESS_KEY');
   define('S3_SECRET', 'APP_SECRET_KEY');
   define('S3_BUCKET', 'punchlist');
   define('S3_REGION', 'us-east-1'); // MinIO accepts any region string
   define('S3_USE_PATH_STYLE', true); // required for MinIO unless using subdomain routing
   ```
   Set `S3_URL_BASE` if you serve public files through a CDN or a different host than the API endpoint.

8. **Verify connectivity** by running `php seed.php` (which uploads sample photos if available) or uploading a photo through the UI.

For alternative storage backends (Ceph, OpenIO, etc.), adjust the endpoint and path-style option as required by your platform.

## Development Notes

- No PHP framework is used; the app relies on simple includes and helper functions.
- CSRF tokens protect all forms and upload/delete actions.
- File uploads go directly to the configured S3-compatible endpoint.
- Exports make use of Dompdf and standard CSV output.
