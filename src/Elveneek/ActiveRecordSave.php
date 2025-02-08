<?php 
namespace Elveneek;
//Здесь собраны методы, которые работают с сохранением данных
trait ActiveRecordSave {
	
	public static function create()
	{
		$object =  new static();
		
		$object->queryNew = true; //Пометка о том, что запрос новый
		$object->queryReady = true; //Пометка о том, что "ленивый" запрос получен и в базу лезть не надо
		$object->_future_data = array(); //Сброс future data (на всякий случай)
		
		$object->_cursor  = 0; //Помещаем курсор в начало
		$object->_count = 1; //Считаем что есть одна запись
		$object->fetchedCount = 1; //Считаем что запросили из базы одну запись
		$object->isFetchedAll = true; //Считаем что всё уже получено
		$object->queryConditions = ['(false)'];  //Если вдруг будет запрос - он будет пустым
		$object->queryLimit = ' LIMIT 0'; //Если вдруг будет запрос - он будет пустым
		
		$object->_data[$object->_cursor]=new \stdClass(); //Инициируем первую строчку
		
		
		return $object;
	}
	
	
	
		
	function __set($name,$value)
	{	
		if(method_exists($this,'set_'.$name)) {
			$this->{'set_'.$name}($value);
		} else {
			if($value=='' && substr($name,-3)=='_at'){
				$value=SQL_NULL;
			}
			if (is_null($value)){
				$value=SQL_NULL;
			}
			//FIXME: тут кидать exception, если режим не "Добавить" и количество == 0
			$this->_future_data[$name]= (string) $value;
		}
	}
	
	
 
	
	/* для new из одного элемента - делает одну вставку. Для new из нескольких элементов - */
	public function saveOldVersionDeprecated()
	{
		$to_array_cache=array();
		$current_id=0;
		$_query_string = '';
		$isAlmostOneColumnCreated = false;
						
		if($this->queryNew===true) {
			//Ветка новой записи (INSERT)
			
			$this->insert_id=false;
			$fields=array();
			$values=array();
			foreach($this->_future_data as $key => $value) {
				$fields[]=" ".ActiveRecord::DB_FIELD_DEL . $key . ActiveRecord::DB_FIELD_DEL." ";
				
				if(SQL_NULL === $value || (substr($key,-3)=='_id' && !$value && $value !== '0' && $value !== 0)){
					$values[]=" NULL ";
				}else{
					$values[]=" ". ActiveRecord::$db->quote ($value)." ";
				}
				
			}
  
			if(!empty($values)){
				//FIXME: Транзакции
				$_query_string='insert into '.ActiveRecord::DB_FIELD_DEL.$this->table .ActiveRecord::DB_FIELD_DEL.' ('. implode (',',$fields) .') values ('. implode (',',$values) .')';
			}else{
				$_query_string='insert into '.ActiveRecord::DB_FIELD_DEL.$this->table .ActiveRecord::DB_FIELD_DEL.' () values ()';
			}
		
			
			//TODO: преобразовывать текущий объект в "готовый к поиску по insert_id, возвращающий ->id, но пока не сделавший запроса
		} else {
			//Ветка существующей записи (UPDATE)
			if ($this->queryReady===false) {
				$this->fetch_data_now();
			}
			if(isset($this->_data[0])){
				$current_id = $this->_data[0]->id;
			}
			if(count($this->_data) != 1 ){
				if(count($this->_data) == 0){
					throw new \Exception('Trying to update empty object. You can use save() only with object with one row.');
				}
				if(count($this->_data) >1 ){
					throw new \Exception('Trying to update more than one row. Please use saveAll()');
				}
				
				return;
			}
			//Тут проверка на апдейт
			if(isset($this->_data[0]) && (count($this->_future_data)>0)){
				$attributes=array();
				foreach($this->_future_data as $key => $value) {

					if(SQL_NULL === $value  || (substr($key,-3)=='_id' && !$value && $value !== '0' && $value !== 0)){
						$attributes[]=" ". ActiveRecord::DB_FIELD_DEL . $key. ActiveRecord::DB_FIELD_DEL ." = NULL ";
					}else{
						$attributes[]=" ". ActiveRecord::DB_FIELD_DEL . $key. ActiveRecord::DB_FIELD_DEL ." = ". ActiveRecord::$db->quote($value)." ";
					}
					
				}
				$attribute_string=implode (',',$attributes);
				$_query_string='update '.ActiveRecord::DB_FIELD_DEL. $this->table .ActiveRecord::DB_FIELD_DEL.' set '.$attribute_string.", ". ActiveRecord::DB_FIELD_DEL ."updated_at". ActiveRecord::DB_FIELD_DEL ." = NOW()  where ". ActiveRecord::DB_FIELD_DEL ."id". ActiveRecord::DB_FIELD_DEL ." = '". $current_id ."'";
			}
		}
		
		try {
			//делается попытка сделать запрос
			if($_query_string !==''){
				$_query_result = ActiveRecord::$db->exec($_query_string);
			}
		}catch  (\PDOException $exception) {
			
		 
			if($exception->errorInfo[1]===1054){
				//Столбец не нашёлся при записи.
				
				//смотрим существующие столбцы в обход кеша столбцов
				$_res=ActiveRecord::$db->query('SELECT * FROM '.ActiveRecord::DB_FIELD_DEL.$this->table.ActiveRecord::DB_FIELD_DEL.' LIMIT 0');
				$columns  = [];
				$columns_count = $_res->columnCount();
				for($i=0; $i<=$columns_count-1; $i++){
					$column = $_res->getColumnMeta($i);
					$columns[$column['name']]=true;
				}
				
				//Создаём отсутствующие колонки

				foreach($this->_future_data as $field => $value) {
					if(!isset($columns[$field])){
						Scaffold::create_field($this->table, $field);
						
						$isAlmostOneColumnCreated = true;
					}
				}
			
				//под шумок создаем стандартные столбцы
				foreach(array('sort','created_at','updated_at') as  $field){
					if(!isset($columns[$field])){
						Scaffold::create_field($this->table, $field);
						$isAlmostOneColumnCreated = true;
					}
				}

				
				//Делаем повторный запрос
				$_query_result = ActiveRecord::$db->exec($_query_string);
				
			}elseif($exception->getCode() === 'HY000' && $exception->errorInfo[1]===2006){
				//Переподключаемся и делаем повторный запрос
				ActiveRecord::$db = ActiveRecord::connect();
				$_query_result = ActiveRecord::$db->exec($_query_string);
			}elseif(  $exception->errorInfo[1]===1064){
				//Переподключаемся и делаем повторный запрос
				throw new \Exception('Wrong SQL query: '.$_query_string);
			}else{
				throw $exception;
			}
		}
		
		//После сохранения запись перезаписывается повторно, делается update sord/updated_at/created_at
		if($this->queryNew===true) {
			$this->insert_id = ActiveRecord::$db->lastInsertId();
			$current_id = $this->insert_id;
			
			$_query_fields = array();
			if (empty($this->_future_data["sort"])) {
				$_query_fields[] = ActiveRecord::DB_FIELD_DEL ."sort". ActiveRecord::DB_FIELD_DEL ." = '".$this->insert_id."'";
			}
			if (empty($this->_future_data["created_at"])) {
				$_query_fields[] = ActiveRecord::DB_FIELD_DEL ."created_at". ActiveRecord::DB_FIELD_DEL ." = NOW()";
			}
			if (empty($this->_future_data["updated_at"])) {
				$_query_fields[] = ActiveRecord::DB_FIELD_DEL ."updated_at". ActiveRecord::DB_FIELD_DEL ." = NOW()";
			}
			if (!empty($_query_fields)) {
				$_query_string = 'update '.ActiveRecord::DB_FIELD_DEL. $this->table .ActiveRecord::DB_FIELD_DEL.' set ' . implode(', ', $_query_fields) . " where ". ActiveRecord::DB_FIELD_DEL ."id". ActiveRecord::DB_FIELD_DEL ." = '".$this->insert_id."'";
				
				try {
					$_query_result = ActiveRecord::$db->exec($_query_string);
			
				}catch  (\PDOException $exception) {
					if($exception->errorInfo[1]===1054){
						//смотрим существующие столбцы в обход кеша столбцов
						$_res=ActiveRecord::$db->query('SELECT sort, created_at, updated_at FROM '.ActiveRecord::DB_FIELD_DEL. $this->table . ActiveRecord::DB_FIELD_DEL . ' LIMIT 0');
						$columns  = [];
						$columns_count = $_res->columnCount();
						for($i=0; $i<=$columns_count-1; $i++){
							$column = $_res->getColumnMeta($i);
							$columns[$column['name']]=true;
						}
						foreach(array('sort','created_at','updated_at') as  $key){
							if(!isset($columns[$key])){
								Scaffold::create_field($this->table, $key);
								$isAlmostOneColumnCreated = true;
							}
						}
						$_query_result = ActiveRecord::$db->exec($_query_string);
					}else{
						throw $exception;
					}
				}
			}
		}
		$this->_future_data=array();
		if($isAlmostOneColumnCreated){

			throw new \Exception('Ты забыл код написать, ленивая ты жопа');
			
			//Перезагружаем новые воркеры
		//	App::$instance->rpc->call("http.Reset", true);
			//Считаем, что колонка создалась, кеш уже не актуален
			unset(ActiveRecord::$_columns_cache[$this->table]);
		}
		 
		  
		return $this;
	}
	
