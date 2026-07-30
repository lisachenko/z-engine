# Debug PHP build for z-engine development and CI.
#
# A --enable-debug build turns the silent memory corruption that z-engine can
# cause into loud Zend assertion failures, which is exactly what you want when
# poking at engine internals. Built from the official image's own php source so
# it matches the ABI of the generated headers for this branch's PHP minor.
#
# Built inline (with layer caching) by the tests-internal-debug CI job and run
# in place - no registry involved. Build locally with:
#   docker build -f tools/docker/php-debug.Dockerfile -t z-engine-php:debug .
#
# Note: a debug PHP cannot load the release-compiled shared extensions the base
# image ships (ABI differs), so FFI is compiled statically here and the stale
# docker-php-ext ini files are removed.
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli AS build

RUN apt-get update \
    && apt-get install -y --no-install-recommends libffi-dev \
    && rm -rf /var/lib/apt/lists/*

# Rebuild PHP from the bundled source with debug + built-in FFI, replaying the
# image's own configure options (minus the cosmetic PHP_UNAME assignment, whose
# unquoted space-separated value would otherwise be parsed as a bogus option).
RUN set -eux; \
    docker-php-source extract; \
    cd /usr/src/php; \
    configureOptions="$(php-config --configure-options | sed 's/ PHP_UNAME=[^=]*Docker//')"; \
    # shellcheck disable=SC2086
    ./configure --enable-debug --with-ffi $configureOptions > /tmp/configure.log 2>&1 \
        || (cat /tmp/configure.log && false); \
    make -j"$(nproc)" > /tmp/make.log 2>&1 || (tail -80 /tmp/make.log && false); \
    make install; \
    docker-php-source delete

# Drop the base image's shared-extension enablers: they point at release-ABI
# .so files that a debug PHP refuses to load (and ffi is now built in).
RUN rm -f /usr/local/etc/php/conf.d/docker-php-ext-*.ini

# Enable FFI and assertions and disable the JIT (it rewrites the executor
# internals z-engine hooks into).
RUN { \
      echo 'ffi.enable=1'; \
      echo 'zend.assertions=1'; \
      echo 'opcache.jit=off'; \
      echo 'opcache.jit_buffer_size=0'; \
    } > /usr/local/etc/php/conf.d/z-engine.ini

RUN php -v && php -m | grep -qi '^FFI$' && php -r 'assert(PHP_DEBUG === 1); echo "debug FFI build OK\n";'

WORKDIR /app
