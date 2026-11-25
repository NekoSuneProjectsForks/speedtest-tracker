# Build from the repository root.
ARG APP_BASE=ghcr.io/linuxserver/speedtest-tracker:1.15.1
FROM ${APP_BASE} AS application
WORKDIR /app/www
COPY composer.json composer.lock ./
COPY app/ app/
COPY bootstrap/ bootstrap/
COPY config/ config/
COPY database/ database/
COPY lang/ lang/
COPY public/ public/
COPY resources/ resources/
COPY routes/ routes/
COPY artisan openapi.json ./
RUN COMPOSER_ALLOW_SUPERUSER=1 composer install --no-dev --no-scripts --no-interaction --prefer-dist --optimize-autoloader

FROM node:24-bookworm-slim AS assets
WORKDIR /build
COPY package.json package-lock.json ./
RUN npm ci --no-audit
COPY vite.config.js ./
COPY resources/ resources/
COPY app/ app/
COPY --from=application /app/www/vendor/ vendor/
RUN npm run build

FROM application
COPY --from=assets /build/public/build/ /app/www/public/build/
RUN php artisan filament:assets --no-interaction
