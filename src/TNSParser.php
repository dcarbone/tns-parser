<?php
namespace DCarbone;

/*
    Copyright 2015-2016 Daniel Carbone (daniel.p.carbone@gmail.com)

    Licensed under the Apache License, Version 2.0 (the "License");
    you may not use this file except in compliance with the License.
    You may obtain a copy of the License at

       http://www.apache.org/licenses/LICENSE-2.0

    Unless required by applicable law or agreed to in writing, software
    distributed under the License is distributed on an "AS IS" BASIS,
    WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
    See the License for the specific language governing permissions and
    limitations under the License.
 */

/**
 * PHP 5.3 compatibility
 */
if (interface_exists('\\JsonSerializable'))
{
    interface JsonSerializable extends \JsonSerializable {}
}
else
{
    interface JsonSerializable { public function jsonSerialize(); }
}

/**
 * Class TNSParser
 */
class TNSParser implements \Countable, \ArrayAccess, \Iterator, \Serializable, JsonSerializable
{
    /** @var array */
    private $_entries = array();

    /** @var array */
    private $_searchMap = array();

    /** @var bool */
    private $_sorted = false;

    // Below are only using during parsing.
    /** @var int */
    private $_openCount = 0;
    /** @var int */
    private $_closeCount = 0;
    /** @var bool */
    private $_inKey = true;
    /** @var string */
    private $_currentKey = '';
    /** @var string */
    private $_currentValue = '';
    /** @var array */
    private $_currentTree = array();
    /** @var mixed */
    private $_currentPosition;
    /** @var string */
    private $_currentName = '';

    /**
     * @param string $file
     * @return bool
     */
    public function parseFile($file)
    {
        $fh = @fopen($file, 'r');

        if (false === $fh)
        {
            throw new \RuntimeException(sprintf(
                '%s::parseFile - Unable to open specified file "%s"',
                get_class($this),
                $file
            ));
        }

        $this->_reset();

        $lines = 0;
        while (false === feof($fh))
        {
            $line = trim(fgets($fh));

            if (strlen($line) < 1 || $line[0] === '#')
                continue;

            $lines++;
            $this->_parseLine($line);
        }

        fclose($fh);

        if (0 === $lines)
        {
            trigger_error(sprintf('%s::parseFile - File "%s" appears to be empty.', get_class($this), $file));
            return false;
        }

        return true;
    }

    /**
     * @param string $string
     * @return bool
     */
    public function parseString($string)
    {
        if (false === is_string($string))
        {
            throw new \InvalidArgumentException(sprintf(
                '%s::parseString - Argument 1 expected to be string, %s seen.',
                get_class($this),
                gettype($string)
            ));
        }

        $string = trim($string);

        if (strlen($string) === 0)
        {
            trigger_error(sprintf('%s::parseString - Empty string seen.', get_class($this)));
            return false;
        }

        $this->_reset();

        $inComment = false;
        for($i = 0, $strlen = strlen($string); $i < $strlen; $i++)
        {
            switch($string[$i])
            {
                case '#':
                    $inComment = true;
                    continue 2;

                case "\n":
                    $inComment = false;
                    continue 2;

                default:
                    if ($inComment)
                        continue 2;

                    $this->_parseCharacter($string[$i]);
            }
        }

        return true;
    }

    /**
     * @param string $term
     * @return array
     */
    public function search($term, $caseSensitive = false)
    {
        $term = sprintf('{%s}S%s', $term, $caseSensitive ? '' : 'i');
        $matched = array();
        foreach($this->_searchMap as $name=>$values)
        {
            if (preg_match($term, $name))
            {
                $matched[] = $name;
            }
            else
            {
                foreach($values as $value)
                {
                    if (preg_match($term, $value))
                    {
                        $matched[] = $name;
                        break;
                    }
                }
            }
        }

        return $matched;
    }

    /**
     * @return bool
     */
    public function sort()
    {
        if ($this->_sorted)
            return true;

        return $this->_sorted = (ksort($this->_entries) && ksort($this->_searchMap));
    }

    /**
     * @param bool $alphabetize
     * @return string
     */
    public function getAllTNSEntriesString($alphabetize = false)
    {
        $tnsEntries = '';
        if ($alphabetize)
            $this->sort();

        foreach($this as $name=>$values)
        {
            $tnsEntries = sprintf("%s%s\n\n", $tnsEntries, $this->getTNSEntryString($name));
        }

        return $tnsEntries;
    }

    /**
     * @param string $name
     * @return null|string
     */
    public function getTNSEntryString($name)
    {
        if (false === isset($this[$name]))
            return null;

        $tnsEntry = sprintf("%s =", $name);
        $level = 0;
        foreach($this[$name] as $key=>$value)
        {
            $this->_buildTNSEntryStringPart($tnsEntry, $level, $key, $value);
        }

        return $tnsEntry;
    }