	//FIXME: dictionary убран (save_dictionary_array). При необходимости надо зако

	public function save()
	{
		try {
			if($this->queryNew === true) {
				// INSERT logic
				$fields = [];
				$values = [];
				foreach($this->_future_data as $key => $value) {
					$fields[] = ActiveRecord::DB_FIELD_DEL . $key . ActiveRecord::DB_FIELD_DEL;
					
					if(SQL_NULL === $value) {
						$values[] = "NULL";
					} else {
						$values[] = ActiveRecord::$db->quote($value);
					}
				}
				
				$query = 'INSERT INTO ' . ActiveRecord::DB_FIELD_DEL . $this->table . ActiveRecord::DB_FIELD_DEL
					. ' (' . implode(',', $fields) . ') VALUES (' . implode(',', $values) . ')';
				
				ActiveRecord::$db->exec($query);
				$this->insert_id = ActiveRecord::$db->lastInsertId();
				$current_id = $this->insert_id; // Store for later use in updates
				
				// Initialize _data with the new record
				if (!isset($this->_data[$this->_cursor])) {
					$this->_data[$this->_cursor] = new \stdClass();
				}
				// Initialize _data with the new record
				if (!isset($this->_data[$this->_cursor])) {
					$this->_data[$this->_cursor] = new \stdClass();
				}
				$this->_data[$this->_cursor]->id = $this->insert_id;
				foreach ($this->_future_data as $key => $value) {
					$this->_data[$this->_cursor]->$key = $value;
				}
				
				// Set default values if not provided
				$updates = [];
				if(empty($this->_future_data['sort'])) {
					$updates[] = ActiveRecord::DB_FIELD_DEL . 'sort' . ActiveRecord::DB_FIELD_DEL . ' = ' . $this->insert_id;
				}
				if(empty($this->_future_data['created_at'])) {
					$updates[] = ActiveRecord::DB_FIELD_DEL . 'created_at' . ActiveRecord::DB_FIELD_DEL . ' = NOW()';
				}
				if(empty($this->_future_data['updated_at'])) {
					$updates[] = ActiveRecord::DB_FIELD_DEL . 'updated_at' . ActiveRecord::DB_FIELD_DEL . ' = NOW()';
				}
				
				if(!empty($updates)) {
					$updateQuery = 'UPDATE ' . ActiveRecord::DB_FIELD_DEL . $this->table . ActiveRecord::DB_FIELD_DEL
						. ' SET ' . implode(', ', $updates)
						. ' WHERE ' . ActiveRecord::DB_FIELD_DEL . 'id' . ActiveRecord::DB_FIELD_DEL . ' = ' . $this->insert_id;
					ActiveRecord::$db->exec($updateQuery);

					$this->queryNew = false;

					
				}
			} else {
				// UPDATE logic
				if ($this->queryReady === false) {
					$this->fetch_data_now();
				}
				
				if(!isset($this->_data[$this->_cursor])) {
					throw new \Exception('Trying to update empty object');
				}
				
				$current_id = $this->_data[$this->_cursor]->id;
				
				if(count($this->_future_data) > 0) {
					$attributes = [];
					foreach($this->_future_data as $key => $value) {
						if(SQL_NULL === $value) {
							$attributes[] = ActiveRecord::DB_FIELD_DEL . $key . ActiveRecord::DB_FIELD_DEL . " = NULL";
						} else {
							$attributes[] = ActiveRecord::DB_FIELD_DEL . $key . ActiveRecord::DB_FIELD_DEL . " = " . ActiveRecord::$db->quote($value);
						}
					}
					
					$query = 'UPDATE ' . ActiveRecord::DB_FIELD_DEL . $this->table . ActiveRecord::DB_FIELD_DEL
						. ' SET ' . implode(', ', $attributes)
						. ', ' . ActiveRecord::DB_FIELD_DEL . 'updated_at' . ActiveRecord::DB_FIELD_DEL . ' = NOW()'
						. ' WHERE ' . ActiveRecord::DB_FIELD_DEL . 'id' . ActiveRecord::DB_FIELD_DEL . ' = ' . $current_id;
					
					ActiveRecord::$db->exec($query);
					
					// Update the current record in _data with new values
					foreach($this->_future_data as $key => $value) {
						$this->_data[$this->_cursor]->$key = $value;
					}
					$this->_data[$this->_cursor]->updated_at = date('Y-m-d H:i:s');
					
					// Clear specific caches but keep _data
					$this->_get_by_id_cache = false;
					$this->_objects_cache = [];
					
					// Clear column cache for this table
					if (isset(ActiveRecord::$_columns_cache[$this->table])) {
						unset(ActiveRecord::$_columns_cache[$this->table]);
					}
				}
			}
			
			// Clear future data
			$this->_future_data = [];
		
			return $this;
			
		} catch (\PDOException $e) {
			if($e->errorInfo[1] === 1054) { // Unknown column
				// Create missing columns
				foreach($this->_future_data as $field => $value) {
					if(!isset(ActiveRecord::$_columns_cache[$this->table][$field])) {
						Scaffold::create_field($this->table, $field);
						// Update columns cache after creating new field
						if (!isset(ActiveRecord::$_columns_cache[$this->table])) {
							ActiveRecord::$_columns_cache[$this->table] = [];
						}
						ActiveRecord::$_columns_cache[$this->table][$field] = true;
					}
				}
				// Retry the operation
				return $this->save();
			}
			throw new \Exception('Database error: ' . $e->getMessage());
		}
	}
	
	
	//Синоним id_or_insert_id
	public function ioi()
	{
		return $this->insert_id ? $this->insert_id : $this->id;
	}

