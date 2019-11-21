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

namespace ZEngine\Macro;

use php_user_filter as PhpStreamFilter;
use RuntimeException;

class DefinitionLoader extends PhpStreamFilter
{
    /**
     * Default PHP filter name for registration
     */
    private const FILTER_IDENTIFIER = 'z-engine.def.loader';

    /**
     * String buffer
     */
    private string $data = '';

    /**
     * {@inheritdoc}
     */
    public function filter($in, $out, &$consumed, $closing)
    {
        /** Simple pattern to match if(n?)def..endif constructions */
        static $pattern = '/^#(ifn?def) +(.*?)\n([\s\S]*?)(#endif)/m';

        while ($bucket = stream_bucket_make_writeable($in)) {
            $this->data .= $bucket->data;
        }

        if ($closing || feof($this->stream)) {
            $consumed = strlen($this->data);

            $macros = $this->resolveSystemMacros();
            // Now we emulate resolution of ifdef..endif constructions
            $transformedData = $this->data;
            $transformedData = preg_replace_callback($pattern, function (array $matches) use ($macros): string {
                [, $keyword, $macro, $body] = $matches;
                if ($keyword === 'ifdef' && !isset($macros[$macro])) {
                    $body = '';
                } elseif ($keyword === 'ifndef' && isset($macros[$macro])) {
                    $body = '';
                }

                return $body;
            }, $transformedData);

            // Simple macros resolving via strtr
            $transformedData = strtr($transformedData, $macros);

            $bucket = stream_bucket_new($this->stream, $transformedData);
            stream_bucket_append($out, $bucket);

            return PSFS_PASS_ON;
        }

        return PSFS_FEED_ME;
    }

    /**
     * Wraps given filename with stream resolver
     *
     * @param string $filename
     */
    public static function wrap(string $filename): string
    {
        // Let's perform self-registration on first query
        if (!in_array(self::FILTER_IDENTIFIER, stream_get_filters(), true)) {
            self::register();
        }

        return 'php://filter/read=' . self::FILTER_IDENTIFIER . '/resource=' . $filename;
    }

    /**
     * Register current loader as stream filter in PHP
     *
     * @throws RuntimeException If registration was failed
     */
    private static function register(): void
    {
        $result = stream_filter_register(self::FILTER_IDENTIFIER, self::class);
        if ($result === false) {
            throw new RuntimeException('Stream filter was not registered');
        }
    }

    private function resolveSystemMacros(): array
    {
        $isThreadSafe      = ZEND_THREAD_SAFE;
        $isWindowsPlatform = stripos(PHP_OS, 'WIN') === 0;
        $is64BitPlatform   = PHP_INT_SIZE === 8;

        // TODO: support ts/nts x86/x64 combination
        if ($isThreadSafe || !$is64BitPlatform) {
            throw new \RuntimeException('Only x64 non thread-safe versions of PHP are supported');
        }

        $macros = [
            'ZEND_API'      => '__declspec(dllimport)',
            'ZEND_FASTCALL' => $isWindowsPlatform ? '__vectorcall' : '',

            'ZEND_MAX_RESERVED_RESOURCES' => '6',
            'ZEND_LIBRARY_NAME'           => $isWindowsPlatform ? 'php7.dll' : '',
        ];

        if ($isWindowsPlatform) {
            $macros['ZEND_WIN32'] = '1';
        }

        if ($isThreadSafe) {
            $macros['ZTS'] = '1';
        }

        return $macros;
    }
}
