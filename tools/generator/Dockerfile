# Header-generation image: runs tools/generator against the exact PHP build
# shipped in the official docker image, then exports the three artifacts
# (engine.h, constants.php, layouts.json) via the scratch stage.
#
# Driven by tools/generator/generate.php - do not run by hand unless debugging.
ARG BASE_IMAGE=php:8.4-cli
FROM ${BASE_IMAGE} AS build

WORKDIR /gen
COPY . .

# Optional: trust an intercepting build proxy. generate.php drops ca-bundle.crt
# into the build context when the host environment uses one; apt is switched to
# https so the proxy can relay it.
RUN if [ -f ca-bundle.crt ]; then \
        cp ca-bundle.crt /usr/local/share/ca-certificates/build-proxy-ca.crt \
        && update-ca-certificates \
        && sed -i 's|http://|https://|g' /etc/apt/sources.list.d/*.sources; \
    fi

RUN apt-get update \
    && apt-get install -y --no-install-recommends clang libffi-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install ffi \
    && docker-php-source extract

RUN php -d memory_limit=2G emit.php --php-src=/usr/src/php --out=/out --build-dir=/tmp/gen-build

FROM scratch
COPY --from=build /out /
