<?php

use Ody\Scheduler\Crontab;
use Ody\Scheduler\Tests\Jobs\JobPerMin;
use Swoole\Server;

require_once 'vendor/autoload.php';

$http = new Server("127.0.0.1", 9503);
$crontab = new Crontab();
$crontab->register(new JobPerMin());
$crontab->attachToServer($http);
$http->on('receive', function (Server $server, $fd, $from_id, $data) use ($crontab) {

    $ret = $crontab->rightNow('JobPerMin');
    $server->send($fd, 'Swoole: '.$data);
    $server->close($fd);

    var_dump($data);
});

$http->start();