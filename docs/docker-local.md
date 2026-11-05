# Full Docker development on Windows

The original docker-compose.yml provides PostgreSQL 17, Redis 7 and Mailpit. The optional docker-compose.full.yml overlay adds Laravel/PHP, a queue worker, the Laravel scheduler and Vite/Node. This is a local development stack, not a production image.

## Requirements

- Docker Desktop running with Linux containers / WSL 2 backend.
- Git for Windows.
- Repository checked out on the feature branch.
- PHP/Node through Herd are not required while using this stack. Herd may remain installed but should not serve this project at the same time.

## First run (PowerShell)

Create .env only if it does not already exist:

\`\`\`powershell
if (-not (Test-Path .env)) { Copy-Item .env.example .env }
\`\`\`

Edit .env and ensure these values are set:

\`\`\`dotenv
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=fms
DB_USERNAME=fms
DB_PASSWORD=secret
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379
REDIS_PASSWORD=secret
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
\`\`\`

The Compose overlay passes the service DNS names postgres, redis and mailpit to Laravel. Do not use 127.0.0.1 as DB_HOST or REDIS_HOST from inside the app container.

Validate and build the images:

\`\`\`powershell
docker compose -f docker-compose.yml -f docker-compose.full.yml config
docker compose -f docker-compose.yml -f docker-compose.full.yml build
\`\`\`

Install PHP dependencies in a one-off container before starting the app services. The repository is bind-mounted, so vendor is created in the project folder:

\`\`\`powershell
docker compose -f docker-compose.yml -f docker-compose.full.yml run --rm --no-deps app composer install
\`\`\`

Generate the local application key:

\`\`\`powershell
docker compose -f docker-compose.yml -f docker-compose.full.yml run --rm --no-deps app php artisan key:generate
\`\`\`

Start all services:

\`\`\`powershell
docker compose -f docker-compose.yml -f docker-compose.full.yml up -d
\`\`\`

Run database migrations:

\`\`\`powershell
docker compose -f docker-compose.yml -f docker-compose.full.yml exec app php artisan migrate
\`\`\`

The Vite container installs the npm dependencies on its first start. Open:
- Laravel: http://localhost:8000
- Vite: http://localhost:5173
- Mailpit: http://localhost:8025

## Daily use

\`\`\`powershell
docker compose -f docker-compose.yml -f docker-compose.full.yml up -d
docker compose -f docker-compose.yml -f docker-compose.full.yml ps
docker compose -f docker-compose.yml -f docker-compose.full.yml logs -f app vite
\`\`\`

Run Artisan commands through the app container:

\`\`\`powershell
docker compose -f docker-compose.yml -f docker-compose.full.yml exec app php artisan migrate:status
docker compose -f docker-compose.yml -f docker-compose.full.yml exec app php artisan test
\`\`\`

Stop containers without deleting database data:

\`\`\`powershell
docker compose -f docker-compose.yml -f docker-compose.full.yml down
\`\`\`

To delete database and Redis volumes (DESTRUCTIVE; all local data in them is lost):

\`\`\`powershell
docker compose -f docker-compose.yml -f docker-compose.full.yml down -v
\`\`\`

## Notes

- Do not run migrate:fresh against a database containing data you need.
- This is a development stack. Do not expose these ports to the public internet or reuse sample passwords in production.
- If ports 8000, 5173, 5432, 6379 or 8025 are occupied, stop the conflicting service or change the host-side port mapping.
