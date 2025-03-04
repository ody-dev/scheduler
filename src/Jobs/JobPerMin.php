<?php

namespace Ody\Scheduler\Jobs;

use Ody\Scheduler\JobInterface;

class JobPerMin implements JobInterface
{

    public function jobName(): string
    {
        return 'JobPerMin';
    }

    public function crontabRule(): string
    {
        return '*/1 * * * *';
    }

    public function run()
    {
        var_dump(time(), 'JobPerMin');
        return time();
    }

    public function onException(\Throwable $throwable)
    {
        throw $throwable;
    }
}