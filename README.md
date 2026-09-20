# Libooru

Libooru is a self-hosted image and video board written in PHP with SQLite. It provides tag-based discovery, user accounts, moderation, an HTTP API, local or S3-compatible media storage, and importers for Realbooru, e621, and Rule34.xxx.

The application has no framework, package manager, build step, or external database server. Database migrations run automatically when the application opens the SQLite database.

## Features

- JPEG, PNG, GIF, WebP, MP4, WebM, and MOV uploads
- Generated square thumbnails and video metadata through FFmpeg
- Canonical tags, aliases, autocomplete, blacklists, and local tag explanations
- Global banned-tag rules with AND combinations and optional cleanup of existing posts
- e621-style search operators and metadata filters
- Safe, questionable, and explicit ratings
- Comments, votes, favorites, profiles, reports, and per-user API keys
- Role-based administration and moderation permissions
- Optional registration approval, Cloudflare Turnstile, and login-gated posts
- Local filesystem or S3-compatible media storage
- S3-compatible SQLite backups with integrity checks and rollback protection
- Realbooru, e621, and Rule34.xxx importers with persistent progress and duplicate detection
- Discord notifications for new posts
- XML sitemaps, `robots.txt`, byte-range video delivery, and responsive thumbnails

## Requirements

- PHP 8.0 or newer
- PHP-FPM for production
- PHP extensions: `pdo_sqlite`, `fileinfo`, `gd`, `mbstring`, and `simplexml`
- `ffmpeg` and `ffprobe`
- `timeout` from GNU coreutils
- `curl` CLI when using S3-compatible storage or database backups
- A web server with front-controller routing; the repository includes an Nginx example
- `allow_url_fopen=On` when using remote importers, Turnstile, or IP geolocation

The web server user must be able to create and modify files under `data/`. The source tree should otherwise be read-only to that user.

## Production deployment

### 1. Install the application

```bash
git clone https://github.com/JustLivvu/libooru.git /var/www/libooru
cd /var/www/libooru
install -d -o www-data -g www-data -m 0750 \
  data data/uploads data/thumbs data/sessions data/site-assets
```

Replace `www-data` with the account used by PHP-FPM on your system, commonly `www`, `nginx`, or `apache`.

Do not expose the repository through a generic PHP virtual host. Requests must enter through `index.php`, and direct access to `data/`, internal PHP files, dotfiles, and scraper scripts must be denied.

### 2. Configure PHP

At minimum, align these PHP settings with the application limits:

```ini
upload_max_filesize = 100M
post_max_size = 105M
max_execution_time = 60
allow_url_fopen = On
```

Restart PHP-FPM after changing its configuration. The application-level upload limit is defined by `MAX_FILE_SIZE` in `config.php` and defaults to 100 MiB.

### 3. Configure Nginx

Use [`nginx.conf.example`](nginx.conf.example) as the routing baseline. Update its document root, PHP-FPM socket, and upload limit for your host, then include it inside the HTTPS server block.

The production virtual host must enforce the following boundaries:

- route non-file requests to `/index.php`;
- deny all access to `/data/`, `/.git/`, dotfiles, and internal PHP source files;
- execute only `/index.php` through PHP-FPM;
- set `client_max_body_size` to at least `100m`;
- pass the real client IP only from explicitly trusted reverse proxies.

Validate and reload Nginx:

```bash
nginx -t
systemctl reload nginx
```

### 4. Set the canonical URL

Pass the public origin to PHP-FPM:

```ini
env[LIBOORU_SITE_URL] = https://booru.example.com
```

`LIBOORU_SITE_URL` is used for absolute links, webhooks, sitemaps, and canonical metadata. It must not contain a trailing slash. If the variable is unavailable, Libooru derives the origin from the request, which is less reliable behind a proxy.

When using Cloudflare Tunnel, point the tunnel at the local Nginx listener rather than PHP-FPM. TLS may terminate at Cloudflare; the origin should still receive the correct host and scheme headers.

### 5. Initialize and secure the instance

Open the site once. Libooru creates `data/libooru.db`, applies its schema, and creates the initial administrator:

```text
username: admin
password: admin
```

