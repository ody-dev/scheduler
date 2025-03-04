<?php

namespace Ody\Scheduler\Jobs;

use Ody\Scheduler\PrivilegeJobInterface;

class JobPerMinWithPrivilege implements PrivilegeJobInterface
{

    public function jobName(): string
    {
        return 'JobPerMinWithPrivilege';
    }

    public function crontabRule(): string
    {
        return '*/1 * * * *';
    }

    public function run()
    {
        var_dump("JobPerMinWithPrivilege");
    }

    public function onException(\Throwable $throwable)
    {
        // TODO: Implement onException() method.
    }
}