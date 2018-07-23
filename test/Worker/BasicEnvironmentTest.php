<?php

namespace Amp\Parallel\Test\Worker;

use Amp\Parallel\Worker\BasicEnvironment;
use Amp\PHPUnit\TestCase;
use function Amp\delay;

class BasicEnvironmentTest extends TestCase
{
    public function testBasicOperations(): void
    {
        $environment = new BasicEnvironment;
        $key = "key";

        $this->assertFalse($environment->exists($key));
        $this->assertNull($environment->get($key));

        $environment->set($key, 1);
        $this->assertTrue($environment->exists($key));
        $this->assertSame(1, $environment->get($key));

        $environment->set($key, 2);
        $this->assertSame(2, $environment->get($key));

        $environment->delete($key);
        $this->assertFalse($environment->exists($key));
        $this->assertNull($environment->get($key));
    }

    public function testArrayAccess(): void
    {
        $environment = new BasicEnvironment;
        $key = "key";

        $this->assertArrayNotHasKey($key, $environment);
        $this->assertNull($environment[$key]);

        $environment[$key] = 1;
        $this->assertArrayHasKey($key, $environment);
        $this->assertSame(1, $environment[$key]);

        $environment[$key] = 2;
        $this->assertSame(2, $environment[$key]);

        unset($environment[$key]);
        $this->assertArrayNotHasKey($key, $environment);
        $this->assertNull($environment[$key]);
    }

    public function testClear(): void
    {
        $environment = new BasicEnvironment;

        $environment->set("key1", 1);
        $environment->set("key2", 2);

        $environment->clear();

        $this->assertFalse($environment->exists("key1"));
        $this->assertFalse($environment->exists("key2"));
    }

    public function testTtl(): void
    {
        $environment = new BasicEnvironment;
        $key = "key";

        $environment->set($key, 1, 2);

        delay(3000);

        $this->assertFalse($environment->exists($key));
    }

    /**
     * @depends testTtl
     */
    public function testRemovingTtl(): void
    {
        $environment = new BasicEnvironment;
        $key = "key";

        $environment->set($key, 1, 1);

        $environment->set($key, 2);

        delay(2000);

        $this->assertTrue($environment->exists($key));
        $this->assertSame(2, $environment->get($key));
    }

    public function testShorteningTtl(): void
    {
        $environment = new BasicEnvironment;
        $key = "key";

        $environment->set($key, 1, 10);
        $environment->set($key, 1, 1);

        delay(2000);

        $this->assertFalse($environment->exists($key));
    }

    public function testLengtheningTtl(): void
    {
        $environment = new BasicEnvironment;
        $key = "key";

        $environment->set($key, 1, 1);
        $environment->set($key, 1, 3);

        delay(2000);

        $this->assertTrue($environment->exists($key));

        delay(1100);

        $this->assertFalse($environment->exists($key));
    }

    public function testAccessExtendsTtl(): void
    {
        $environment = new BasicEnvironment;
        $key1 = "key1";
        $key2 = "key2";

        $environment->set($key1, 1, 2);
        $environment->set($key2, 2, 2);

        delay(1000);

        $this->assertSame(1, $environment->get($key1));
        $this->assertTrue($environment->exists($key2));

        delay(1500);

        $this->assertTrue($environment->exists($key1));
        $this->assertFalse($environment->exists($key2));
    }
}
