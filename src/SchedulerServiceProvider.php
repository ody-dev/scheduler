<?php

namespace Ody\Scheduler;

use Ody\Core\Foundation\Providers\ServiceProvider;
use Ody\Scheduler\Commands\StartCommand;

class SchedulerServiceProvider extends ServiceProvider
{
    public function boot(): void
    {

    }

    public function register()
    {
        $this->commands = [
            StartCommand::class,
        ];
    }
}