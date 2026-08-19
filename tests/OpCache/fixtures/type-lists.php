<?php

/**
 * Fixture compiled into the opcache file cache by the type-list relocation tests.
 *
 * Every zend_type_list shape the relocator must walk: a union parameter, a
 * union return type, an intersection parameter and return type, union and DNF
 * property types (the DNF one nests an intersection list inside a union list),
 * plus a no-argument driver the patch tests execute straight from the cache.
 */
declare(strict_types=1);

interface ZEngineTypeListOne {}
interface ZEngineTypeListTwo {}

class ZEngineTypeListExtra {}

class ZEngineTypeListImpl implements ZEngineTypeListOne, ZEngineTypeListTwo
{
    public ZEngineTypeListOne|ZEngineTypeListTwo|null $union = null;

    public (ZEngineTypeListOne&ZEngineTypeListTwo)|ZEngineTypeListExtra|null $dnf = null;
}

function zengine_bin_union_param(ZEngineTypeListOne|ZEngineTypeListTwo $subject): string
{
    return $subject::class;
}

function zengine_bin_union_return(bool $extra): ZEngineTypeListImpl|ZEngineTypeListExtra
{
    return $extra ? new ZEngineTypeListExtra() : new ZEngineTypeListImpl();
}

function zengine_bin_intersection(ZEngineTypeListOne&ZEngineTypeListTwo $subject): ZEngineTypeListOne&ZEngineTypeListTwo
{
    return $subject;
}

function zengine_bin_typelist_run(): string
{
    $impl        = new ZEngineTypeListImpl();
    $impl->union = zengine_bin_intersection($impl);
    $impl->dnf   = zengine_bin_union_return(false);

    return zengine_bin_union_param($impl->union ?? $impl) . ':tl-ok';
}
