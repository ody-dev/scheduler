<?php

namespace Ody\Scheduler;

class SchedulerEvent
{
    public const string ON_REQUEST = 'request';

    const string ON_START = 'start';

    const string ON_WORKER_START = 'workerStart';
}