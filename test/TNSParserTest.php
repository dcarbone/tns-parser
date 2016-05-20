<?php
use DCarbone\TNSParser;

class TNSParserTest extends \PHPUnit_Framework_TestCase
{

    public function __construct()
    {
        $this->sampleFile = __DIR__ . '/fixtures/sampletnsnames.ora';
        $this->parser = new TNSParser();
    }

    public function testCanParseIndividualEntries()
    {

        $this->parser->parseFile($this->sampleFile);

        $data = $this->parser->jsonSerialize();

        $scott = $this->parser->getTNSEntryString('SCOTT');
        $tiger = $this->parser->getTNSEntryString('TIGER');
        $this->assertContains('scottcloud.aws.cloud03.local', $scott);
        $this->assertContains('tigercloud.aws.cloud02.local', $tiger);
    }
}
