/*
 * observer_enabler - test-only extension module for z-engine's ObserverHook tests.
 *
 * Registers an fcall observer during MINIT that observes nothing ({NULL, NULL}
 * handlers for every function). Its only purpose is to make the engine enable
 * the zend_observer machinery - reserve the op_array / internal-function
 * extension slots in zend_observer_post_startup() - so that the runtime
 * per-function observer API becomes usable by z-engine's ObserverHook.
 *
 * This is the minimal "startup-time observer provider" described in
 * docs/observer-hook.md. Built on demand by ObserverHookFiringTest via
 * phpize / configure / make; never installed permanently.
 */
#ifdef HAVE_CONFIG_H
# include "config.h"
#endif

#include "php.h"
#include "zend_observer.h"

static zend_observer_fcall_handlers observer_enabler_init(zend_execute_data *execute_data)
{
    zend_observer_fcall_handlers handlers = {NULL, NULL};

    (void) execute_data;

    return handlers;
}

static PHP_MINIT_FUNCTION(observer_enabler)
{
    zend_observer_fcall_register(observer_enabler_init);

    return SUCCESS;
}

zend_module_entry observer_enabler_module_entry = {
    STANDARD_MODULE_HEADER,
    "observer_enabler",
    NULL,                          /* functions */
    PHP_MINIT(observer_enabler),
    NULL,                          /* MSHUTDOWN */
    NULL,                          /* RINIT */
    NULL,                          /* RSHUTDOWN */
    NULL,                          /* MINFO */
    "1.0.0",
    STANDARD_MODULE_PROPERTIES
};

#ifdef COMPILE_DL_OBSERVER_ENABLER
ZEND_GET_MODULE(observer_enabler)
#endif
