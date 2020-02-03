<?php
require __DIR__ . '/../vendor/autoload.php';

use Bobby\Coroutine\CoroutineScheduler;

$scheduler = new CoroutineScheduler();
$fn1 = function () {
    echo "this is fn1\n";
    yield;
};

$fn2 = function () use($fn1) {
    yield $fn1();
    echo "this is fn2\n";
};

$fn3 = function () {
    echo "this is fn3\n";
    yield;
};

$fn4 = function () use ($fn3) {
    yield $fn3();
    echo "this is fn4\n";
    yield;
};

$scheduler->newCoroutine($fn2());
$scheduler->newCoroutine($fn4());
$scheduler->run();

