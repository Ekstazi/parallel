<?php

namespace Amp\Parallel\Test\Sync;

use Amp\Parallel\Sync\ChannelParser;
use Amp\PHPUnit\TestCase;

class ChannelParserTest extends TestCase
{
    /**
     * @expectedException \Amp\Parallel\Sync\SerializationException
     * @expectedExceptionMessage Exception thrown when unserializing data
     */
    public function testCorruptedData(): void
    {
        $data = "Invalid serialized data";
        $data = \pack("CL", 0, \strlen($data)) . $data;
        $parser = new ChannelParser($this->createCallback(0));
        $parser->push($data);
    }

    /**
     * @expectedException \Amp\Parallel\Sync\ChannelException
     * @expectedExceptionMessage Invalid packet received: Invalid packet
     */
    public function testInvalidHeaderData(): void
    {
        $data = "Invalid packet";
        $parser = new ChannelParser($this->createCallback(0));
        $parser->push($data);
    }

    /**
     * @expectedException \Amp\Parallel\Sync\ChannelException
     * @expectedExceptionMessage Invalid packet received: B \xf3\xf2\x0\x1
     */
    public function testInvalidHeaderBinaryData(): void
    {
        $data = "\x42\x20\xf3\xf2\x00\x01";
        $parser = new ChannelParser($this->createCallback(0));
        $parser->push($data);
    }
}
