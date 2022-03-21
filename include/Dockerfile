FROM php:8.1.0-zts-bullseye AS php-8-1-x64-zts-linux-build
RUN apt update
RUN apt install libffi-dev
RUN docker-php-ext-install ffi
RUN curl http://131.123.42.38/lmcrs/v1.0.0/srcml_1.0.0-1_ubuntu20.04.deb > srcml.deb
RUN apt install -y ./srcml.deb
COPY process.php .
COPY symbols.txt .
RUN php process.php linux
FROM scratch AS php-8-1-x64-zts-linux
COPY --from=php-8-1-x64-zts-linux-build /engine-8-1-x64-zts-linux.h /
COPY --from=php-8-1-x64-zts-linux-build /constants-8-1-x64-zts-linux.php /

FROM php:8.1.0-bullseye AS php-8-1-x64-nts-linux-build
RUN apt update
RUN apt install libffi-dev
RUN docker-php-ext-install ffi
RUN curl http://131.123.42.38/lmcrs/v1.0.0/srcml_1.0.0-1_ubuntu20.04.deb > srcml.deb
RUN apt install -y ./srcml.deb
COPY process.php .
COPY symbols.txt .
RUN php process.php linux
FROM scratch AS php-8-1-x64-nts-linux
COPY --from=php-8-1-x64-nts-linux-build /engine-8-1-x64-nts-linux.h /
COPY --from=php-8-1-x64-nts-linux-build /constants-8-1-x64-nts-linux.php /

FROM php:8.0.0-zts-buster AS php-8-0-x64-zts-linux-build
RUN apt update
RUN apt install libffi-dev
RUN docker-php-ext-install ffi
RUN curl http://131.123.42.38/lmcrs/v1.0.0/srcml_1.0.0-1_ubuntu19.04.deb > srcml.deb
RUN apt install -y ./srcml.deb
COPY process.php .
COPY symbols.txt .
RUN php process.php linux
FROM scratch AS php-8-0-x64-zts-linux
COPY --from=php-8-0-x64-zts-linux-build /engine-8-0-x64-zts-linux.h /
COPY --from=php-8-0-x64-zts-linux-build /constants-8-0-x64-zts-linux.php /

FROM php:8.0.0-buster AS php-8-0-x64-nts-linux-build
RUN apt update
RUN apt install libffi-dev
RUN docker-php-ext-install ffi
RUN curl http://131.123.42.38/lmcrs/v1.0.0/srcml_1.0.0-1_ubuntu19.04.deb > srcml.deb
RUN apt install -y ./srcml.deb
COPY process.php .
COPY symbols.txt .
RUN php process.php linux
FROM scratch AS php-8-0-x64-nts-linux
COPY --from=php-8-0-x64-nts-linux-build /engine-8-0-x64-nts-linux.h /
COPY --from=php-8-0-x64-nts-linux-build /constants-8-0-x64-nts-linux.php /

FROM php:7.4.0-zts-buster AS php-7-4-x64-zts-linux-build
RUN apt update
RUN apt install libffi-dev
RUN docker-php-ext-install ffi
RUN curl http://131.123.42.38/lmcrs/v1.0.0/srcml_1.0.0-1_ubuntu19.04.deb > srcml.deb
RUN apt install -y ./srcml.deb
COPY process.php .
COPY symbols.txt .
RUN php process.php linux
FROM scratch AS php-7-4-x64-zts-linux
COPY --from=php-7-4-x64-zts-linux-build /engine-7-4-x64-zts-linux.h /
COPY --from=php-7-4-x64-zts-linux-build /constants-7-4-x64-zts-linux.php /

FROM php:7.4.0-buster AS php-7-4-x64-nts-linux-build
RUN apt update
RUN apt install libffi-dev
RUN docker-php-ext-install ffi
RUN curl http://131.123.42.38/lmcrs/v1.0.0/srcml_1.0.0-1_ubuntu19.04.deb > srcml.deb
RUN apt install -y ./srcml.deb
COPY process.php .
COPY symbols.txt .
RUN php process.php linux
FROM scratch AS php-7-4-x64-nts-linux
COPY --from=php-7-4-x64-nts-linux-build /engine-7-4-x64-nts-linux.h /
COPY --from=php-7-4-x64-nts-linux-build /constants-7-4-x64-nts-linux.php /

FROM scratch
COPY --from=php-8-1-x64-zts-linux /* /
COPY --from=php-8-1-x64-nts-linux /* /
COPY --from=php-8-0-x64-zts-linux /* /
COPY --from=php-8-0-x64-nts-linux /* /
COPY --from=php-7-4-x64-zts-linux /* /
COPY --from=php-7-4-x64-nts-linux /* /
