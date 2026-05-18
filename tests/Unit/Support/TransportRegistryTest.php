<?php

namespace Admnio\Sunset\Tests\Unit\Support;

use Admnio\Sunset\Contracts\Transport;
use Admnio\Sunset\Exceptions\InvalidConfigurationException;
use Admnio\Sunset\Support\TransportRegistry;
use Admnio\Sunset\Tests\TestCase;
use Mockery;

class TransportRegistryTest extends TestCase
{
    public function test_register_and_lookup(): void
    {
        $registry = new TransportRegistry();
        $fake = $this->fakeTransport('sqs');

        $registry->register($fake);

        $this->assertSame($fake, $registry->get('sqs'));
    }

    public function test_get_throws_for_unknown_name(): void
    {
        $registry = new TransportRegistry();

        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/no transport registered/i');

        $registry->get('redis');
    }

    public function test_register_overwrites_existing(): void
    {
        $registry = new TransportRegistry();
        $first = $this->fakeTransport('sqs');
        $second = $this->fakeTransport('sqs');

        $registry->register($first);
        $registry->register($second);

        $this->assertSame($second, $registry->get('sqs'));
    }

    public function test_names_returns_registered_keys(): void
    {
        $registry = new TransportRegistry();
        $registry->register($this->fakeTransport('sqs'));
        $registry->register($this->fakeTransport('redis'));

        $names = $registry->names();
        sort($names);

        $this->assertSame(['redis', 'sqs'], $names);
    }

    private function fakeTransport(string $name): Transport
    {
        $transport = Mockery::mock(Transport::class);
        $transport->shouldReceive('name')->andReturn($name);
        return $transport;
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