Log in and change this password immediately under **Settings**. Then review the admin panel, registration policy, default blacklist, storage backend, and role permissions before making the site public.

Verify filesystem ownership after initialization:

```bash
find data -type d -exec chmod 0750 {} \;
find data -type f -exec chmod 0640 {} \;
```

## Local development

The PHP development server is sufficient for local work:

```bash
mkdir -p data/uploads data/thumbs data/sessions data/site-assets
LIBOORU_SITE_URL=http://127.0.0.1:8080 \
  php -S 127.0.0.1:8080 router.php
```

Open `http://127.0.0.1:8080`. The built-in server is not intended for public deployment.

## Configuration

Static limits and defaults live in [`config.php`](config.php). Runtime settings are stored in SQLite and managed from the admin panel.

| Area | Configuration |
| --- | --- |
| Public origin | `LIBOORU_SITE_URL` environment variable |
| Upload size and media limits | `config.php` |
| Site name, branding, terms, registrations | Admin panel |
| Roles and permissions | Admin panel |
| Local or S3 media storage | Admin panel |
| S3 database backups | Admin panel |
| Discord webhook | Admin panel |
| Turnstile | Admin panel |
| Rule34.xxx credentials | Scraper page |

S3 access keys, webhook URLs, Turnstile secrets, and importer credentials are stored in the SQLite database. Protect the database, its WAL files, and every backup as secrets.

### Media storage

The default `local` driver stores originals in `data/uploads/` and thumbnails in `data/thumbs/`. Persist the entire `data/` directory across deployments.

The `s3` driver supports AWS Signature Version 4 endpoints. Configure the endpoint, region, bucket, access key, and secret key in the admin panel. Existing local objects are not migrated automatically when the driver changes.

### Database backups

Database backups use a separate S3-compatible configuration. A backup is created with SQLite `VACUUM INTO`, validated, and uploaded below `database-backups/` in the configured bucket.

Before a restore, Libooru creates:

1. a remote pre-rollback backup of the current database;
2. a local emergency copy in `data/`;
3. a validated replacement database.

Back up media objects separately. Database backups contain metadata and settings, not uploaded media.

For local snapshots, use SQLite's backup mechanism rather than copying a live WAL database as a single file:

```bash
sqlite3 data/libooru.db ".backup '/secure/path/libooru.sqlite'"
```

## Search

Search accepts plain tags and e621-style operators. Terms are combined with `AND` unless an OR expression is used.

```text
blue_eyes solo
-animated
~cat ~dog
rating:e
width:>=1920 height:>=1080
date:2026-01-01..2026-01-31
order:score_desc limit:100
```

Supported metadata includes rating, quality, ID, score, dimensions, file size, megapixels, aspect ratio, tag count, favorites, comments, date, file type, MD5, source, title/description, uploader, and ordering. The complete interactive reference is available at `/search-help` on a running instance.

Tag aliases are resolved on upload, edit, import, search, and autocomplete. For example, an alias query can resolve to its canonical stored tag instead of fragmenting the tag index.

## API

The API base path is `/api/v1`. Supply an API key through the `X-API-Key` header. Query-string API keys are accepted for compatibility but should not be used in production because URLs are commonly logged.

```bash
export LIBOORU_URL=https://booru.example.com
export LIBOORU_API_KEY=replace-with-your-key

curl "$LIBOORU_URL/api/v1/posts?tags=artwork+rating:e&limit=20"

curl -H "X-API-Key: $LIBOORU_API_KEY" \
  "$LIBOORU_URL/api/v1/users/me"

curl -X POST \
  -H "X-API-Key: $LIBOORU_API_KEY" \
  -F "file=@image.webp" \
  -F "tags=example_tag artist:example" \
  -F "rating=q" \
  -F "content_type=artwork" \
  "$LIBOORU_URL/api/v1/posts"
```

### Endpoints

