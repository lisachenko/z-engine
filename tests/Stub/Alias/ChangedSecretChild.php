<?php

/**
 * Z-Engine framework
 *
 * @copyright Copyright 2026, Lisachenko Alexander <lisachenko.it@gmail.com>
 *
 * This source file is subject to the license that is bundled
 * with this source code in the file LICENSE.
 *
 */
declare(strict_types=1);

namespace ZEngine\Stub\Alias;

/**
 * Target of the override of a parent-private method: before the publication its table holds
 * the private ChangedSecretBase::m() entry, afterwards the public donor body
 * (AddMethodChangedFlagTest::testOverrideOfAParentPrivateMethodIsMarkedChangedAndLinksNoPrototype)
 *
 * @method string m() the public override addMethod() publishes (the static analyser only sees the inherited private declaration)
 */
class ChangedSecretChild extends ChangedSecretBase {}
