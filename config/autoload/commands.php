<?php

declare(strict_types=1);
/**
 * This file is part of Hyperf.
 *
 * @link     https://www.hyperf.io
 * @document https://hyperf.wiki
 * @contact  group@hyperf.io
 * @license  https://github.com/hyperf/hyperf/blob/master/LICENSE
 */
return [
    App\Command\InitAdminCommand::class,
    App\Command\ImportCategoryConversionCommand::class,
    App\Command\ImportInsuranceLevelConfigCommand::class,
    App\Command\ImportInsuranceDataCommand::class,
    App\Command\CheckLevelDataCommand::class,
];
