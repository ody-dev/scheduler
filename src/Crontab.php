<?php

namespace Ody\Scheduler;

use Ody\Swoole\Process\Socket\UnixProcessConfig;
use Ody\Scheduler\Exception\Exception;
use Ody\Scheduler\Protocol\Command;
use Ody\Scheduler\Protocol\Pack;
use Ody\Scheduler\Protocol\Response;
use Ody\Scheduler\Protocol\UnixClient;
use Swoole\Server;
use Swoole\Table;
use Ody\Swoole\Process\Config as ProcessConfig;

class Crontab
{
    private Table $schedulerTable;
    private Table $workerStatisticTable;
    private array $jobs = [];
    /** @var Config */
    private ?Config $config;
    private bool $hasAttach = false;

    function __construct(?Config $config = null)
    {
        if ($config == null) {
            $config = new Config();
        }
        $this->config = $config;
        $this->schedulerTable = new Table(2048);
        $this->schedulerTable->column('taskRule', Table::TYPE_STRING, 35);
        $this->schedulerTable->column('taskRunTimes', Table::TYPE_INT, 8);
        $this->schedulerTable->column('taskNextRunTime', Table::TYPE_INT, 10);
        $this->schedulerTable->column('taskCurrentRunTime', Table::TYPE_INT, 10);
        $this->schedulerTable->column('isStop', Table::TYPE_INT, 1);
        $this->schedulerTable->create();

        $this->workerStatisticTable = new Table(1024);
        $this->workerStatisticTable->column('runningNum', Table::TYPE_INT, 8);
        $this->workerStatisticTable->create();
    }

    function getConfig(): Config
    {
        return $this->config;
    }

    public function register(JobInterface $job): Crontab
    {
        if (!isset($this->jobs[$job->jobName()])) {
            $this->jobs[$job->jobName()] = $job;
            return $this;
        } else {
            throw new Exception("{$job->jobName()} hash been register");
        }
    }

    public function attachToServer(Server $server): void
    {
        if (empty($this->jobs)) {
            return;
        }

        if($this->hasAttach){
            return;
        }

        $this->hasAttach = true;

        $c = new ProcessConfig();
        $c->setEnableCoroutine(true);
        $c->setProcessName("{$this->config->getServerName()}.CrontabScheduler");
        $c->setProcessGroup("{$this->config->getServerName()}.Crontab");
        $c->setArg([
            'jobs' => $this->jobs,
            'schedulerTable' => $this->schedulerTable,
            'crontabInstance' => $this
        ]);
        $server->addProcess((new Scheduler($c))->getProcess());

        for ($i = 0; $i < $this->config->getWorkerNum(); $i++) {
            //设置统计table信息和worker注册
            $this->workerStatisticTable->set($i, [
                'runningNum' => 0
            ]);
            $c = new UnixProcessConfig();
            $c->setEnableCoroutine(true);
            $c->setProcessName("{$this->config->getServerName()}.Worker.{$i}");
            $c->setProcessGroup("{$this->config->getServerName()}.Crontab");
            $c->setArg([
                'jobs' => $this->jobs,
                'schedulerTable' => $this->schedulerTable,
                'workerStatisticTable' => $this->workerStatisticTable,
                'crontabInstance' => $this,
                'workerIndex' => $i
            ]);
            $c->setSocketFile($this->indexToSockFile($i));
            $server->addProcess((new Worker($c))->getProcess());
        }

        foreach ($this->jobs as $name => $job){
            if($job instanceof PrivilegeJobInterface){
                $c = new UnixProcessConfig();
                $c->setEnableCoroutine(true);
                $c->setProcessName("{$this->config->getServerName()}.Worker.{$name}");
                $c->setProcessGroup("{$this->config->getServerName()}.Crontab");
                $c->setArg([
                    'jobs' => $this->jobs,
                    'schedulerTable' => $this->schedulerTable,
                    'workerStatisticTable' => $this->workerStatisticTable,
                    'crontabInstance' => $this,
                    'workerIndex' => $name
                ]);
                $c->setSocketFile($this->indexToSockFile($name));
                $server->addProcess((new Worker($c))->getProcess());
            }
        }
    }

    public function rightNow(string $jobName): ?Response
    {
        if(!isset($this->jobs[$jobName])){
            throw new Exception("crontab job {$jobName} not exist");
        }
        $request = new Command();
        $request->setCommand(Command::COMMAND_EXEC_JOB);
        $request->setArg($jobName);
        if($this->jobs[$jobName] instanceof PrivilegeJobInterface){
            return $this->sendToWorker($request, $jobName);
        }else{
            return $this->sendToWorker($request, $this->idleWorkerIndex());
        }

    }

    public function stop(string $jobName): bool
    {
        if (isset($this->jobs[$jobName])) {
            $this->schedulerTable->set($jobName, ['isStop' => 1]);
            return true;
        } else {
            return false;
        }
    }

    public function stopAll(): bool
    {
        foreach ($this->schedulerTable as $key => $item) {
            $this->schedulerTable->set($key, ['isStop' => 1]);
        }
        return true;
    }

    public function resume(string $jobName): bool
    {
        if (isset($this->jobs[$jobName])) {
            $this->schedulerTable->set($jobName, ['isStop' => 0]);
            return true;
        } else {
            return false;
        }
    }

    public function resumeAll(): bool
    {
        foreach ($this->schedulerTable as $key => $item) {
            $this->schedulerTable->set($key, ['isStop' => 0]);
        }
        return true;
    }

    function resetJobRule($jobName, $taskRule): bool
    {
        if (isset($this->jobs[$jobName])) {
            $this->schedulerTable->set($jobName, ['taskRule' => $taskRule]);
            return true;
        } else {
            return false;
        }
    }

    function schedulerTable(): Table
    {
        return $this->schedulerTable;
    }

    private function idleWorkerIndex(): int
    {
        $index = 0;
        $min = null;
        foreach ($this->workerStatisticTable as $key => $item) {
            if(!is_numeric($key)){
                continue;
            }
            $runningNum = intval($item['runningNum']);
            if ($min === null) {
                $min = $runningNum;
            }
            if ($runningNum < $min) {
                $index = $key;
                $min = $runningNum;
            }
        }
        return $index;
    }

    private function indexToSockFile(int|string $index): string
    {
        return $this->config->getTempDir() . "/{$this->config->getServerName()}.Worker.{$index}.sock";
    }

    private function sendToWorker(Command $command, int|string $index): ?Response
    {
        $data = Pack::pack(serialize($command));
        $client = new UnixClient($this->indexToSockFile($index), 10 * 1024 * 1024);
        $client->send($data);
        $data = $client->recv(3);
        if ($data) {
            $data = Pack::unpack($data);
            $data = unserialize($data);
            if ($data instanceof Response) {
                return $data;
            } else {
                return (new Response())->setStatus(Response::STATUS_ILLEGAL_PACKAGE)->setMsg('unserialize response as an Response instance fail');
            }
        } else {
            return (new Response())->setStatus(Response::STATUS_PACKAGE_READ_TIMEOUT)->setMsg('recv timeout from worker');
        }
    }
}