<?php

namespace Ody\Scheduler;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Runtime;

class ServerCallbacks
{
    /**
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public static function onRequest(\Swoole\Http\Request $request, \Swoole\Http\Response $response): void
    {

    }

    public static function onStart (Server $server): void
    {
        $protocol = ($server->ssl) ? "https" : "http";
        echo "   \033[1mSUCCESS\033[0m  Scheduler instance started successfully\n";
        echo "   \033[1mINFO\033[0m  listen on " . $protocol . "://" . $server->host . ':' . $server->port . PHP_EOL;
        echo "   \033[1mINFO\033[0m  press Ctrl+C to stop the server\n";
    }

    public static function onWorkerStart(Server $server, int $workerId): void
    {
        // Save worker ids to serverState.json
        if ($workerId == config('scheduler.additional.worker_num') - 1) {
            $workerIds = [];
            for ($i = 0; $i < config('scheduler.additional.worker_num'); $i++) {
                $workerIds[$i] = $server->getWorkerPid($i);
            }

            $serveState = SchedulerServerState::getInstance();
            $serveState->setMasterProcessId($server->getMasterPid());
            $serveState->setManagerProcessId($server->getManagerPid());
            $serveState->setWorkerProcessIds($workerIds);
        }
    }
}