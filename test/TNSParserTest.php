<?php

use DCarbone\TNSParser;

class TNSParserTest extends \PHPUnit\Framework\TestCase {
    /** @var int */
    private static $totalNumEntries = 2;

    /** @var \DCarbone\TNSParser */
    private $parser;
    /** @var string */
    private $sampleFile;

    public function setUp() {
        $this->sampleFile = __DIR__ . '/fixtures/sampletnsnames.ora';
        $this->parser = new TNSParser();
        $this->parser->parseFile($this->sampleFile);
    }

    public function testCanParseIndividualEntries() {
        $scott = $this->parser->getTNSEntryString('SCOTT');
        $tiger = $this->parser->getTNSEntryString('TIGER');

        $this->assertContains('scottcloud.aws.cloud03.local', $scott);
        $this->assertContains('tigercloud.aws.cloud02.local', $tiger);
    }

    public function testJSONSerialization() {
        $data = $this->parser->jsonSerialize();

        // Array uses aliases as root keys
        $this->assertEquals(array_keys($data), ['SCOTT', 'TIGER']);

        // dig into it and check for some deeply nested data
        $this->assertEquals($data['SCOTT']['DESCRIPTION']['ADDRESS_LIST']['ADDRESS']['PORT'], "1521");

        // should only be two entries
        $this->assertEquals(count($data), self::$totalNumEntries);
    }

    public function testSerialization() {
        $expectedSerialization =
            'C:18:"DCarbone\TNSParser":884:{a:3:{i:0;a:2:{s:5:"SCOTT";a:1:{s:11:"DESCRIPTION";a:2:{s:12:"ADDRESS_LIST";a:3:{s:12:"LOAD_BALANCE";s:3:"off";s:8:"FAILOVER";s:2:"ON";s:7:"ADDRESS";a:3:{s:8:"PROTOCOL";s:3:"TCP";s:4:"HOST";s:28:"scottcloud.aws.cloud03.local";s:4:"PORT";s:4:"1521";}}s:12:"CONNECT_DATA";a:1:{s:12:"service_name";s:5:"SCOTT";}}}s:5:"TIGER";a:1:{s:11:"DESCRIPTION";a:2:{s:12:"ADDRESS_LIST";a:3:{s:12:"LOAD_BALANCE";s:3:"off";s:8:"FAILOVER";s:2:"ON";s:7:"ADDRESS";a:3:{s:8:"PROTOCOL";s:3:"TCP";s:4:"HOST";s:28:"tigercloud.aws.cloud02.local";s:4:"PORT";s:4:"1521";}}s:12:"CONNECT_DATA";a:1:{s:12:"service_name";s:5:"TIGER";}}}}i:1;a:2:{s:5:"SCOTT";a:6:{i:0;s:3:"off";i:1;s:2:"ON";i:2;s:3:"TCP";i:3;s:28:"scottcloud.aws.cloud03.local";i:4;s:4:"1521";i:5;s:5:"SCOTT";}s:5:"TIGER";a:6:{i:0;s:3:"off";i:1;s:2:"ON";i:2;s:3:"TCP";i:3;s:28:"tigercloud.aws.cloud02.local";i:4;s:4:"1521";i:5;s:5:"TIGER";}}i:2;b:0;}}';
        $actualSerialization = serialize($this->parser);
        $this->assertEquals($expectedSerialization, $actualSerialization);

        $reconstructed = unserialize($expectedSerialization);
        $this->_conductSearchTest($reconstructed);
    }

    private function _conductSearchTest($root) {
        $entries = $root->search('cloud03');
        $this->assertEquals($entries, ['SCOTT']);

        // check that full TNS can be resolved from $parser
        $fullTNS = $root[$entries[0]];
        $this->assertEquals($fullTNS['DESCRIPTION']['ADDRESS_LIST']['ADDRESS']['PROTOCOL'], 'TCP');
    }

    public function testInvalidEntry() {
        $badEntry = $this->parser->getTNSEntryString('THISDOESNTEXIST');
        $this->assertNull($badEntry);
    }

    public function testSearch() {
        $this->_conductSearchTest($this->parser);
    }

    public function testCountable() {
        $this->assertEquals(count($this->parser), self::$totalNumEntries);
    }

    public function testArrayAccess() {
        $entry = $this->parser['SCOTT'];
        $this->assertEquals($entry['DESCRIPTION']['CONNECT_DATA']['service_name'], 'SCOTT');

        $badKey = 'THISDOESNTEXIST';
        try {
            $invalidEntry = $this->parser[$badKey];
        } catch (\OutOfRangeException $e) {
            $this->assertEquals("No key ${badKey} exists", $e->getMessage());
        }

        try {
            unset($this->parser['SCOTT']);
        } catch (\BadMethodCallException $e) {
            $this->assertEquals('Not allowed to unset values on this object', $e->getMessage());
        }

        try {
            $this->parser['SCOTT'] = 'Testing override ability';
        } catch (\BadMethodCallException $e) {
            $this->assertEquals('Not allowed to set values on this object', $e->getMessage());
        }

        $this->assertTrue(isset($this->parser['SCOTT']));
        $this->assertFalse(isset($this->parser['JOHN']));
    }

    public function testIteration() {
        $total = 0;
        $entries = [];
        $keys = [];
        foreach ($this->parser as $key => $entry) {
            ++$total;
            $entries [] = $entry;
            $keys [] = $key;
        }
        $this->assertEquals($keys, ['SCOTT', 'TIGER']);
        $this->assertEquals($total, self::$totalNumEntries);
    }
}
