FROM node:24-alpine AS codex-tools
ARG CODEX_VERSION=0.165.0
RUN npm install --global @openai/codex@${CODEX_VERSION} && find /usr/local/lib/node_modules/@openai -type f -name codex -exec cp {} /codex \; && test -x /codex && find /usr/local/lib/node_modules/@openai -type f -name codex-code-mode-host -exec cp {} /codex-code-mode-host \; && test -x /codex-code-mode-host

FROM alpine:3.22 AS lumo-tools
ARG LUMO_VERSION=3.42.0
ARG LUMO_SHA256=81d18d4feaedaa461812d26ab3d9b8c7f58e02f6152b2de597360364a676d981
RUN apk add --no-cache curl tar && cd /tmp && curl -fsSLo LumoCLI-linux-amd64.tar.gz https://storage.horusbi.com.br/download/lumo/LumoCLI-linux-amd64.tar.gz && curl -fsSLo checksums.txt https://storage.horusbi.com.br/download/lumo/checksums.txt && awk '$2 == "LumoCLI-linux-amd64.tar.gz" || $2 == "*LumoCLI-linux-amd64.tar.gz"' checksums.txt > checksum.verify && test -s checksum.verify && sha256sum -c checksum.verify && echo "${LUMO_SHA256}  LumoCLI-linux-amd64.tar.gz" | sha256sum -c - && mkdir extracted && tar -xzf LumoCLI-linux-amd64.tar.gz -C extracted && find extracted -type f -name lumo -exec cp {} /lumo \; && chmod +x /lumo && /lumo version 2>&1 | tee /tmp/lumo-version.txt && grep -F "lumo ${LUMO_VERSION} " /tmp/lumo-version.txt

FROM php:8.5-fpm-alpine AS runtime
RUN apk add --no-cache libpq git curl libstdc++ libgcc tini poppler-utils libzip && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev linux-headers libzip-dev && docker-php-ext-install pdo_pgsql pcntl zip && apk del .build-deps
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
COPY --from=codex-tools /codex /usr/local/bin/codex
COPY --from=codex-tools /codex-code-mode-host /usr/local/bin/codex-code-mode-host
COPY --from=lumo-tools /lumo /usr/local/bin/lumo
WORKDIR /var/www/html
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["php","artisan","serve","--host=0.0.0.0","--port=8000","--no-reload"]

FROM runtime AS dependencies
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --prefer-dist --no-interaction --optimize-autoloader

FROM node:24-alpine AS assets
WORKDIR /src
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY public ./public
COPY vite.config.ts tsconfig.json ./
ARG ASSET_URL
ARG VITE_REVERB_APP_KEY
ARG VITE_REVERB_HOST
ARG VITE_REVERB_PORT=443
ARG VITE_REVERB_SCHEME=https
ARG VITE_REVERB_PATH=/ws
RUN npm run build

FROM runtime AS production
COPY --chown=1000:1000 . .
COPY --from=dependencies --chown=1000:1000 /var/www/html/vendor ./vendor
COPY --from=assets --chown=1000:1000 /src/public/build ./public/build
RUN mkdir -p storage/app/private storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache && chown -R 1000:1000 storage bootstrap/cache && rm -f public/hot bootstrap/cache/*.php && php artisan package:discover --ansi
USER 1000:1000
CMD ["php-fpm","-F"]

FROM nginxinc/nginx-unprivileged:stable-alpine AS web
COPY docker/nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=production /var/www/html/public /var/www/html/public

FROM runtime AS development
