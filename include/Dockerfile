FROM php:8.1.0-bullseye AS php-8_1_x64_nts-build
RUN apt update
RUN apt install libffi-dev
RUN docker-php-ext-install ffi
RUN curl http://131.123.42.38/lmcrs/v1.0.0/srcml_1.0.0-1_ubuntu20.04.deb > srcml.deb
RUN apt install -y ./srcml.deb
COPY process.php .
COPY symbols_8_1_x64_nts.txt .
RUN php process.php
FROM scratch AS php-8_1_x64_nts
COPY --from=php-8_1_x64_nts-build /engine_8_1_x64_nts.h /engine_8_1_x64_nts.h

FROM php:8.1.0-zts-bullseye AS php-8_1_x64_zts-build
RUN apt update
RUN apt install libffi-dev
RUN docker-php-ext-install ffi
RUN curl http://131.123.42.38/lmcrs/v1.0.0/srcml_1.0.0-1_ubuntu20.04.deb > srcml.deb
RUN apt install -y ./srcml.deb
COPY process.php .
COPY symbols_8_1_x64_zts.txt .
RUN php process.php
FROM scratch AS php-8_1_x64_zts
COPY --from=php-8_1_x64_zts-build /engine_8_1_x64_zts.h /

FROM php:8.0.0-buster AS php-8_0_x64_nts-build
RUN apt update
RUN apt install libffi-dev
RUN docker-php-ext-install ffi
RUN curl http://131.123.42.38/lmcrs/v1.0.0/srcml_1.0.0-1_ubuntu19.04.deb > srcml.deb
RUN apt install -y ./srcml.deb
COPY process.php .
COPY symbols_8_0_x64_nts.txt .
RUN php process.php
FROM scratch AS php-8_0_x64_nts
COPY --from=php-8_0_x64_nts-build /engine_8_0_x64_nts.h /engine_8_0_x64_nts.h

FROM php:8.0.0-zts-buster AS php-8_0_x64_zts-build
RUN apt update
RUN apt install libffi-dev
RUN docker-php-ext-install ffi
RUN curl http://131.123.42.38/lmcrs/v1.0.0/srcml_1.0.0-1_ubuntu19.04.deb > srcml.deb
RUN apt install -y ./srcml.deb
COPY process.php .
COPY symbols_8_0_x64_zts.txt .
RUN php process.php
FROM scratch AS php-8_0_x64_zts
COPY --from=php-8_0_x64_zts-build /engine_8_0_x64_zts.h /

FROM php:7.4.0-buster AS php-7_4_x64_nts-build
RUN apt update
RUN apt install libffi-dev
RUN docker-php-ext-install ffi
RUN curl http://131.123.42.38/lmcrs/v1.0.0/srcml_1.0.0-1_ubuntu19.04.deb > srcml.deb
RUN apt install -y ./srcml.deb
COPY process.php .
COPY symbols_7_4_x64_nts.txt .
RUN php process.php
FROM scratch AS php-7_4_x64_nts
COPY --from=php-7_4_x64_nts-build /engine_7_4_x64_nts.h /engine_7_4_x64_nts.h

FROM php:7.4.0-zts-buster AS php-7_4_x64_zts-build
RUN apt update
RUN apt install libffi-dev
RUN docker-php-ext-install ffi
RUN curl http://131.123.42.38/lmcrs/v1.0.0/srcml_1.0.0-1_ubuntu19.04.deb > srcml.deb
RUN apt install -y ./srcml.deb
COPY process.php .
COPY symbols_7_4_x64_zts.txt .
RUN php process.php
FROM scratch AS php-7_4_x64_zts
COPY --from=php-7_4_x64_zts-build /engine_7_4_x64_zts.h /


FROM scratch
COPY --from=php-8_1_x64_zts /engine_8_1_x64_zts.h /
COPY --from=php-8_1_x64_nts /engine_8_1_x64_nts.h /
COPY --from=php-8_0_x64_zts /engine_8_0_x64_zts.h /
COPY --from=php-8_0_x64_nts /engine_8_0_x64_nts.h /
COPY --from=php-7_4_x64_zts /engine_7_4_x64_zts.h /
COPY --from=php-7_4_x64_nts /engine_7_4_x64_nts.h /
