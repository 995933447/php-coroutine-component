<?php
namespace Bobby\Coroutine;

class CoroutineInterrupter
{
    protected $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function __invoke(CoroutineScheduler $scheduler, Coroutine $coroutine)
    {
        $callback = $this->callback;
        return $callback($scheduler, $coroutine);
    }
}