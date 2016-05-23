<?php
use DCarbone\TNSParser;

class TNSParserTest extends \PHPUnit_Framework_TestCase
{

    public function setUp()
    {
        $this->sampleFile = __DIR__ . '/fixtures/sampletnsnames.ora';
        $this->parser = new TNSParser();
        $this->parser->parseFile($this->sampleFile);
    }

    public function testCanParseIndividualEntries()
    {
        $scott = $this->parser->getTNSEntryString('SCOTT');
        $tiger = $this->parser->getTNSEntryString('TIGER');

        $this->assertContains('scottcloud.aws.cloud03.local', $scott);
        $this->assertContains('tigercloud.aws.cloud02.local', $tiger);
    }

    public function testSerializationFormat()
    {
        $data = $this->parser->jsonSerialize();

        // Array uses aliases as root keys
        $this->assertEquals(array_keys($data), [ 'SCOTT', 'TIGER' ]);

        // dig into it and check for some deeply nested data
        $this->assertEquals($data['SCOTT']['DESCRIPTION']['ADDRESS_LIST']['ADDRESS']['PORT'], "1521");

        // should only be two entries
        $this->assertEquals(count($data), 2);
    }



    public function testInvalidEntry()
    {
        $badEntry  = $this->parser->getTNSEntryString('THISDOESNTEXIST');
        $this->assertNull($badEntry);
    }

    public function testSearch()
    {
        $entries = $this->parser->search('cloud03');
        $this->assertEquals($entries, ['SCOTT']);

        // check that full TNS can be resolved from $parser
        $fullTNS = $this->parser[$entries[0]];
        $this->assertEquals($fullTNS['DESCRIPTION']['ADDRESS_LIST']['ADDRESS']['PROTOCOL'], 'TCP');
    }
}