| Method | Path | Authentication | Purpose |
| --- | --- | --- | --- |
| `GET` | `/posts` | Optional | Search and paginate posts |
| `GET` | `/posts/{id}` | Optional | Read one post and its comments |
| `POST` | `/posts` | Required | Upload a post using multipart form data |
| `PUT` | `/posts/{id}` | Owner or moderator | Update tags and metadata |
| `DELETE` | `/posts/{id}` | Owner or moderator | Delete a post and its media |
| `GET` | `/tags` | Optional | List or filter tags |
| `GET` | `/tags/autocomplete` | Optional | Resolve autocomplete suggestions and aliases |
| `GET` | `/tags/explanation` | Optional | Read a local tag explanation |
| `GET` | `/comments/{postId}` | Optional | List comments |
| `POST` | `/comments/{postId}` | Required | Add a comment |
| `DELETE` | `/comments/{id}` | Moderator | Delete a comment |
| `POST` | `/votes/{postId}` | Required | Set the current user's vote |
| `GET` | `/users/me` | Required | Read the authenticated user |

Optional endpoints become authenticated when **Require login to view posts** is enabled. API responses are JSON. Uploads use `multipart/form-data`; updates, comments, and votes use JSON request bodies.

## Importers

Importers are managed from `/scraper` by users with the `manage_scraper` permission. Tasks run as background PHP processes, write logs to `data/`, remember pagination progress, and skip posts already imported from the same source.

| Source | Authentication | Imported content tag | Notes |
| --- | --- | --- | --- |
| Realbooru | None | `real_life` | Includes a separate tag backfill task |
| e621 | None | `artwork` | Supports a per-task tag blacklist |
| Rule34.xxx | User ID and API key | `artwork` | Credentials are saved from the scraper page |

Respect each upstream service's API terms, rate limits, and content policy. Importers download remote files into local temporary storage before passing them through the same validation, duplicate detection, thumbnail generation, and storage path as manual uploads.

## Operations

### Health and smoke checks

There is no dedicated health endpoint. A basic deployment check is:

```bash
curl --fail --silent --show-error https://booru.example.com/ >/dev/null
curl --fail --silent --show-error https://booru.example.com/api/v1/posts?limit=1 >/dev/null
sqlite3 data/libooru.db 'PRAGMA quick_check;'
```

The final command must return `ok`.

### Updating

1. Create a database backup and verify media backups.
2. Stop or drain requests to the instance.
3. Pull or deploy the new source tree without replacing `data/`.
4. Run a CLI bootstrap to apply migrations before returning traffic:

   ```bash
   php -r "require 'db.php'; DB::get(); echo \"migrations complete\\n\";"
   ```

5. Restart PHP-FPM to clear OPcache.
6. Run the smoke checks above.

For multi-instance deployments, use shared object storage for media and ensure only one instance performs schema migration at a time. SQLite itself must remain on a local filesystem with correct locking semantics; do not place the database on NFS or an object-storage mount.

### Monitoring

Monitor at least:

- HTTP 5xx rate and PHP-FPM saturation;
- free space and inode usage for `data/` and temporary storage;
- SQLite integrity and backup age;
- failed scraper tasks and scraper log growth;
- FFmpeg failures and S3 request errors;
- object count parity between database posts and media storage.

## Security model

- Passwords use PHP's `PASSWORD_DEFAULT` hashing.
- Browser mutations use CSRF tokens.
- API mutations require a user API key and enforce ownership or role permissions.
- Upload types are detected from file contents, not trusted client filenames.
- Duplicate media is rejected by MD5, and decoded media dimensions are capped.
- User and default tag blacklists are enforced in page and API results.
- Comment and report submission are rate-limited in SQLite.

Operational security still depends on the deployment. Keep PHP and FFmpeg patched, expose only the front controller, restrict filesystem permissions, rotate leaked API/S3/webhook credentials, and test restores regularly.

## Repository layout

```text
index.php                 HTTP front controller and page handlers
api.php                   JSON API
post.php                  search, tags, uploads, comments, votes, reports
auth.php                  sessions, accounts, roles, and API keys
db.php                    SQLite connection and migrations
storage.php / s3.php      local and S3-compatible object storage
image.php                 thumbnail generation and media inspection
backup.php                database backup and rollback workflow
seo.php                   robots and sitemap endpoints
view.php                  server-rendered UI primitives
tag_explanations.php      curated local tag documentation
scrapers/                 upstream import workers
static/                   CSS, JavaScript, and favicon
data/                     runtime state; never deploy from Git
```

## License

Released under the [MIT License](LICENSE).
