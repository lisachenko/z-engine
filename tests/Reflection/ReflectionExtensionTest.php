<?php
/**
 * Z-Engine framework
 *
 * @copyright Copyright 2020, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\Reflection;


use PHPUnit\Framework\TestCase;

class ReflectionExtensionTest extends TestCase
{
    private ReflectionExtension $refExtension;

    protected function setUp(): void
    {
        // As FFI is always required for this framework, we can be sure that it is present
        $this->refExtension = new ReflectionExtension('ffi');
    }

    public function testReturnsThreadSafe(): void
    {
        $this->assertSame(ZEND_THREAD_SAFE, $this->refExtension->isThreadSafe());
    }

    public function testReturnsDebug(): void
    {
        $this->assertSame(ZEND_DEBUG_BUILD, $this->refExtension->isDebug());
    }

    public function testModuleWasStarted(): void
    {
        // Built-in modules always started, only our custom modules may be in non-started state
        $this->assertSame(true, $this->refExtension->wasModuleStarted());
    }

    public function testReturnsModuleNumber(): void
    {
        // each module has it's own unique module number greater than zero
        $this->assertGreaterThan(0,$this->refExtension->getModuleNumber());
    }

    public function testGetGlobals(): void
    {
        /* @see https://github.com/php/php-src/blob/PHP-7.4/ext/ffi/php_ffi.h#L33-L63 */
        $this->assertNotNull($this->refExtension->getGlobals());
    }

    public function testGetGlobalsSize(): void
    {
        /* @see https://github.com/php/php-src/blob/PHP-7.4/ext/ffi/php_ffi.h#L33-L63 */
        $this->assertGreaterThan(0, $this->refExtension->getGlobalsSize());
    }
}
