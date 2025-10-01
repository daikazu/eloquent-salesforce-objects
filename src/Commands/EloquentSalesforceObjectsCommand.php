<?php

declare(strict_types=1);

namespace Daikazu\EloquentSalesforceObjects\Commands;

use Illuminate\Console\Command;

class EloquentSalesforceObjectsCommand extends Command
{
    public $signature = 'eloquent-salesforce-objects';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
