<?php

namespace Ody\Scheduler;

interface JobInterface
{
    public function jobName(): string;

    public function crontabRule(): string;

    public function run();

    public function onException(\Throwable $throwable);
}