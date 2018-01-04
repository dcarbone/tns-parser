<?php

namespace DCarbone;

/*
    Copyright 2015-2018 Daniel Carbone (daniel.p.carbone@gmail.com)

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
 * Class TNSParser
 */
class TNSParser implements \Countable, \ArrayAccess, \Iterator, \Serializable, \JsonSerializable {
    /** @var array */
    private $_entries = [];

    /** @var array */
    private $_searchMap = [];

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
    private $_currentTree = [];
    /** @var mixed */
    private $_currentPosition;
    /** @var string */
    private $_currentName = '';

    /**
     * @param string $file
     * @return bool
     */
    public function parseFile($file) {
        $fh = @fopen($file, 'r');

        if (false === $fh) {
            throw new \RuntimeException(sprintf(
                '%s::parseFile - Unable to open specified file "%s"',
                get_class($this),
                $file
            ));
        }

        $this->_reset();

        $lines = 0;
        while (false === feof($fh)) {
            $line = trim(fgets($fh));

            if (1 > strlen($line) || '#' === $line[0]) {
                continue;
            }

            $lines++;
            $this->_parseLine($line);
        }

        fclose($fh);

        if (0 === $lines) {
            trigger_error(sprintf('%s::parseFile - File "%s" appears to be empty.', get_class($this), $file));
            return false;
        }

        return true;
    }

    /**
     * @param string $string
     * @return bool
     */
    public function parseString($string) {
        if (!is_string($string)) {
            throw new \InvalidArgumentException(sprintf(
                '%s::parseString - Argument 1 expected to be string, %s seen.',
                get_class($this),
                gettype($string)
            ));
        }

        $string = trim($string);

        if (strlen($string) === 0) {
            trigger_error(sprintf('%s::parseString - Empty string seen.', get_class($this)));
            return false;
        }

        $this->_reset();

        $inComment = false;
        for ($i = 0, $strlen = strlen($string); $i < $strlen; $i++) {
            if ($inComment) {
                if ("\n" === $string[$i]) {
                    $inComment = false;
                }
            } else if ('#' === $string[$i]) {
                $inComment = true;
            } else {
                $this->_parseCharacter($string[$i]);
            }
        }

        return true;
    }

    /**
     * @param string $term
     * @param bool $caseSensitive
     * @return array
     */
    public function search($term, $caseSensitive = false) {
        $term = sprintf('{%s}S%s', $term, $caseSensitive ? '' : 'i');
        $matched = [];
        foreach ($this->_searchMap as $name => $values) {
            if (preg_match($term, $name)) {
                $matched[] = $name;
            } else {
                foreach ($values as $value) {
                    if (preg_match($term, $value)) {
                        $matched[] = $name;
                        break;
                    }
                }
            }
        }

        return $matched;
    }

    /**
     * @param bool $alphabetize
     * @return string
     */
    public function getAllTNSEntriesString($alphabetize = false) {
        $tnsEntries = '';
        if ($alphabetize) {
            $this->sort();
        }

        foreach ($this as $name => $values) {
            $tnsEntries = sprintf("%s%s\n\n", $tnsEntries, $this->getTNSEntryString($name));
        }

        return $tnsEntries;
    }

    /**
     * @return bool
     */
    public function sort() {
        if ($this->_sorted) {
            return true;
        }

        return $this->_sorted = (ksort($this->_entries) && ksort($this->_searchMap));
    }

    /**
     * @param string $name
     * @return null|string
     */
    public function getTNSEntryString($name) {
        if (!isset($this[$name])) {
            return null;
        }

        $tnsEntry = sprintf("%s =", $name);
        $level = 0;
        foreach ($this[$name] as $key => $value) {
            $this->_buildTNSEntryStringPart($tnsEntry, $level, $key, $value);
        }

        return $tnsEntry;
    }

    /**
     * @return array
     */
    public function current() {
        return current($this->_entries);
    }

    public function next() {
        next($this->_entries);
    }

    // -------------------

    /**
     * @return string
     */
    public function key() {
        return key($this->_entries);
    }

    /**
     * @return bool
     */
    public function valid() {
        return key($this->_entries) !== null;
    }

    public function rewind() {
        reset($this->_entries);
    }

    /**
     * @param mixed $offset
     * @return mixed
     */
    public function offsetGet($offset) {
        if ($this->offsetExists($offset)) {
            return $this->_entries[$offset];
        }

        throw new \OutOfRangeException(sprintf('No key %s exists', $offset));
    }

    /**
     * @param mixed $offset
     * @return bool
     */
    public function offsetExists($offset) {
        return (isset($this->_entries[$offset]) || array_key_exists($offset, $this->_entries));
    }

    /**
     * @param mixed $offset
     * @param mixed $value
     */
    public function offsetSet($offset, $value) {
        throw new \BadMethodCallException('Not allowed to set values on this object');
    }

    /**
     * @param mixed $offset
     */
    public function offsetUnset($offset) {
        throw new \BadMethodCallException('Not allowed to unset values on this object');
    }

    /**
     * @return int
     */
    public function count() {
        return count($this->_entries);
    }

    /**
     * @return string
     */
    public function serialize() {
        return serialize([
            $this->_entries,
            $this->_searchMap,
            $this->_sorted,
        ]);
    }

    /**
     * @param string $serialized
     */
    public function unserialize($serialized) {
        $data = unserialize($serialized);
        if (count($data) !== 3
            || !is_array($data[0])
            || !is_array($data[1])
            || count($data[0]) !== count($data[1])
            || !is_bool($data[2])) {
            throw new \DomainException('Invalid serialized class format detected.');
        }

        $this->_entries = $data[0];
        $this->_searchMap = $data[1];
        $this->_sorted = $data[2];
        $this->_currentPosition = &$this->_entries;
    }

