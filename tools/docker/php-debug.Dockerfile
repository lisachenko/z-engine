# Debug PHP build for z-engine development and CI.
#
# A --enable-debug build turns the silent memory corruption that z-engine can
# cause into loud Zend assertion failures, which is exactly what you want when
# poking at engine internals. Built from the official image's own php source so
# it matches the ABI of the generated headers for this branch's PHP minor.
#
# Built inline (with layer caching) by the tests-internal-debug CI job and run
# in place - no registry involved. Composer runs on the host; this image only
# needs to *run* PHPUnit, so it carries FFI (built in - a debug PHP cannot load
# the base image's release-ABI ffi.so) plus the extensions PHPUnit needs at
# runtime (dom/xml/xmlwriter from libxml, mbstring). Build locally with:
#   docker build -f tools/docker/php-debug.Dockerfile -t z-engine-php:debug .
ARG PHP_VERSION=8.4
FROM php:${PHP_VERSION}-cli AS build

# Build dependencies for the default-enabled extensions (which the official
# image's runtime layer strips the -dev libs for): FFI, libxml-based extensions
# (dom/xml/xmlwriter/simplexml), mbstring (oniguruma) and sqlite3/pdo_sqlite.
RUN apt-get update \
    && apt-get install -y --no-install-recommends \
        libffi-dev libxml2-dev libonig-dev libsqlite3-dev \
    && rm -rf /var/lib/apt/lists/*

# Rebuild PHP from the bundled source with debug + a curated extension set.
# Replaying the base image's full configure options is not possible: their
# build-time -dev libraries were stripped from the runtime image. The default
# extension set (dom, xml, xmlwriter, simplexml, ctype, tokenizer, phar, json,
# filter) builds automatically once libxml is present.
RUN set -eux; \
    docker-php-source extract; \
    cd /usr/src/php; \
    ./configure \
        --enable-debug \
        --with-ffi \
        --enable-mbstring \
        > /tmp/configure.log 2>&1 || (cat /tmp/configure.log && false); \
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
    } > /usr/local/etc/php/conf.d/z-engine.ini

RUN php -v && php -m | grep -qi '^FFI$' && php -r 'assert(PHP_DEBUG === 1); echo "debug FFI build OK\n";'

WORKDIR /app
