<?php
// 把本程序模拟成操作系统.使用yield关键字实现的协程模拟单CPU下的多进程

class Task
{
    protected $generator;

    protected $taskId;

    protected $isFirstRun = true;

    protected $sendValue;

    public function __construct(int $id, Generator $generator)
    {
        $this->generator = $generator;
        $this->taskId = $id;
    }

    public function getTaskId()
    {
        return $this->taskId;
    }

    public function setSendValue($value)
    {
        $this->sendValue = $value;
    }

    public function isFinished(): bool
    {
        return !$this->generator->valid();
    }

    public function run()
    {
        if ($this->isFirstRun) {
            $result = $this->isFirstRun = false;
            return $this->generator->current();
        } else {
            $result = $this->generator->send($this->sendValue);
            $this->sendValue = null;
        }
        return $result;
    }
}

class SystemCall
{
    protected $callback;

    public function __construct(callable $callback)
    {
        $this->callback = $callback;
    }

    public function run(Task $task, Scheduler $scheduler)
    {
        return call_user_func_array($this->callback, [$task, $scheduler]);
    }
}

class Scheduler
{
    protected $taskQueue;

    protected $maxTaskId = 0;

    public function __construct()
    {
        $this->taskQueue = new \SplQueue();
    }

    public function addTask(Generator $generator)
    {
        $newTask = new Task(++$this->maxTaskId, $generator);
        $this->schedule($newTask);
        return $newTask->getTaskId();
    }

    public function schedule(Task $task)
    {
        $this->taskQueue->enqueue($task);
    }

    public function popTask(): Task
    {
        return $this->taskQueue->dequeue();
    }

    public function removeTask(int $taskId) 
    {
        foreach ($this->taskQueue as $index => $task) {
            if ($task->getTaskId() === $taskId) {
                unset($this->taskQueue[$index]);
                break;
            }
        }
    }

    public function run()
    {
        while(!$this->taskQueue->isEmpty()) {
            $result = ($task = $this->popTask())->run();
            if ($result instanceof SystemCall) {
                $result->run($task, $this);
                $task->setSendValue($result->run($task, $this));
                $this->schedule($task);
            } else if (!$task->isFinished()) {
                $this->schedule($task); 
            }
        }
    }
}

$task = function () {
    yield new SystemCall(function (Task $task, Scheduler $scheduler) {
        $scheduler->schedule($task);
    });
};

$task1 = function () {
    for ($i = 1; $i <= 10; $i++) {
        echo "Task 1 run $i." , PHP_EOL;
        yield;
    }
};

$task2 = function () {
    for ($i = 1; $i <= 5; $i++) {
        echo "Task 2 run $i.", PHP_EOL;
        yield;
    }
};

($scheduler = (new Scheduler()))->addTask($task1());
$scheduler->addTask($task2());

$getTaskId = function () {
    return new SystemCall(function (Task $task, Scheduler $scheduler) {
        return $task->getTaskId();
    });
};

$task3 = function () use ($getTaskId) {
    $taskId = yield $getTaskId();
    echo "Task 3 's task id is $taskId." . PHP_EOL;
};

$scheduler->addTask($task3());

$addTask = function (Generator $generator) {
    return new systemCall(function (Task $task, Scheduler $scheduler) use ($generator) {
        return $scheduler->addTask($generator);
    });
};

$killTask = function (int $taskId) use($scheduler) {
    $scheduler->removeTask($taskId);
};

$childTask = function () use ($getTaskId) {
    $taskId = yield $getTaskId();
    for ($i = 0; $i < 10; $i++) {
        echo "Child task $taskId alive $i." . PHP_EOL;
        yield;
    }
};

$task4 = function () use ($getTaskId, $addTask, $childTask, $killTask) {
    $taskId = yield $getTaskId();
    $childTaskId = yield $addTask($childTask());
    for ($i = 0; $i < 6; $i++) {
        echo "Master $taskId alive $i." . PHP_EOL;
        if ($i == 3) {
            $killTask($childTaskId);
        }
        yield;
    }
};

$scheduler->addTask($task4());
$scheduler->run();