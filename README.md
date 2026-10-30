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
docker run --rm -p 8080:80 --env-file .env boarding-hauz
```

The PHP container does not create or migrate a MySQL database. Configure a separate MySQL/MariaDB service and set `BOARDING_HAUZ_DB_HOST` to a hostname reachable from the PHP container. Then open `http://localhost:8080/boarding_hauz/`.

## Uploads and room images

- Static sample room photographs belong in `boarding_hauz/assets/images/rooms/`.
- Dynamic room uploads are stored under `boarding_hauz/uploads/rooms/` by the current application.
- Payment-proof uploads are stored under `boarding_hauz/uploads/payment-proofs/`.
- The supplied upload access-control files are retained. For production, prefer storing private payment proofs outside the public web root and enforce equivalent upload restrictions in the web server.
- `.gitignore` retains the current demo upload files but ignores newly generated runtime uploads by default. Do not commit real tenant payment proofs, private documents, or other personal data.

## Git notes

The supplied `.git` directory and its existing `main` branch history are retained. Local editor configuration is excluded. Review `git status` before staging, and inspect staged changes before committing.

## Security and deployment

Read [`docs/SECURITY_AUDIT.md`](docs/SECURITY_AUDIT.md) before deployment. The audit is a static code review, not a penetration test or production certification. Test database migrations and tenant/landlord/super-administrator workflows in a staging environment before deploying.
