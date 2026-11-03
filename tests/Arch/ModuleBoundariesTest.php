<?php

declare(strict_types=1);

arch('asset module does not depend on ticket module')
    ->expect('Modules\Asset')
    ->not->toUse('Modules\Ticket');

arch('master data does not depend on transactional modules')
    ->expect('Modules\Master')
    ->not->toUse(['Modules\Ticket', 'Modules\Asset']);

arch('iam does not depend on other modules')
    ->expect('Modules\IAM')
    ->not->toUse(['Modules\Ticket', 'Modules\Asset', 'Modules\Master', 'Modules\Integration']);
