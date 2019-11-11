set -x
if [ "TRAVIS_JOB_NAME" = '7.4-rc' ] ; then
    wget https://scrutinizer-ci.com/ocular.phar
    php ocular.phar code-coverage:upload --format=php-clover ./build/clover.xml
fi
