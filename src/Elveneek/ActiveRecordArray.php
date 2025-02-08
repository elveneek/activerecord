<?php

namespace Elveneek;

trait ActiveRecordArray
{
    function offsetGet(mixed $index): mixed
    {
        if (is_numeric($index)) {
            if ($this->queryReady === false) {
                $this->fetch_data_now();
            }
            
            // Ensure we have fetched enough data
            while (!$this->isFetchedAll && $this->fetchedCount <= $index) {
                if ($row = $this->currentPDOStatement->fetch()) {
                    $this->fetchedCount++;
                    $this->_data[] = $row;
                } else {
                    $this->isFetchedAll = true;
                    $this->_count = count($this->_data);
                    break;
                }
            }
            
            // Only set cursor and return if we have data at this index
            if (isset($this->_data[$index])) {
                $this->_cursor = $index;
                return $this;
            }
            return null;
        }
        return $this->{$index};
    }

    function offsetExists(mixed $offset): bool
    {
        if ($this->queryReady === false) {
            $this->fetch_data_now();
        }
        
        // Handle numeric offsets
        if (is_numeric($offset)) {
            if ($offset < 0) {
                return false;
            }
            
            while (!$this->isFetchedAll && $this->fetchedCount <= $offset) {
                if ($row = $this->currentPDOStatement->fetch()) {
                    $this->fetchedCount++;
                    $this->_data[] = $row;
                } else {
                    $this->isFetchedAll = true;
                    $this->_count = count($this->_data);
                    break;
                }
            }
         
            return isset($this->_data[$offset]);
        }

        // Handle string keys for single record access
        if (isset($this->_data[$this->_cursor])) {
            // Check for methods first (consistent with __get behavior)
            if (method_exists($this, $offset)) {
                return true;
            }
            // Then check for properties in the data
            return property_exists($this->_data[$this->_cursor], $offset);
        }
        
        return false;
    }

    function offsetSet(mixed $offset, mixed $value): void
    {
        if (is_null($offset)) {
            //ничего пока не делать
        } else {
            if ($this->queryReady === false) {
                $this->fetch_data_now();
            }
            
            // Initialize _data if needed
            if (!isset($this->_data[$this->_cursor])) {
                $this->_data[$this->_cursor] = new \stdClass();
            }
            
            // Handle the value similar to __set()
            if ($value === '' && substr($offset, -3) === '_at') {
                $value = SQL_NULL;
            }
            if (is_null($value)) {
                $value = SQL_NULL;
            }
            
            // Set the value both in _data and future_data
            $this->_data[$this->_cursor]->{$offset} = $value;
            $this->_future_data[$offset] = (string)$value;
        }
    }

    function offsetUnset(mixed $offset): void
    {
        if ($this->queryReady === false) {
            $this->fetch_data_now();
        }
        
        if (is_numeric($offset)) {
            // Handle numeric indexes
            if (isset($this->_data[$offset])) {
                // Force fetch all remaining data
                if (!$this->isFetchedAll) {
                    while ($row = $this->currentPDOStatement->fetch()) {
                        $this->fetchedCount++;
                        $this->_data[] = $row;
                    }
                    $this->isFetchedAll = true;
                }
                
                $this->_data[$offset] = null;
                // Update count
                $count = 0;
                foreach ($this->_data as $item) {
                    if ($item !== null) {
                        $count++;
                    }
                }
                $this->_count = $count;
            }
        } else {
            // Handle string keys by unsetting the property on the current record
            if (isset($this->_data[$this->_cursor])) {
                unset($this->_data[$this->_cursor]->{$offset});
                // Also unset from future_data if it exists
                if (isset($this->_future_data[$offset])) {
                    unset($this->_future_data[$offset]);
                }
            }
        }
    }

    function seek($position)
    {
        $this->_cursor = $position;
        while ($this->fetchedCount <= $this->_cursor) {
            if ($row = $this->currentPDOStatement->fetch()) {
                $this->fetchedCount++;
                $this->_data[] = $row;
            } else {
                $this->_count = count($this->_data);
                $this->isFetchedAll = true;
                return $this;
            }
        }
    }

    function current(): mixed
    {
        return $this;
    }

    function next(): void
    {
        if (!$this->_is_sliced) {
            $this->_cursor++;
            return;
        }
        if (!$this->_must_rewind) {
            $this->_cursor++;
        }
    }

    function valid(): bool
    {
        if ($this->queryReady === false) {
            $this->fetch_data_now();
        }
        if (!$this->_is_sliced) {
            //Если не включен режим слайса, то конец происходит в двух случаях:
            //1. Всё уже скачано ранее ($this->isFetchedAll == true) и при этом курсор < количества. Например, количество = 3, курсор = 3 (0, 1, 2 уже были)
            //2. Если ещё не открыт текущий fetch по тем или иным причинам, делаем fetch. Если не получилось, то false. В противном случае (курсор гдето в начале) всё валидно
            if ($this->isFetchedAll === true) {
                return $this->_cursor < $this->_count;
            }

            while ($this->fetchedCount <= $this->_cursor) {
                if ($row = $this->currentPDOStatement->fetch()) {
                    $this->fetchedCount++;
                    $this->_data[] = $row;
                } else {
                    $this->_count = count($this->_data);
                    $this->isFetchedAll = true;
                    return false;
                }
            }
            return true;
        }

        //Вот тут смотрим, обогнал ли курсор то количество данных, которое нам надо получить.
        //Если курсор указывает на дальше, чем скачано на данный момент, то скачиваем
        if ($this->isFetchedAll === true && $this->_cursor >= $this->_count) {
            return false;
        }
        $this->_revinded++;
        if ($this->_revinded % $this->_slice_size == 0) {
            $this->_must_rewind = true;
            return false;
        }
        return true;
    }

    function key(): mixed
    {
        return $this->_cursor;
    }

    function rewind(): void
    {
        if (!$this->_is_sliced) {
            $this->_cursor = 0;
            return;
        }

        if ($this->_must_rewind) {
            $this->_must_rewind = false;
        } else {
            $this->_cursor = 0;
        }
    }
}