    /**
     * @return array|mixed
     */
    public function jsonSerialize() {
        return $this->_entries;
    }

    /**
     * @param string $tnsEntry
     * @param int $level
     * @param string $key
     * @param string|array $value
     */
    private function _buildTNSEntryStringPart(&$tnsEntry, $level, $key, $value) {
        $level++;
        $offset = str_repeat(' ', (4 * $level));
        if (is_array($value)) {
            $firstKey = key($value);
            if (is_int($firstKey)) {
                foreach ($value as $subValue) {
                    $tnsEntry = sprintf("%s\n%s(%s =", $tnsEntry, $offset, $key);
                    foreach ($subValue as $k => $v) {
                        $this->_buildTNSEntryStringPart($tnsEntry, $level, $k, $v);
                    }
                    $tnsEntry = sprintf("%s\n%s)", $tnsEntry, $offset);
                }
            } else {
                $tnsEntry = sprintf("%s\n%s(%s =", $tnsEntry, $offset, $key);
                foreach ($value as $k => $v) {
                    $this->_buildTNSEntryStringPart($tnsEntry, $level, $k, $v);
                }
                $tnsEntry = sprintf("%s\n%s)", $tnsEntry, $offset);
            }
        } else {
            $tnsEntry = sprintf("%s\n%s(%s = %s)", $tnsEntry, $offset, $key, $value);
        }
    }


    private function _reset() {
        $this->_entries = [];
        $this->_searchMap = [];
        $this->_sorted = false;

        $this->_openCount = 0;
        $this->_closeCount = 0;
        $this->_inKey = true;
        $this->_currentKey = '';
        $this->_currentValue = '';
        $this->_currentTree = [];
        $this->_currentPosition = &$this->_entries;
        $this->_currentName = '';
    }

    /**
     * @param string $line
     */
    private function _parseLine($line) {
        foreach (str_split($line) as $chr) {
            $this->_parseCharacter($chr);
        }
    }

    /**
     * @param string $chr
     */
    private function _parseCharacter($chr) {
        if ('=' === $chr) {
            $this->_equalOperator();
        } else if ('(' === $chr) {
            $this->_openParenOperator();
        } else if (')' === $chr) {
            $this->_closeParenOperator();
        } else  {
            $this->_noOperator($chr);
        }
    }

    private function _equalOperator() {
        $this->_inKey = false;
        if (0 === count($this->_currentTree)) {
            $this->_currentName = trim($this->_currentKey);
            $this->_searchMap[$this->_currentName] = [];
        }
        $this->_currentTree[] = trim($this->_currentKey);
        $this->_currentKey = '';
    }

    private function _openParenOperator() {
        $this->_openCount++;
        $this->_inKey = true;
        $this->_updateCurrentPosition();
    }

    private function _updateCurrentPosition() {
        // Reset current position reference
        $this->_currentPosition = &$this->_entries;
        foreach ($this->_currentTree as $key) {
            $this->_currentPosition = &$this->_currentPosition[$key];
        }
    }

    private function _closeParenOperator() {
        $this->_closeCount++;

        if ('' !== $this->_currentValue) {
            $this->_populateEntry();
        }

        array_pop($this->_currentTree);
        $this->_currentValue = '';
        $this->_inKey = true;

        if ($this->_openCount === $this->_closeCount) {
            $this->_finishedEntry();
        }
    }

    private function _populateEntry() {
        $localKey = end($this->_currentTree);
        $localValue = trim($this->_currentValue);

        if (null === $this->_currentPosition) {
            $this->_currentPosition = [
                $localKey => $localValue
            ];
            $this->_searchMap[$this->_currentName][] = $localValue;
            return;
        }

        if (is_array($this->_currentPosition)) {
            $firstKey = key($this->_currentPosition);

            if (is_string($firstKey)) {
                if (isset($this->_currentPosition[$localKey])) {
                    $tmp = $this->_currentPosition;
                    array_pop($this->_currentTree);
                    $this->_updateCurrentPosition();
                    $this->_currentPosition = [
                        $tmp,
                        [$localKey => $localValue]
                    ];
                    $this->_currentTree[] = $localKey;
                } else {
                    $this->_currentPosition[$localKey] = $localValue;
                }
            } else {
                if (is_int($firstKey)) {
                    $i = 0;
                    while (isset($this->_currentPosition[$i]) && isset($this->_currentPosition[$i][$localKey])) {
                        $i++;
                    }
                    if (false === isset($this->_currentPosition[$i])) {
                        $this->_currentPosition[$i] = [];
                    }

                    $this->_currentPosition[$i][$localKey] = $localValue;
                } else {
                    throw new \DomainException('Invalid entry structure: Non-integer or string key found!');
                }
            }

            $this->_searchMap[$this->_currentName][] = $localValue;
        }
    }

    private function _finishedEntry() {
        $this->_openCount = 0;
        $this->_closeCount = 0;
        $this->_currentKey = '';
        $this->_currentValue = '';
        $this->_currentName = '';
        $this->_currentTree = [];
    }

    /**
     * @param string $chr
     */
    private function _noOperator($chr) {
        if ($this->_inKey) {
            $this->_currentKey = sprintf('%s%s', $this->_currentKey, $chr);
        } else {
            $this->_currentValue = sprintf('%s%s', $this->_currentValue, $chr);
        }
    }
}
