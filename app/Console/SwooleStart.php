<?php

namespace App\Console;

use Illuminate\Console\Command;
use OpenSwoole\Table;
use OpenSwoole\WebSocket\Frame;
use OpenSwoole\WebSocket\Server;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;

class SwooleStart extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'swoole:start';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Swoole';

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        $server = new Server('training_appserver_1', 9502);
        $fds = new Table(1024);
        $fds->column('fd', Table::TYPE_INT, 4);
        $fds->column('name', Table::TYPE_STRING, 16);
        $fds->column('channel', Table::TYPE_STRING, 16);
        $fds->create();
        $server->on("start", function(Server $server)
        {
            $this->info("OpenSwoole WebSocket Server is started at http://127.0.0.1:9502");
        });

        $server->on('open', function(Server $server, Request $request) use ($fds)
        {
            $fd = $request->fd;
            $clientName = sprintf("Client-%'.06d\n", $request->fd);
            $fds->set($request->fd, [
                'fd' => $fd,
                'name' => sprintf($clientName),
                'channel' => 'public'
            ]);
            $this->info("connection open: {$request->fd}");
            foreach ($fds as $key => $value) {
                if ($key == $fd) {
                    $server->push($request->fd, "Welcome {$clientName}, there are " . $fds->count() . " connections");
                } else {
                    $server->push($key, "A new client ({$clientName}) is joining to the party");
                }
            }
        });

        $server->on('message', function(Server $server, Frame $frame) use ($fds)
        {
            if ($frame->data != 'ping') {
                $frame->data = json_decode($frame->data);
                if ($frame->data->status == 'join') {
                    $fds->set($frame->fd, [
                        'fd' => $fds->get($frame->fd, 'fd'),
                        'name' => $fds->get($frame->fd, 'name'),
                        'channel' => $frame->data->channel
                    ]);
                    $server->push($frame->fd, "Joined {$frame->data->channel}");
                } else {
                    foreach ($fds as $key => $value) {
                        if ($fds->get($key, 'channel') == $frame->data->channel) {
                            $server->push($key, $frame->data->message);
                        }
                    }
                }
            } else {
                $server->push($frame->fd, "Pong");
            }
        });

        $server->on('request', function(Request $request, Response $response) use ($fds)
        {
            $params = [];
            if (isset($request->server['query_string'])) {
                parse_str($request->server['query_string'], $params);
            }
        
            if (isset($params['name'])) {
                $user = $params['name'];
            }
            
            $response->end();
        });

        $server->on('close', function(Server $server, int $fd) use ($fds)
        {
            $fds->del($fd);
            $this->info("connection close: {$fd}");
        });

        $server->on('disconnect', function(Server $server, int $fd)
        {
            $this->info("connection disconnect: {$fd}");
        });

        $server->start();
    }
}