# Runtime image for the wpc CLI. The version of PHP is a build argument so the
# CI can publish one image per supported PHP version (latest = newest stable).
ARG PHP_VERSION=8.5
FROM php:${PHP_VERSION}-alpine

ARG APP_VERSION=dev
LABEL org.opencontainers.image.title="wpc" \
      org.opencontainers.image.description="wp-content.io CLI" \
      org.opencontainers.image.version="${APP_VERSION}" \
      org.opencontainers.image.source="https://github.com/wp-content-io/wpc-cli" \
      org.opencontainers.image.vendor="wp-content.io <support@wp-content.io>"

# The CLI is a self-contained phar; the only extension it needs is zip
# (see composer.json). Build deps are installed in a virtual package and
# removed to keep the image small.
RUN apk add --no-cache libzip git unzip \
    && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS libzip-dev \
    && docker-php-ext-install zip \
    && apk del .build-deps \
    && rm -rf /var/cache/apk/*

# Composer (with git and unzip above) stays in the image: 1.x shipped it, and
# customer pipelines run `composer install` before `wpc plugin build`.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

COPY ./wpc.phar /usr/local/bin/wpc
RUN chmod +x /usr/local/bin/wpc

WORKDIR /app
# A CMD, never an ENTRYPOINT: GitLab CI hands the job script to the image's
# entrypoint, so `ENTRYPOINT ["wpc"]` turned every `image: …` + `script: wpc …`
# job into `wpc sh -c …` and failed it. `docker run <image> wpc plugin list`
# works either way.
CMD ["wpc"]
