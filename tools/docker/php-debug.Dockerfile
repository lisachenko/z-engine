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
ARG PHP_VERSION=8.6
FROM php:${PHP_VERSION}-cli AS build

# Thread-safety mode: "nts" (default) or "zts". The base tag stays -cli either
# way - PHP is rebuilt from the bundled source tarball below, which is identical
# across the -cli/-zts image variants; only the configure flag differs.
ARG PHP_TS=nts

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
# The ini paths must be replayed explicitly: without them the rebuilt binary keeps
# the autoconf defaults and scans NO conf.d at all, which silently drops every
# setting (and every zend_extension) the image configures below.
RUN set -eux; \
    docker-php-source extract; \
    cd /usr/src/php; \
    ./configure \
        --enable-debug \
        $(test "$PHP_TS" = "zts" && echo --enable-zts) \
        --with-ffi \
        --enable-mbstring \
        --enable-opcache \
        --with-config-file-path="${PHP_INI_DIR}" \
        --with-config-file-scan-dir="${PHP_INI_DIR}/conf.d" \
        > /tmp/configure.log 2>&1 || (cat /tmp/configure.log && false); \
    make -j"$(nproc)" > /tmp/make.log 2>&1 || (tail -80 /tmp/make.log && false); \
    make install; \
    docker-php-source delete; \
    php --ini | grep -q "${PHP_INI_DIR}/conf.d"

# Drop the base image's shared-extension enablers: they point at release-ABI
# .so files that a debug PHP refuses to load (and ffi is now built in).
RUN rm -f /usr/local/etc/php/conf.d/docker-php-ext-*.ini

# Enable FFI and Zend assertions (the corruption airbag - the whole point of a
# debug build) and disable the JIT (it rewrites the executor internals z-engine
# hooks into). report_memleaks is ON: since the memory-lifetime overhaul
# (issue #62) wrappers own and release their engine references and hooks are
# uninstalled at shutdown, so every leak report is a genuine bug, not noise.
#
# opcache is LOADED but INACTIVE (opcache.enable_cli=0): the opcache-gated tests
# skip themselves unless the extension is present, and they activate it
# explicitly in the child processes that exercise shared memory - which is where
# a debug build turns the shared-memory corruption of issue #41 into a loud
# zend_function_dtor() assertion instead of a silent wrong result.
# Up to PHP 8.4 opcache is a shared zend_extension (the debug build installs
# its own ABI-tagged extension directory), loaded by absolute path - a bare
# file name resolves against whatever extension_dir the base image left behind.
# Since PHP 8.5 opcache is linked statically into the binary: no opcache.so
# exists and no zend_extension line is needed. The sanity check below asserts
# the extension is present either way.
RUN set -eux; \
    extension_dir="$(php-config --extension-dir)"; \
    { \
      if [ -f "${extension_dir}/opcache.so" ]; then \
        echo "zend_extension=${extension_dir}/opcache.so"; \
      fi; \
      echo 'ffi.enable=1'; \
      echo 'zend.assertions=1'; \
      echo 'report_memleaks=1'; \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=0'; \
      echo 'opcache.jit=off'; \
    } > /usr/local/etc/php/conf.d/z-engine.ini

# Sanity check: the interpreter's own banner must report a DEBUG build, the
# requested thread-safety mode, and FFI must be present (built in). (PHP_DEBUG's
# constant type is not worth asserting strictly - the banner is the ground truth
# PHP prints for itself.) A missing opcache is reported with the state that
# explains it, since the whole shared-memory test group depends on the extension
# being loaded.
RUN php -v && php -v | grep -q 'DEBUG' \
    && EXPECTED_TS="$PHP_TS" php -r 'exit(ZEND_THREAD_SAFE === (getenv("EXPECTED_TS") === "zts") ? 0 : 1);' \
    && php -m | grep -qi '^FFI$' \
    && { php -r 'exit(extension_loaded("Zend OPcache") ? 0 : 1);' \
         || { echo "Zend OPcache did not load:"; \
              php --ini; \
              cat /usr/local/etc/php/conf.d/z-engine.ini; \
              ls -la "$(php-config --extension-dir)"; \
              false; }; } \
    && echo "debug FFI+opcache build OK"

WORKDIR /app
