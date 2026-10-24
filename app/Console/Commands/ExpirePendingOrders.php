<?php

namespace App\Console\Commands;

use App\Actions\ExpireStaleOrders;
use Illuminate\Console\Command;

class ExpirePendingOrders extends Command
{
    protected $signature = 'orders:expire';

    protected $description = 'Expire unpaid orders whose payment window has passed and free their seats';

    public function handle(ExpireStaleOrders $expire): int
    {
        $this->info("Expired {$expire->handle()} unpaid order(s).");

        return self::SUCCESS;
    }
}
