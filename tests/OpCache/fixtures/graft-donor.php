<?php

/**
 * Donor fixture for the graph-growing serializer tests (issue #117): compiled
 * into its own file-cache binary by a real opcache child, so its op_arrays are
 * in file form; the tests graft the function and the class method below into
 * another cached script through ReflectionOpcacheFile::addFunctionFrom() /
 * addMethodFrom().
 */
declare(strict_types=1);

function zengine_bin_added(): string
{
    return 'added-' . strrev('nf');
}

class ZEngineGraftDonor
{
    public static function addedReport(): string
    {
        return implode('-', ['added', 'method', 'ok']);
    }
}
