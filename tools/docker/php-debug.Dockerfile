# Debug PHP build for z-engine development and CI.
#
# A --enable-debug build turns the silent memory corruption that z-engine can
# cause into loud Zend assertion failures, which is exactly what you want when
# poking at engine internals. Built from the official image's own php source so
# it matches the ABI of the generated headers for this branch's PHP minor.
#
# Published to ghcr by .github/workflows/publish-php-images.yml and consumed by
# the tests-internal-debug CI job. Build locally with:
#   docker build -f tools/docker/php-debug.Dockerfile -t z-engine-php:8.4-nts-debug .
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli AS build

RUN apt-get update \
    && apt-get install -y --no-install-recommends libffi-dev \
    && rm -rf /var/lib/apt/lists/*

# Rebuild PHP from the source bundled in the image, with debug + FFI enabled.
RUN set -eux; \
    docker-php-source extract; \
    cd /usr/src/php; \
    ./configure \
        --enable-debug \
        --with-ffi \
        --enable-opcache \
        $(php-config --configure-options 2>/dev/null || true) \
        > /tmp/configure.log 2>&1 || (cat /tmp/configure.log && false); \
    make -j"$(nproc)" > /tmp/make.log 2>&1 || (tail -50 /tmp/make.log && false); \
    make install; \
    docker-php-source delete

# Enable FFI and assertions and disable the JIT (it rewrites the executor
# internals z-engine hooks into).
RUN { \
      echo 'ffi.enable=1'; \
      echo 'zend.assertions=1'; \
      echo 'opcache.enable_cli=0'; \
      echo 'opcache.jit=off'; \
      echo 'opcache.jit_buffer_size=0'; \
    } > /usr/local/etc/php/conf.d/z-engine.ini

RUN php -v && php -m | grep -qi ffi && php -r 'assert(PHP_DEBUG === 1);'

WORKDIR /app