    /**
     * Return the current element
     * @link http://php.net/manual/en/iterator.current.php
     * @return mixed Can return any type.
     * @since 5.0.0
     */
    public function current()
    {
        return current($this->_entries);
    }

    /**
     * Move forward to next element
     * @link http://php.net/manual/en/iterator.next.php
     * @return void Any returned value is ignored.
     * @since 5.0.0
     */
    public function next()
    {
        next($this->_entries);
    }

    /**
     * Return the key of the current element
     * @link http://php.net/manual/en/iterator.key.php
     * @return mixed scalar on success, or null on failure.
     * @since 5.0.0
     */
    public function key()
    {
        return key($this->_entries);
    }

    /**
     * Checks if current position is valid
     * @link http://php.net/manual/en/iterator.valid.php
     * @return boolean The return value will be casted to boolean and then evaluated.
     * Returns true on success or false on failure.
     * @since 5.0.0
     */
    public function valid()
    {
        return key($this->_entries) !== null;
    }

    /**
     * Rewind the Iterator to the first element
     * @link http://php.net/manual/en/iterator.rewind.php
     * @return void Any returned value is ignored.
     * @since 5.0.0
     */
    public function rewind()
    {
        reset($this->_entries);
    }

    /**
     * Whether a offset exists
     * @link http://php.net/manual/en/arrayaccess.offsetexists.php
     * @param mixed $offset An offset to check for.
     * @return boolean true on success or false on failure. The return value will be casted to boolean if non-boolean was returned.
     * @since 5.0.0
     */
    public function offsetExists($offset)
    {
        return (isset($this->_entries[$offset]) || array_key_exists($offset, $this->_entries));
    }

    /**
     * Offset to retrieve
     * @link http://php.net/manual/en/arrayaccess.offsetget.php
     * @param mixed $offset The offset to retrieve.
     * @return mixed Can return all value types.
     * @since 5.0.0
     */
    public function offsetGet($offset)
    {
        if ($this->offsetExists($offset))
            return $this->_entries[$offset];

        throw new \OutOfRangeException(sprintf('No key %s exists', $offset));
    }

    /**
     * Offset to set
     * @link http://php.net/manual/en/arrayaccess.offsetset.php
     * @param mixed $offset The offset to assign the value to.
     * @param mixed $value The value to set.
     * @return void
     * @since 5.0.0
     */
    public function offsetSet($offset, $value)
    {
        throw new \BadMethodCallException('Not allowed to set values on this object');
    }

    /**
     * Offset to unset
     * @link http://php.net/manual/en/arrayaccess.offsetunset.php
     * @param mixed $offset The offset to unset.
     * @return void
     * @since 5.0.0
     */
    public function offsetUnset($offset)
    {
        throw new \BadMethodCallException('Not allowed to unset values on this object');
    }

    /**
     * Count elements of an object
     * @link http://php.net/manual/en/countable.count.php
     * @return int The custom count as an integer. The return value is cast to an integer.
     * @since 5.1.0
     */
    public function count()
    {
        return count($this->_entries);
    }

    /**
     * String representation of object
     * @link http://php.net/manual/en/serializable.serialize.php
     * @return string the string representation of the object or null
     * @since 5.1.0
     */
    public function serialize()
    {
        return serialize(array(
            $this->_entries,
            $this->_searchMap,
            $this->_sorted,
        ));
    }

    /**
     * Constructs the object
     * @link http://php.net/manual/en/serializable.unserialize.php
     * @param string $serialized The string representation of the object.
     * @return void
     * @since 5.1.0
     */
    public function unserialize($serialized)
    {
        $data = unserialize($serialized);
        if (count($data) !== 3
            || false === is_array($data[0])
            || false === is_array($data[1])
            || count($data[0]) !== count($data[1])
            || false === is_bool($data[2]))
        {
            throw new \DomainException('Invalid serialized class format detected.');
        }

        $this->_entries = $data[0];
        $this->_searchMap = $data[1];
        $this->_sorted = $data[2];
        $this->_currentPosition = &$this->_entries;
    }

    /**
     * Specify data which should be serialized to JSON
     * @link http://php.net/manual/en/jsonserializable.jsonserialize.php
     * @return mixed data which can be serialized by <b>json_encode</b>,
     * which is a value of any type other than a resource.
     * @since 5.4.0
     */
    public function jsonSerialize()
    {
        return $this->_entries;
    }

    // -------------------

