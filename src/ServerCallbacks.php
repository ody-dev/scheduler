<?php

namespace Ody\Scheduler;

use Ody\Core\Monolog\Logger;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

class ServerCallbacks
{
    /**
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public static function onRequest(Request $request, Response $response): void
    {

    }

    public static function onStart (Server $server): void
    {
        $protocol = ($server->ssl) ? "https" : "http";
        Logger::write('info', 'schduler started successfully');
        Logger::write('info', "listening on $protocol://$server->host:$server->port");
        Logger::write('info', 'press Ctrl+C to stop the server');
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