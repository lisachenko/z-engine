<?php
$top = "";
$bottom = "FROM scratch\n";
foreach([[8, 1, "bullseye", "srcml_1.0.0-1_ubuntu20.04.deb"], [8, 0, "buster", "srcml_1.0.0-1_ubuntu19.04.deb"], [7, 4, "buster", "srcml_1.0.0-1_ubuntu19.04.deb"]] as [$major, $minor, $distro, $srcmlDeb])
    foreach(["x64"] as $arch)
        foreach([["zts", "-zts"], ["nts", ""]] as [$threadSafety, $ts])
            foreach(["linux"] as $os) {
                $top .= <<<DOCKERFILE
FROM php:$major.$minor.0$ts-$distro AS php-$major-$minor-$arch-$threadSafety-$os-build
RUN apt update
RUN apt install libffi-dev
RUN docker-php-ext-install ffi
RUN curl http://131.123.42.38/lmcrs/v1.0.0/$srcmlDeb > srcml.deb
RUN apt install -y ./srcml.deb
COPY process.php .
COPY symbols.txt .
RUN php process.php $os
FROM scratch AS php-$major-$minor-$arch-$threadSafety-$os
COPY --from=php-$major-$minor-$arch-$threadSafety-$os-build /engine-$major-$minor-$arch-$threadSafety-$os.h /
COPY --from=php-$major-$minor-$arch-$threadSafety-$os-build /constants-$major-$minor-$arch-$threadSafety-$os.php /


DOCKERFILE;
                $bottom .= "COPY --from=php-$major-$minor-$arch-$threadSafety-$os /* /\n";
    }

echo "$top$bottom";