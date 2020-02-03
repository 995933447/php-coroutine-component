<?php
namespace Bobby\Coroutine;

use Generator;
use SplStack;

class Coroutine
{
    protected $coroutineId;

    protected $generator;

    protected $sendValue;

    protected $isFirstRun = true;

    public function __construct(int $coroutineId, Generator $generator)
    {
        $this->coroutineId = $coroutineId;
        $this->generator = $this->toStackable($generator);
    }

    protected function toStackable(Generator $generator)
    {
        $stack = new SplStack();

        while (1) {
            $value = $generator->current();

            if ($value instanceof Generator) {
                $stack->push($generator);
                $generator = $value;     
                continue;
            }

            if ($value instanceof CoroutineReturn) {
                if ($stack->isEmpty()) {
                    return $value;
                }

                $generator = $stack->pop();
                $generator->send($value->getValue());
                continue;
            }

            yield $value;

            $generator->next();

            if (!$generator->valid()) {
                if ($stack->isEmpty()) {
                    return;
                }
                $generator = $stack->pop();
                $generator->next();
            }
        }
    }

    public function setSendValue($value)
    {
        $this->sendValue = $value;
    }

    public function getCoroutineId(): int
    {
        return $this->coroutineId;
    }

    public function isFinished(): bool
    {
        return !$this->generator->valid();
    }

    public function run()
    {
        if ($this->isFirstRun) {
            $result = $this->generator->current();
            $this->isFirstRun = false;
        } else {
            $result = $this->generator->send($this->sendValue);
            $this->sendValue = null;
        }
        return $result;
    }
}