# tns-parser
Oracle tnsnames.ora file / string parser written in PHP

# Installation

This library is designed to be installed using [Composer](https://getcomposer.org/).

Require entry:
```json
"dcarbone/tns-parser": "0.1.*"
```

This readme assumes knowledge of [Composer Autoloading](https://getcomposer.org/doc/01-basic-usage.md#autoloading).

# Feature List

- Able to parse input from file or passed string
- Multi-line and comment agnostic
- Supports multi-entry items (such as multi-address entries)
- Allows searching
- Allows basic sorting based upon entry name
- Implements the follow interfaces:
    - [Serializable](http://php.net/manual/en/class.serializable.php)
    - [JsonSerializable](http://php.net/manual/en/class.jsonserializable.php)
    - [ArrayAccess](http://php.net/manual/en/class.arrayaccess.php)
    - [Iterator](http://php.net/manual/en/class.iterator.php)
    - [Countable](http://php.net/manual/en/class.countable.php)
- Able to export entries as:
    - Original
    - Alphabetized tnsnames.ora file
    - JSON representation

# Example Usage

Given the below TNS entries:

```php
$tns = <<<STRING
AWESOME.MYSELF.DATABASE =
    (DESCRIPTION =
        (ADDRESS_LIST =
            (ADDRESS =
                (PROTOCOL = TCP)
                (HOST = me.db.myself.net)
                (PORT = 12345)
            )
        )
        (CONNECT_DATA =
            (SID = AWESOME)
            (SERVER = dedicated)
        )
    )

#--------------------------------------------------

AWESOME2.MYSELF.DATABASE =
    (DESCRIPTION =
        (ADDRESS_LIST =
            (ADDRESS =
                (PROTOCOL = TCP)
                (HOST = me2.db.myself.net)
                (PORT = 12345)
            )
            (ADDRESS =
                (PROTOCOL = TCP)
                (HOST = me25.db.myself.net)
                (PORT = 12345)
            )
            (LOAD_BALANCE = on)
            (FAILOVER = on)
            (ENABLE = broken)
        )
        (CONNECT_DATA =
            (SID = AWESOME2)
            (SERVER = dedicated)
            (FAILOVER_MODE =
                (TYPE = select)
                (METHOD = basic)
                (RETRIES = 120)
                (DELAY = 2)
            )
        )
    )
STRING;

```

You would initialize an instance of the parser:

```php
$parser = new \DCarbone\TNSParser();
```

Then, if the above was contained within a file:

```php
$parser->parseFile('path-to-file');
```

Or as just a string:

```php
$parser->parseString($tns);
```

And that's it!

## Searching

During input parsing, all properties for a given TNS entry are stored a multi-dimensional array to allow searching:

```php
$matched = $parser->search('awesome');
```

The search result is an array containing the NAMES of any matched entries.  The above, in this case, would result in:

```php
var_export($matched);
/*
array (
  0 => 'AWESOME.MYSELF.DATABASE',
  1 => 'AWESOME2.MYSELF.DATABASE',
)
*/
```

You can get as specific as you like:

```php
$matched = $parser->search('me2.db.myself');
var_export($matched):
/*
array (
  0 => 'AWESOME2.MYSELF.DATABASE',
)
*/
```

You can then use the matched names to retrieve the entries:

```php
// Using the 2nd match statement...
$entries = array();
foreach($matched as $name)
{
    $entries = $parser[$name];
}
var_export($entries);
/*
array (
  'DESCRIPTION' =>
  array (
    'ADDRESS_LIST' =>
    array (
      'ADDRESS' =>
      array (
        0 =>
        array (
          'PROTOCOL' => 'TCP',
          'HOST' => 'me2.db.myself.net',
          'PORT' => '12345',
        ),
        1 =>
        array (
          'PROTOCOL' => 'TCP',
          'HOST' => 'me25.db.myself.net',
          'PORT' => '12345',
        ),
      ),
      'LOAD_BALANCE' => 'on',
      'FAILOVER' => 'on',
      'ENABLE' => 'broken',
    ),
    'CONNECT_DATA' =>
    array (
      'SID' => 'AWESOME2',
      'SERVER' => 'dedicated',
      'FAILOVER_MODE' =>
      array (
        'TYPE' => 'select',
        'METHOD' => 'basic',
        'RETRIES' => '120',
        'DELAY' => '2',
      ),
    ),
  ),
)
*/
```

... Or get a valid TNS entry version as a string:

```php
$entry = $parser->getTNSEntryString($matched[0]);
var_export($entry);
/*
'AWESOME2.MYSELF.DATABASE =
    (DESCRIPTION =
        (ADDRESS_LIST =
            (ADDRESS =
                (PROTOCOL = TCP)
                (HOST = me2.db.myself.net)
                (PORT = 12345)
            )
            (ADDRESS =
                (PROTOCOL = TCP)
                (HOST = me25.db.myself.net)
                (PORT = 12345)
            )
            (LOAD_BALANCE = on)
            (FAILOVER = on)
            (ENABLE = broken)
        )
        (CONNECT_DATA =
            (SID = AWESOME2)
            (SERVER = dedicated)
            (FAILOVER_MODE =
                (TYPE = select)
                (METHOD = basic)
                (RETRIES = 120)
                (DELAY = 2)
            )
        )
    )'
*/
```

Under the covers, the searching system uses [preg_match](http://php.net/manual/en/function.preg-match.php), with the
following structure: ` '{%s}Si' ` by default, with %s being replaced by your input.

If you wish for a case-sensitive search, pass in `true` as the 2nd parameter when executing `search()`.

## Sorting

For the moment, searching is limited to alphabetical by entry name,
and utilizes [ksort](http://php.net/manual/en/function.ksort.php) under the covers.

```php
$parser->sort();
```

## Suggestions?

For the moment this library serves my needs, however if anybody using this library would like to see some
improvements / modifications made, please let me know!

## Tests

Work in progress.
