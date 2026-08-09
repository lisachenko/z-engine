<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2019, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine;

/**
 * Boots the engine bridge from Composer's autoloader, so consumers get an initialized Core
 * (issue #21)
 *
 * Registered through `autoload.files`, which means it runs once per process, before any
 * consumer code - and, because Composer orders dependencies first, before the `files` of every
 * package that depends on z-engine. A dependant should never have to know how the bridge is
 * started; it just uses `Core`, the `Reflection\*` wrappers and the services built on them.
 *
 * ## Preloading is the whole reason this file has logic in it
 *
 * `opcache.preload` runs a script at server start whose first act is `require vendor/autoload.php`
 * - which lands here. Booting unconditionally at that moment is what kept this issue open since
 * 2019: `Core::init()` would bind the definitions with `FFI::cdef()`, which is scoped to the
 * *preload request* and gone by the time the first real request arrives, and because that leaves
 * a bound engine behind, the `Core::preload()` the script calls next would find its work already
 * done and never register the persistent scope. Every following request then fails, with the
 * preload script looking correct.
 *
 * So the preload stage has to be recognised and served differently: `Core::preload()` there
 * (`FFI::load()`, which is what publishes the definitions under `FFI_SCOPE` for the life of the
 * server), plain `Core::init()` everywhere else - which picks those definitions up through
 * `FFI::scope()` when preloading ran, and falls back to `FFI::cdef()` when it did not.
 *
 * The stage is identified by the one fact that distinguishes it: during preloading the script
 * named by `opcache.preload` is the first file the process included. In a request the entry
 * script holds that position.
 *
 * ## Failure is silent here, and explained where it matters
 *
 * A host without ext-ffi, with `ffi.enable=0`, on an unsupported PHP minor or without generated
 * definitions for its platform cannot run the engine - but it can still legitimately autoload
 * this package: static analysis, a test suite whose engine-driving cases self-skip, `composer
 * install` running its own tooling. Throwing from an autoloaded file would break all of them at
 * `require`, so a boot that cannot happen leaves `Core` uninitialized and says nothing.
 *
 * Nothing is lost by that silence: `Core::init()` is idempotent and re-invocable, so code that
 * actually needs the engine calls it and gets either a no-op or the same explanatory
 * `RuntimeException` this file swallowed. Ask `Core::isInitialized()` to test the state without
 * committing to it.
 *
 * Set `ZENGINE_AUTOBOOT=0` to skip this entirely and boot by hand.
 */
(static function (): void {
    if (getenv('ZENGINE_AUTOBOOT') === '0' || Core::isInitialized()) {
        return;
    }

    $preloadScript = (string) ini_get('opcache.preload');
    $includedFiles = get_included_files();
    $isPreloadStage = $preloadScript !== ''
        && isset($includedFiles[0])
        && realpath($preloadScript) === $includedFiles[0];

    try {
        if ($isPreloadStage) {
            Core::preload();
        } else {
            Core::init();
        }
    } catch (\Throwable) {
        // This host cannot run the engine. Core stays uninitialized and Core::init() will
        // explain why to whoever actually needs it - see the note above.
    }
})();
