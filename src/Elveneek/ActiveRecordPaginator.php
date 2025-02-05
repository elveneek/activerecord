<?php 
namespace Elveneek;	
//Здесь собраны методы, которые отвечают за пагинацию
trait ActiveRecordPaginator {
 
	/**
	 * Разбивает набор результатов на страницы
	 * @param int $per_page Количество элементов на странице
	 * @param int|false $current Номер текущей страницы (начиная с 0)
	 * @return $this
	 */
	public function paginate($per_page = 10, $current = false)
	{
		if ($current === false) {
			//Если в контроллере забыли передать это подразумевающееся понятие, поможем контроллеру
			if (isset($_GET['page'])) {
				$current = (int)$_GET['page'];
			} else {
				$current = 0;
			}
		}

		if ($per_page < 1) {
			throw new \InvalidArgumentException("Items per page must be greater than 0");
		}

		if ($current < 0) {
			throw new \InvalidArgumentException("Page number must be 0 or greater");
		}

		$this->current_page = $current;
		$this->per_page = $per_page;
		
		// Set LIMIT and OFFSET for pagination
		$this->limit($per_page);
		$this->offset($current * $per_page);
		
		return $this;
	}
	
	/**
	 * Количество строк в найденном запросе без учета LIMIT/OFFSET
	 * @return int Общее количество строк
	 */
	public function found_rows()
	{
		// Create a clone of current query without LIMIT/OFFSET
		$clone = clone $this;
		$clone->queryLimit = '';
		$clone->queryOffset = '';
		$clone->queryReady = false;
		$clone->_data = [];
		$clone->_count = 0;
		
		// Execute query and count all rows
		return $clone->count();
	}
}
