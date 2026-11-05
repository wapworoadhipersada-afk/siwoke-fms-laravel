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

arch('shared kernel does not depend on any feature module')
    ->expect('Modules\Shared')
    ->not->toUse(['Modules\IAM', 'Modules\Master', 'Modules\Asset', 'Modules\Ticket', 'Modules\Integration']);

arch('modules do not reach into the web layer of other modules')
    ->expect(['Modules\Master\Application', 'Modules\Master\Domain'])
    ->not->toUse(['Modules\IAM\Http', 'Modules\Master\Http']);

arch('the document module knows only the shared kernel, never what it documents')
    ->expect('Modules\Document')
    ->not->toUse(['Modules\Ticket', 'Modules\Asset', 'Modules\Master', 'Modules\Integration', 'Modules\IAM\Http']);

arch('operational modules never depend on the integration or report modules')
    ->expect(['Modules\Ticket', 'Modules\Asset', 'Modules\Master', 'Modules\Document', 'Modules\IAM', 'Modules\Shared'])
    ->not->toUse(['Modules\Integration', 'Modules\Report']);

arch('the integration module does not depend on reports')
    ->expect('Modules\Integration')
    ->not->toUse('Modules\Report');

arch('nothing depends on the report module, it only reads')
    ->expect('Modules\Report')
    ->not->toBeUsedIn(['Modules\Ticket', 'Modules\Asset', 'Modules\Master', 'Modules\Document', 'Modules\IAM', 'Modules\Integration']);
