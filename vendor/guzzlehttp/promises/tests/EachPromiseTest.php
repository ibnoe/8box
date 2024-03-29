<?php
namespace GuzzleHttp\Promise\Tests;

use GuzzleHttp\Promise\RejectedPromise;
use GuzzleHttp\Promise\FulfilledPromise;
use GuzzleHttp\Promise\Promise;
use GuzzleHttp\Promise\PromiseInterface;
use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Promise as P;

/**
 * @covers GuzzleHttp\Promise\EachPromise
 */
class EachPromiseTest extends \PHPUnit_Framework_TestCase
{
    public function testReturnsSameInstance()
    {
        $each = new EachPromise([], ['concurrency' => 100]);
        $this->assertSame($each->promise(), $each->promise());
    }

    public function testInvokesAllPromises()
    {
        $promises = [new Promise(), new Promise(), new Promise()];
        $called = [];
        $each = new EachPromise(
            $promises, [
            'fulfilled' => function ($value) use (&$called) {
                $called[] = $value;
            },
        ]
        );
        $p = $each->promise();
        $promises[0]->resolve('a');
        $promises[1]->resolve('c');
        $promises[2]->resolve('b');
        P\queue()->run();
        $this->assertEquals(['a', 'c', 'b'], $called);
        $this->assertEquals(PromiseInterface::FULFILLED, $p->getState());
    }

    public function testIsWaitable()
    {
        $a = new Promise(
            function () use (&$a) {
                $a->resolve('a');
            }
        );
        $b = new Promise(
            function () use (&$b) {
                $b->resolve('b');
            }
        );
        $called = [];
        $each = new EachPromise(
            [$a, $b], [
            'fulfilled' => function ($value) use (&$called) {
                $called[] = $value;
            },
        ]
        );
        $p = $each->promise();
        $this->assertNull($p->wait());
        $this->assertEquals(PromiseInterface::FULFILLED, $p->getState());
        $this->assertEquals(['a', 'b'], $called);
    }

    public function testCanResolveBeforeConsumingAll()
    {
        $called = 0;
        $a = new Promise(
            function () use (&$a) {
                $a->resolve('a');
            }
        );
        $b = new Promise(
            function () {
                $this->fail();
            }
        );
        $each = new EachPromise(
            [$a, $b], [
            'fulfilled' => function ($value, $idx, Promise $aggregate) use (&$called) {
                $this->assertSame($idx, 0);
                $this->assertEquals('a', $value);
                $aggregate->resolve(null);
                $called++;
            },
            'rejected' => function (\Exception $reason) {
                $this->fail($reason->getMessage());
            },
        ]
        );
        $p = $each->promise();
        $p->wait();
        $this->assertNull($p->wait());
        $this->assertEquals(1, $called);
        $this->assertEquals(PromiseInterface::FULFILLED, $a->getState());
        $this->assertEquals(PromiseInterface::PENDING, $b->getState());
        // Resolving $b has no effect on the aggregate promise.
        $b->resolve('foo');
        $this->assertEquals(1, $called);
    }

    public function testLimitsPendingPromises()
    {
        $pending = [new Promise(), new Promise(), new Promise(), new Promise()];
        $promises = new \ArrayIterator($pending);
        $each = new EachPromise($promises, ['concurrency' => 2]);
        $p = $each->promise();
        $this->assertCount(2, $this->readAttribute($each, 'pending'));
        $pending[0]->resolve('a');
        $this->assertCount(2, $this->readAttribute($each, 'pending'));
        $this->assertTrue($promises->valid());
        $pending[1]->resolve('b');
        P\queue()->run();
        $this->assertCount(2, $this->readAttribute($each, 'pending'));
        $this->assertTrue($promises->valid());
        $promises[2]->resolve('c');
        P\queue()->run();
        $this->assertCount(1, $this->readAttribute($each, 'pending'));
        $this->assertEquals(PromiseInterface::PENDING, $p->getState());
        $promises[3]->resolve('d');
        P\queue()->run();
        $this->assertNull($this->readAttribute($each, 'pending'));
        $this->assertEquals(PromiseInterface::FULFILLED, $p->getState());
        $this->assertFalse($promises->valid());
    }

    public function testDynamicallyLimitsPendingPromises()
    {
        $calls = [];
        $pendingFn = function ($count) use (&$calls) {
            $calls[] = $count;

            return 2;
        };
        $pending = [new Promise(), new Promise(), new Promise(), new Promise()];
        $promises = new \ArrayIterator($pending);
        $each = new EachPromise($promises, ['concurrency' => $pendingFn]);
        $p = $each->promise();
        $this->assertCount(2, $this->readAttribute($each, 'pending'));
        $pending[0]->resolve('a');
        $this->assertCount(2, $this->readAttribute($each, 'pending'));
        $this->assertTrue($promises->valid());
        $pending[1]->resolve('b');
        $this->assertCount(2, $this->readAttribute($each, 'pending'));
        P\queue()->run();
        $this->assertTrue($promises->valid());
        $promises[2]->resolve('c');
        P\queue()->run();
        $this->assertCount(1, $this->readAttribute($each, 'pending'));
        $this->assertEquals(PromiseInterface::PENDING, $p->getState());
        $promises[3]->resolve('d');
        P\queue()->run();
        $this->assertNull($this->readAttribute($each, 'pending'));
        $this->assertEquals(PromiseInterface::FULFILLED, $p->getState());
        $this->assertEquals([0, 1, 1, 1], $calls);
        $this->assertFalse($promises->valid());
    }

