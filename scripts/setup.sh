#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")/.."
test -f .env || cp .env.example .env
chmod 600 .env
docker compose build app
docker compose run --rm --no-deps app composer install --no-interaction --prefer-dist
if ! grep -q '^APP_KEY=base64:.' .env; then
  docker compose run --rm --no-deps app php artisan key:generate --force
fi
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app node:24-alpine npm ci
docker run --rm --user "$(id -u):$(id -g)" -v "$PWD:/app" -w /app node:24-alpine npm run build:local
docker compose up -d postgres redis
docker compose run --rm app php artisan migrate --force
docker compose up -d app worker help documents scheduler reverb
printf '\nG-Analise IA: http://localhost:8088\nCrie a primeira conta com: docker compose exec app php artisan horus:user seu@email.com --name="Seu nome" --role=owner\n'
