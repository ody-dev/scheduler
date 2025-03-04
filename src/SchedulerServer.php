<?php

namespace Ody\Scheduler;

use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;
use Swoole\Runtime;

class SchedulerServer
{
    private $server;

    public static function init(): SchedulerServer
    {
        return new self();
    }

    public function start(): void
    {
        $this->server->start();
    }


    public function createServer($daemonize): SchedulerServer
    {

        $config = config('scheduler');
        $this->server = new Server(
            $config['host'],
            (int) $config['port'],
            $this->getSslConfig($config['ssl'], $config['mode']),
            $config["sock_type"]
        );

        var_dump($config["runtime"]["enable_coroutine"]);
        if($config["runtime"]["enable_coroutine"]) {
            Runtime::enableCoroutine(
                $config["runtime"]["hook_flag"]
            );
        }

        return $this;
    }

    /**
     * @param Request $request
     * @param Response $response
     * @return void
     */
    public static function onRequest(\Swoole\Http\Request $request, \Swoole\Http\Response $response): void
    {
        var_dump($request);
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

    /**
     * @param array $config
     * @param $serverMode
     * @return int
     */
    private function getSslConfig(array $config, $serverMode): int
    {
        if (
            !is_null($config["ssl_cert_file"]) &&
            !is_null($config["ssl_key_file"])
        ) {
            return !is_null($serverMode) ? $serverMode : SWOOLE_SSL;
        }

        return $serverMode;
    }

    public function registerCallbacks(array $callbacks): static
    {
        foreach ($callbacks as $event => $callback) {
            $this->server->on($event, [...$callback]);
        }

        return $this;
    }

    public function setServerConfig(array $config, int $daemonize = 0): static
    {
        $this->server->set([
            ...$config,
            'daemonize' => (int) $daemonize,
            'enable_coroutine' => true // must be set on false for Runtime::enableCoroutine
        ]);

        return $this;
    }

    public function getServerInstance(): Server
    {
        return $this->server;
    }
}