    public function testClearsReferencesWhenResolved()
    {
        $called = false;
        $a = new Promise(
            function () use (&$a, &$called) {
                $a->resolve('a');
                $called = true;
            }
        );
        $each = new EachPromise(
            [$a], [
            'concurrency' => function () {
                return 1;
            },
            'fulfilled' => function () {
            },
            'rejected' => function () {
            },
        ]
        );
        $each->promise()->wait();
        $this->assertNull($this->readAttribute($each, 'onFulfilled'));
        $this->assertNull($this->readAttribute($each, 'onRejected'));
        $this->assertNull($this->readAttribute($each, 'iterable'));
        $this->assertNull($this->readAttribute($each, 'pending'));
        $this->assertNull($this->readAttribute($each, 'concurrency'));
        $this->assertTrue($called);
    }

    public function testCanBeCancelled()
    {
        $this->markTestIncomplete();
    }

    public function testDoesNotBlowStackWithFulfilledPromises()
    {
        $pending = [];
        for ($i = 0; $i < 100; $i++) {
            $pending[] = new FulfilledPromise($i);
        }
        $values = [];
        $each = new EachPromise(
            $pending, [
            'fulfilled' => function ($value) use (&$values) {
                $values[] = $value;
            },
        ]
        );
        $called = false;
        $each->promise()->then(
            function () use (&$called) {
                $called = true;
            }
        );
        $this->assertFalse($called);
        P\queue()->run();
        $this->assertTrue($called);
        $this->assertEquals(range(0, 99), $values);
    }

    public function testDoesNotBlowStackWithRejectedPromises()
    {
        $pending = [];
        for ($i = 0; $i < 100; $i++) {
            $pending[] = new RejectedPromise($i);
        }
        $values = [];
        $each = new EachPromise(
            $pending, [
            'rejected' => function ($value) use (&$values) {
                $values[] = $value;
            },
        ]
        );
        $called = false;
        $each->promise()->then(
            function () use (&$called) {
                $called = true;
            },
            function () {
                $this->fail('Should not have rejected.');
            }
        );
        $this->assertFalse($called);
        P\queue()->run();
        $this->assertTrue($called);
        $this->assertEquals(range(0, 99), $values);
    }

    public function testReturnsPromiseForWhatever()
    {
        $called = [];
        $arr = ['a', 'b'];
        $each = new EachPromise(
            $arr, [
            'fulfilled' => function ($v) use (&$called) {
                $called[] = $v;
            },
        ]
        );
        $p = $each->promise();
        $this->assertNull($p->wait());
        $this->assertEquals(['a', 'b'], $called);
    }

    public function testRejectsAggregateWhenNextThrows()
    {
        $iter = function () {
            yield 'a';
            throw new \Exception('Failure');
        };
        $each = new EachPromise($iter());
        $p = $each->promise();
        $e = null;
        $received = null;
        $p->then(
            null,
            function ($reason) use (&$e) {
                $e = $reason;
            }
        );
        P\queue()->run();
        $this->assertInstanceOf('Exception', $e);
        $this->assertEquals('Failure', $e->getMessage());
    }

    public function testDoesNotCallNextOnIteratorUntilNeededWhenWaiting()
    {
        $results = [];
        $values = [10];
        $remaining = 9;
        $iter = function () use (&$values) {
            while ($value = array_pop($values)) {
                yield $value;
            }
        };
        $each = new EachPromise(
            $iter(), [
            'concurrency' => 1,
            'fulfilled' => function ($r) use (&$results, &$values, &$remaining) {
                $results[] = $r;
                if ($remaining > 0) {
                    $values[] = $remaining--;
                }
            },
        ]
        );
        $each->promise()->wait();
        $this->assertEquals(range(10, 1), $results);
    }

    public function testDoesNotCallNextOnIteratorUntilNeededWhenAsync()
    {
        $firstPromise = new Promise();
        $pending = [$firstPromise];
        $values = [$firstPromise];
        $results = [];
        $remaining = 9;
        $iter = function () use (&$values) {
            while ($value = array_pop($values)) {
                yield $value;
            }
        };
        $each = new EachPromise(
            $iter(), [
            'concurrency' => 1,
            'fulfilled' => function ($r) use (&$results, &$values, &$remaining, &$pending) {
                $results[] = $r;
                if ($remaining-- > 0) {
                    $pending[] = $values[] = new Promise();
                }
            },
        ]
        );
        $i = 0;
        $each->promise();
        while ($promise = array_pop($pending)) {
            $promise->resolve($i++);
            P\queue()->run();
        }
        $this->assertEquals(range(0, 9), $results);
    }
}
