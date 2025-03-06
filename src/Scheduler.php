<?php

namespace Ody\Scheduler;

use Cron\CronExpression;
use Ody\Swoole\Process\AbstractProcess;
use Ody\Swoole\Timer;
use Swoole\Table;

class Scheduler extends AbstractProcess
{
    /** @var Table */
    private $schedulerTable;

    /** @var Crontab */
    private Crontab $crontabInstance;

    private array $timerIds = [];

    protected function run($arg): void
    {
        $this->crontabInstance = $arg['crontabInstance'];
        $this->schedulerTable = $arg['schedulerTable'];

        $keys = [];
        foreach ($this->schedulerTable as $key => $value) {
            $keys[] = $key;
        }
        foreach ($keys as $key) {
            $this->schedulerTable->del($key);
        }

        $jobs = $arg['jobs'];

        /**
         * @var  $jobName
         * @var JobInterface $job
         */
        foreach ($jobs as $jobName => $job) {
            $nextTime = CronExpression::factory($job->crontabRule())
                ->getNextRunDate()
                ->getTimestamp();

            $this->schedulerTable->set($jobName, [
                'taskRule' => $job->crontabRule(),
                'taskRunTimes' => 0,
                'taskNextRunTime' => $nextTime,
                'taskCurrentRunTime' => 0,
                'isStop' => 0
            ]);
        }
        $this->cronProcess();

        Timer::getInstance()->loop(8 * 1000, function () {
            $this->cronProcess();
        });
    }

    private function cronProcess(): void
    {
        foreach ($this->schedulerTable as $jobName => $task) {
            if (intval($task['isStop']) == 1) {
                if(isset($this->timerIds[$jobName])){
                    $timerId = $this->timerIds[$jobName];
                    Timer::getInstance()->clear($timerId);
                    unset($this->timerIds[$jobName]);
                }
                continue;
            }
            $nextRunTime = CronExpression::factory($task['taskRule'])->getNextRunDate()->getTimestamp();
            if ($task['taskNextRunTime'] != $nextRunTime) {
                $this->schedulerTable->set($jobName, ['taskNextRunTime' => $nextRunTime]);
            }

            if (isset($this->timerIds[$jobName])) {
                continue;
            }
            $distanceTime = $nextRunTime - time();
            $timerId = Timer::getInstance()->after($distanceTime * 1000, function () use ($jobName) {
                unset($this->timerIds[$jobName]);
                try {
                    $this->crontabInstance->rightNow($jobName);
                } catch (\Throwable $throwable) {
                    $call = $this->crontabInstance->getConfig()->getOnException();
                    if (is_callable($call)) {
                        call_user_func($call, $throwable);
                    } else {
                        throw $throwable;
                    }
                }
            });
            if ($timerId) {
                $this->timerIds[$jobName] = $timerId;
            }
        }
    }
}