<?php

namespace Tests\Support;

use App\UrlChecker;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use RuntimeException;
use Throwable;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function test(string $name, callable $callback): void
{
    global $passed, $failed;

    try {
        $callback();
        $passed++;
        echo "[OK] $name\n";
    } catch (Throwable $exception) {
        $failed++;
        echo "[FAIL] $name: {$exception->getMessage()}\n";
    }
}

function checkerFor(Response|Throwable $result): UrlChecker
{
    $handler = HandlerStack::create(new MockHandler([$result]));

    return new UrlChecker(new Client(['handler' => $handler]));
}
