<?php
require __DIR__ . '/../vendor/autoload.php';

use Bobby\Coroutine\Coroutine;
use Bobby\Coroutine\CoroutineScheduler;
use Bobby\Coroutine\CoroutineInterrupter;
use Bobby\Coroutine\CoroutineReturn;

class CoroutineServer
{
    protected $reads = [];

    protected $writes = [];

    protected $socketManager;

    public function __construct(CoroutineSocket $socketManager)
    {
        $this->socketManager = $socketManager;
        $this->socketManager->bindServer($this);
    }

    public function registerReadEvent($socket)
    {
        return new CoroutineInterrupter(function (CoroutineScheduler $scheduler, Coroutine $coroutine) use($socket) {
            if (!isset($this->reads[(int)$socket]))
                $this->reads[(int)$socket] = [$socket, [$coroutine]];
            else
                $this->reads[(int)$socket][1][] = $coroutine;
        });
    }

    public function registerWriteEvent($socket)
    {
        return new CoroutineInterrupter(function (CoroutineScheduler $scheduler, Coroutine $coroutine) use ($socket) {
            if (!isset($this->reads[(int)$socket]))
                $this->writes[(int)$socket] = [$socket, [$coroutine]];
            else
                $this->writes[(int)$socket][1][] = $coroutine;
        });
    }

    public function poll(CoroutineScheduler $scheduler)
    {
        var_dump($this->reads);
        if (empty($this->reads) && empty($this->writes)) {
            return $scheduler->newCoroutine($this->poll($scheduler));
        }

        $waitForReads = [];
        foreach ($this->reads as list($socket)) {
            $waitForReads[] = $socket;
        }

        $waitForWrites = [];
        foreach ($this->writes as list($socket)) {
            $waitForWrites[] = $socket;
        }

        if (!stream_select($waitForReads, $waitForWrites, $exceptions, $scheduler->isEmpty()? null: 0)) {
            return $scheduler->newCoroutine($this->poll($scheduler));
        }

        $rwCoroutines = [];
        foreach ($waitForReads as $read) {
            list(, $readCoroutines) = $this->reads[(int)$read];
            unset($this->reads[(int)$read]);
            $rwCoroutines = array_merge($rwCoroutines, $readCoroutines);
        }

        foreach ($waitForWrites as $write) {
            list(, $writeCoroutines) = $this->writes[(int)$write];
            unset($this->writes[(int)$write]);
            $rwCoroutines = array_merge($rwCoroutines, $writeCoroutines);
        }

        foreach ($rwCoroutines as $rwCoroutine) {
            $scheduler->schedule($rwCoroutine);
        }

        yield $scheduler->newCoroutine($this->poll($scheduler));
    }
}


class CoroutineSocket
{
    protected $socket;

    protected $server;

    public function __construct($socket)
    {
        $this->socket = $socket;
    }

    public function bindServer(CoroutineServer $server)
    {
        $this->server = $server;
    }

    public function accept()
    {
        yield $this->server->registerReadEvent($this->socket);
        $readSocket = new CoroutineSocket(stream_socket_accept($this->socket));
        $readSocket->bindServer($this->server);
        yield new CoroutineReturn($readSocket);
    }

    public function read()
    {
        stream_set_blocking($this->socket, 0);
        yield $this->server->registerReadEvent($this->socket);
        yield new CoroutineReturn(stream_get_contents($this->socket));
    }

    public function write(string $message)
    {
        yield $this->server->registerWriteEvent($this->socket);
        yield fwrite($this->socket, $message);
    }

    public function close()
    {
        yield fclose($this->socket);
    }
}

class HttpServer extends CoroutineServer
{
    public function run()
    {
        yield new CoroutineInterrupter(function (CoroutineScheduler $scheduler, Coroutine $coroutine) {
            $scheduler->newCoroutine($this->requestHandle($scheduler));
            $scheduler->newCoroutine($this->poll($scheduler));
        });
    }

    public function requestHandle(CoroutineScheduler $scheduler)
    {
        while (1) {
            yield $this->response(yield $this->socketManager->accept());
        }
    }

    protected function response(CoroutineSocket $socket)
    {
        $data = yield $socket->read();
        $content = "Received following request:\n\n$data";
        $response = <<<STR
HTTP/1.1 200 OK\r
Content-Type: text/html\r
Connection: close\r
\r
$content      
STR;
        yield $socket->write($response);
        yield $socket->close();
    }
}

if (!$socket = stream_socket_server("tcp://0.0.0.0:80", $errno, $errstr)) {
    throw new \Exception($errstr, $errno);
}

$socket = new CoroutineSocket($socket);
($scheduler = new CoroutineScheduler())->newCoroutine((new HttpServer($socket))->run());
$scheduler->run();