    /**
     * @param string $tnsEntry
     * @param int $level
     * @param string $key
     * @param string|array $value
     */
    private function _buildTNSEntryStringPart(&$tnsEntry, $level, $key, $value)
    {
        $level++;
        $offset = str_repeat(' ', (4 * $level));
        if (is_array($value))
        {
            $firstKey = key($value);
            if (is_int($firstKey))
            {
                foreach($value as $subValue)
                {
                    $tnsEntry = sprintf("%s\n%s(%s =", $tnsEntry, $offset, $key);
                    foreach($subValue as $k=>$v)
                    {
                        $this->_buildTNSEntryStringPart($tnsEntry, $level, $k, $v);
                    }
                    $tnsEntry = sprintf("%s\n%s)", $tnsEntry, $offset);
                }
            }
            else
            {
                $tnsEntry = sprintf("%s\n%s(%s =", $tnsEntry, $offset, $key);
                foreach($value as $k=>$v)
                {
                    $this->_buildTNSEntryStringPart($tnsEntry, $level, $k, $v);
                }
                $tnsEntry = sprintf("%s\n%s)", $tnsEntry, $offset);
            }
        }
        else
        {
            $tnsEntry = sprintf("%s\n%s(%s = %s)", $tnsEntry, $offset, $key, $value);
        }
    }

    /**
     * @param string $line
     */
    private function _parseLine($line)
    {
        foreach(str_split($line) as $chr)
        {
            $this->_parseCharacter($chr);
        }
    }

    /**
     * @param string $chr
     */
    private function _parseCharacter($chr)
    {
        switch ($chr)
        {
            case '=': $this->_equalOperator(); break;
            case '(': $this->_openParenOperator(); break;
            case ')': $this->_closeParenOperator(); break;

            default: $this->_noOperator($chr); break;
        }
    }

    private function _equalOperator()
    {
        $this->_inKey = false;
        if (count($this->_currentTree) === 0)
        {
            $this->_currentName = trim($this->_currentKey);
            $this->_searchMap[$this->_currentName] = array();
        }
        $this->_currentTree[] = trim($this->_currentKey);
        $this->_currentKey = '';
    }

    private function _openParenOperator()
    {
        $this->_openCount++;
        $this->_inKey = true;
        $this->_updateCurrentPosition();
    }

    private function _closeParenOperator()
    {
        $this->_closeCount++;

        if ('' !== $this->_currentValue)
            $this->_populateEntry();

        array_pop($this->_currentTree);
        $this->_currentValue = '';
        $this->_inKey = true;

        if ($this->_openCount === $this->_closeCount)
            $this->_finishedEntry();
    }

    /**
     * @param string $chr
     */
    private function _noOperator($chr)
    {
        if ($this->_inKey)
            $this->_currentKey = sprintf('%s%s', $this->_currentKey, $chr);
        else
            $this->_currentValue = sprintf('%s%s', $this->_currentValue, $chr);
    }

    private function _populateEntry()
    {
        $localKey = end($this->_currentTree);
        $localValue = trim($this->_currentValue);

        if (null === $this->_currentPosition)
        {
            $this->_currentPosition = array(
                $localKey => $localValue
            );
            $this->_searchMap[$this->_currentName][] = $localValue;
            return;
        }

        if (is_array($this->_currentPosition))
        {
            $firstKey = key($this->_currentPosition);

            if (is_string($firstKey))
            {
                if (isset($this->_currentPosition[$localKey]))
                {
                    $tmp = $this->_currentPosition;
                    array_pop($this->_currentTree);
                    $this->_updateCurrentPosition();
                    $this->_currentPosition = array(
                        $tmp,
                        array($localKey => $localValue)
                    );
                    $this->_currentTree[] = $localKey;
                }
                else
                {
                    $this->_currentPosition[$localKey] = $localValue;
                }
            }
            else if (is_int($firstKey))
            {
                $i = 0;
                while (isset($this->_currentPosition[$i]) && isset($this->_currentPosition[$i][$localKey]))
                {
                    $i++;
                }
                if (false === isset($this->_currentPosition[$i]))
                    $this->_currentPosition[$i] = array();

                $this->_currentPosition[$i][$localKey] = $localValue;
            }
            else
            {
                throw new \DomainException('Invalid entry structure: Non-integer or string key found!');
            }

            $this->_searchMap[$this->_currentName][] = $localValue;
        }
    }

    private function _finishedEntry()
    {
        $this->_openCount = 0;
        $this->_closeCount = 0;
        $this->_currentKey = '';
        $this->_currentValue = '';
        $this->_currentName = '';
        $this->_currentTree = array();
    }

    private function _updateCurrentPosition()
    {
        // Reset current position reference
        $this->_currentPosition = &$this->_entries;
        foreach($this->_currentTree as $key)
        {
            $this->_currentPosition = &$this->_currentPosition[$key];
        }
    }

    private function _reset()
    {
        $this->_entries = array();
        $this->_searchMap = array();
        $this->_sorted = false;

        $this->_openCount = 0;
        $this->_closeCount = 0;
        $this->_inKey = true;
        $this->_currentKey = '';
        $this->_currentValue = '';
        $this->_currentTree = array();
        $this->_currentPosition = &$this->_entries;
        $this->_currentName = '';
    }
}
