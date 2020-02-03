<?php
namespace Bobby\Coroutine;

use SplQueue;
use Generator;

class CoroutineScheduler
{
    protected $coroutineQueue;

    protected $maxCoroutineId = 0;

    public function __construct()
    {
        $this->coroutineQueue = new SplQueue();
    }

    public function newCoroutine(Generator $generator)
    {
        $coroutine = new Coroutine(++$this->maxCoroutineId, $generator);
        return $this->schedule($coroutine);
    }

    public function schedule(Coroutine $coroutine)
    {
        $this->coroutineQueue->enqueue($coroutine);
        return $coroutine->getCoroutineId();
    }

    public function isEmpty(): bool
    {
        return $this->coroutineQueue->isEmpty();
    }

    public function run()
    {
        while (!$this->isEmpty()) {
            $coroutine = $this->coroutineQueue->dequeue();
            $value = $coroutine->run();

            if ($value instanceof CoroutineInterrupter) {
                $value($this, $coroutine);
                continue;
            }

            if (!$coroutine->isFinished()) {
                $this->schedule($coroutine);
            }
        }
    }
}