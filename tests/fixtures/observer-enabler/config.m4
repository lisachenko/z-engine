dnl config.m4 for the test-only observer_enabler extension (see observer_enabler.c)
PHP_ARG_ENABLE([observer_enabler],
  [whether to enable observer_enabler],
  [AS_HELP_STRING([--enable-observer-enabler], [Enable the observer_enabler test extension])],
  [yes])

if test "$PHP_OBSERVER_ENABLER" != "no"; then
  PHP_NEW_EXTENSION(observer_enabler, observer_enabler.c, $ext_shared)
fi
