# Boarding Hauz — Property Rental and Maintenance Portal

A PHP/MySQL web application for boarding-house room browsing, inquiries, tenant services, landlord operations, payments, and maintenance requests.

## Project structure

```text
boarding_hauz_audited/
├── .git/                         Git history and repository metadata
├── boarding_hauz/                Application root (served at /boarding_hauz)
│   ├── admin/                    Super-administrator pages
│   ├── landlord/                 Landlord workspace
│   ├── assets/                   CSS, JavaScript, and static images
│   ├── database/                 SQL schema and migration scripts
│   ├── includes/                 Shared PHP components
│   ├── uploads/                  Runtime upload locations and access rules
│   └── *.php                     Public and tenant-facing pages
├── docs/
│   └── SECURITY_AUDIT.md         Static security and bug audit
├── .env.example                  Safe environment-variable template
├── .gitignore                    Git ignore rules
├── .dockerignore                 Docker build-context exclusions
├── Dockerfile                    PHP Apache container definition
├── LICENSE
└── README.md
```

## Requirements

- PHP 8.2 or later
- MySQL or MariaDB
- PHP `mysqli` and `mbstring` extensions
- Apache (or another PHP-capable web server)
- Docker is optional

## Local setup (XAMPP / Apache)

1. Place the `boarding_hauz` application folder directly inside the web server's document root (for XAMPP, that is normally `htdocs`).
2. Create a MySQL database for the application.
3. Review the SQL files in `boarding_hauz/database/` and import the appropriate base schema and any required migrations into a development database. Back up the database before applying changes to an existing installation.
4. Copy `.env.example` to `.env` and set the `BOARDING_HAUZ_DB_*` values in the PHP/Apache environment. Do not commit `.env`.
5. Open `http://localhost/boarding_hauz/` in a browser.

The database connector has local-development fallbacks, but deployments should use a dedicated database account with a strong password and only the privileges the application needs.

## Docker

Build the image from the repository root:

```bash
docker build -t boarding-hauz .
```

Run it with database settings supplied through a local environment file:

```bash
docker run --rm -p 8080:10000 --env-file .env boarding-hauz
```

The PHP container does not create or migrate a MySQL database. Configure a separate MySQL/MariaDB service and set `BOARDING_HAUZ_DB_HOST` to a hostname reachable from the PHP container. Then open `http://localhost:8080/boarding_hauz/`.

## Render deployment

This repo includes a `render.yaml` so the app can be deployed as a Docker web service on Render.

1. Push the repository to GitHub.
2. In Render, click New > Web Service > Connect a repository.
3. Select this repo and keep the default Docker settings.
4. Set the database environment variables in the Render dashboard:
   - `BOARDING_HAUZ_DB_HOST`
   - `BOARDING_HAUZ_DB_PORT` (default `3306`)
   - `BOARDING_HAUZ_DB_NAME`
   - `BOARDING_HAUZ_DB_USER`
   - `BOARDING_HAUZ_DB_PASSWORD`
   - `BOARDING_HAUZ_DB_SSL` (`0` for local/insecure or `1` when using a TLS-enabled DB)
5. Configure a reachable MySQL/MariaDB database with your database provider and import the schema from `boarding_hauz/database/boarding_hauz_full.sql`. Set `BOARDING_HAUZ_DB_SSL=1` if that provider requires TLS.
6. Deploy the service. The app will be served at the Render-generated URL, usually under `/boarding_hauz/`.

The deployment uses the repository's `Dockerfile` and `render.yaml`; a Node `server.js` is not used because the application runs on PHP. Keep the whole repository together when deploying so the Docker build can copy `boarding_hauz/` and Render can find the deployment configuration.

Note: The final live deployment must be triggered from your Render account after pushing the repo.

## Uploads and room images

- Static sample room photographs belong in `boarding_hauz/assets/images/rooms/`.
- Dynamic room uploads are stored under `boarding_hauz/uploads/rooms/` by the current application.
- Payment-proof uploads are stored under `boarding_hauz/uploads/payment-proofs/`.
- The container makes these upload directories writable by Apache. Container-local uploads are not durable across redeploys; configure persistent storage or external object storage before relying on them in production.
- The supplied upload access-control files are retained. For production, prefer storing private payment proofs outside the public web root and enforce equivalent upload restrictions in the web server.
- `.gitignore` retains the current demo upload files but ignores newly generated runtime uploads by default. Do not commit real tenant payment proofs, private documents, or other personal data.

## Git notes

The supplied `.git` directory and its existing `main` branch history are retained. Local editor configuration is excluded. Review `git status` before staging, and inspect staged changes before committing.

## Security and deployment

Read [`docs/SECURITY_AUDIT.md`](docs/SECURITY_AUDIT.md) before deployment. The audit is a static code review, not a penetration test or production certification. Test database migrations and tenant/landlord/super-administrator workflows in a staging environment before deploying.