	public function saveAll()
	{
		if ($this->queryReady === false) {
			$this->fetch_data_now();
		}

		if (empty($this->_data)) {
			throw new \Exception('Trying to update empty collection');
		}

		if (empty($this->_future_data)) {
			return $this; // Nothing to update
		}

		try {
			$attributes = [];
			foreach ($this->_future_data as $key => $value) {
				if (SQL_NULL === $value) {
					$attributes[] = ActiveRecord::DB_FIELD_DEL . $key . ActiveRecord::DB_FIELD_DEL . " = NULL";
				} else {
					$attributes[] = ActiveRecord::DB_FIELD_DEL . $key . ActiveRecord::DB_FIELD_DEL . " = " . ActiveRecord::$db->quote($value);
				}
			}

			// Собираем все ID объектов в коллекции
			$ids = array_map(function($obj) {
				return $obj->id;
			}, $this->_data);

			$query = 'UPDATE ' . ActiveRecord::DB_FIELD_DEL . $this->table . ActiveRecord::DB_FIELD_DEL
				. ' SET ' . implode(', ', $attributes)
				. ', ' . ActiveRecord::DB_FIELD_DEL . 'updated_at' . ActiveRecord::DB_FIELD_DEL . ' = NOW()'
				. ' WHERE ' . ActiveRecord::DB_FIELD_DEL . 'id' . ActiveRecord::DB_FIELD_DEL . ' IN (' . implode(',', $ids) . ')';

			ActiveRecord::$db->exec($query);

			// Clear caches
			$this->_data = [];
			$this->_get_by_id_cache = false;
			$this->_objects_cache = [];
			$this->queryReady = false;
			$this->_future_data = [];

			// Force reload from database
			$this->fetch_data_now();

			return $this;

		} catch (\PDOException $e) {
			if ($e->errorInfo[1] === 1054) { // Unknown column
				// Create missing columns
				foreach ($this->_future_data as $field => $value) {
					if (!isset(ActiveRecord::$_columns_cache[$this->table][$field])) {
						Scaffold::create_field($this->table, $field);
						// Update columns cache after creating new field
						if (!isset(ActiveRecord::$_columns_cache[$this->table])) {
							ActiveRecord::$_columns_cache[$this->table] = [];
						}
						ActiveRecord::$_columns_cache[$this->table][$field] = true;
					}
				}
				// Retry the operation
				return $this->saveAll();
			}
			throw new \Exception('Database error: ' . $e->getMessage());
		}
	}

}
