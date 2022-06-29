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
	public function save()
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
					throw new Exception('Trying to update empty object. You can use save() only with object with one row.');
				}
				if(count($this->_data) >1 ){
					throw new Exception('Trying to update more than one row. Please use saveAll()');
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
		}catch  (PDOException $exception) {
			
		 
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
				throw new Exception('Wrong SQL query: '.$_query_string);
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
			//Перезагружаем новые воркеры
			App::$instance->rpc->call("http.Reset", true);
			//Считаем, что колонка создалась, кеш уже не актуален
			unset(ActiveRecord::$_columns_cache[$this->table]);
		}
		 
		  
		return $this;
	}
	
	

	//FIXME: dictionary убран (save_dictionary_array). При необходимости надо зако

	function save_connecton_array($id,$table,$rules){
		//Сохранение каждого из списка элементов. Если это не массив, сделать его таким
		foreach($rules as $key=>$data){
			if(!is_array($data)){
				if($data==''){
					$data = array();
				}else{
					$data=explode(',',$data);
				}
			}
			$second_table =substr($key,3);
			
			$first_field = to_o($table).'_id';
			$second_field = to_o($second_table).'_id';

			$many_to_many_table = $this->calc_many_to_many_table_name($table,$second_table);

			
			//0. проверяем наличие таблицы, при её отсуствии, создаём её
			if(false == $this->columns($many_to_many_table)){
				//таблицы many_to_many не существует  - создаем автоматически
				$one_element=to_o($many_to_many_table);
				d()->Scaffold->create_table($many_to_many_table,$one_element);
				
				d()->Scaffold->create_field($many_to_many_table,$second_field);
				d()->Scaffold->create_field($many_to_many_table,$first_field);
			}
			$columns_names=array_flip($this->columns($many_to_many_table));
			if(!isset($columns_names[$first_field])){
				d()->Scaffold->create_field($many_to_many_table,$first_field);
			}
			if(!isset($columns_names[$second_field])){
				d()->Scaffold->create_field($many_to_many_table,$second_field);
			}
			
			$original_data = $data;
			foreach($original_data as $key=>$value){
				if(is_array($value)){
					$data[$key] = $value[0];
				}
			}
			//1.удаляем существующие данные из таблицы
			if(count($data)>0){
				$_query_string='delete from '.ActiveRecord::DB_FIELD_DEL.''.$many_to_many_table . ActiveRecord::DB_FIELD_DEL." where ".ActiveRecord::DB_FIELD_DEL. $second_field .ActiveRecord::DB_FIELD_DEL." NOT IN (". implode(', ',$data) .") AND ".ActiveRecord::DB_FIELD_DEL. $first_field .ActiveRecord::DB_FIELD_DEL." =  ". e($id) . "";
			}else{
				$_query_string='delete from '.ActiveRecord::DB_FIELD_DEL.''.$many_to_many_table . ActiveRecord::DB_FIELD_DEL." where ".ActiveRecord::DB_FIELD_DEL. $first_field .ActiveRecord::DB_FIELD_DEL." =  ". e($id) . "";
			}
			doitClass::$instance->db->exec($_query_string);
			//2.добавляем нове записи в таблицу
			$exist = doitClass::$instance->db->query("SELECT ".ActiveRecord::DB_FIELD_DEL.''.$second_field . ActiveRecord::DB_FIELD_DEL." as cln FROM ".ActiveRecord::DB_FIELD_DEL.''.$many_to_many_table . ActiveRecord::DB_FIELD_DEL."  where ".ActiveRecord::DB_FIELD_DEL. $first_field .ActiveRecord::DB_FIELD_DEL." =  ". e($id) . "")->fetchAll(PDO::FETCH_COLUMN);
			$exist = array_flip($exist);

			foreach($original_data as $second_id){
				$additional_keys = '';
				$additional_values = '';
				$need_keys = array();
				$need_values = array();
				//В случае, если при записи to_users = array() передали массив массивов с дополнительными полями
				if(is_array($second_id)){
					if(count($second_id)>1){
						foreach ($second_id as $key=>$value){
							if(!is_numeric($key)){
								$need_keys[]=ActiveRecord::DB_FIELD_DEL . $key . ActiveRecord::DB_FIELD_DEL;
								if(SQL_NULL === $value){
									$need_values[]='NULL';
								}else{
									$need_values[]=e($value);
								}
								
							}
						}
						$additional_keys = ', ' . implode(', ',$need_keys);
						$additional_values = ', ' . implode(', ',$need_values);
					}
					$second_id = $second_id[0];
			
				}
				if(!isset($exist[$second_id])){
					$_query_string='insert into '.ActiveRecord::DB_FIELD_DEL. $many_to_many_table .ActiveRecord::DB_FIELD_DEL." (".ActiveRecord::DB_FIELD_DEL. $first_field .ActiveRecord::DB_FIELD_DEL.", ".ActiveRecord::DB_FIELD_DEL. $second_field .ActiveRecord::DB_FIELD_DEL." , ".ActiveRecord::DB_FIELD_DEL."created_at".ActiveRecord::DB_FIELD_DEL.",  ".ActiveRecord::DB_FIELD_DEL."updated_at".ActiveRecord::DB_FIELD_DEL . $additional_keys . ") values (". e($id) . ",". e( $second_id) . ", NOW(), NOW() " . $additional_values . " )";
					doitClass::$instance->db->exec($_query_string);
					$insert_id = doitClass::$instance->db->lastInsertId();
					$_query_string = 'update ' . ActiveRecord::DB_FIELD_DEL . $many_to_many_table . ActiveRecord::DB_FIELD_DEL . ' set ' . ActiveRecord::DB_FIELD_DEL . 'sort' . ActiveRecord::DB_FIELD_DEL . '=' . ActiveRecord::DB_FIELD_DEL . 'id' . ActiveRecord::DB_FIELD_DEL . ' where ' . ActiveRecord::DB_FIELD_DEL . 'id' . ActiveRecord::DB_FIELD_DEL . '=' . e($insert_id);
					doitClass::$instance->db->exec($_query_string);
				}
			}
		}
	}
	
	
	/**
	* Сохранение связей для запросов вида connected_friend_id_in_user_friends
	*/
	function save_connected_connecton_array($id,$table,$rules){
		//Сохранение каждого из списка элементов. Если это не массив, сделать его таким
		
		foreach($rules as $key=>$data){
			if(!is_array($data)){
				if($data==''){
					$data = array();
				}else{
					$data=explode(',',$data);
				}
			}
			$find_in = strpos($key, '_in_');
			if($find_in==false){
				print '<div style="padding:20px;border:1px solid red;background:white;color:black;">Запросы вида connected_fieid_in_table должны иметь обязательное указание на таблицу' ;
				if (iam('developer')) {
					print '<pre>Поле с ошибкой: ' . htmlspecialchars($key) . '</pre>';
				}
				print '</div>';
				exit;
			}
			
			
			$first_field = to_o($table).'_id';
			$many_to_many_tables = explode('_in_', $key);
			
			
			$second_field = substr($many_to_many_tables[0],10); 
			$many_to_many_table = $many_to_many_tables[1];//Вторая таблица, например, user_friends
 
			//0. проверяем наличие таблицы, при её отсуствии, создаём её
			if(false == $this->columns($many_to_many_table)){
				//таблицы many_to_many не существует  - создаем автоматически
				$one_element=to_o($many_to_many_table);
				d()->Scaffold->create_table($many_to_many_table,$one_element);
				
				d()->Scaffold->create_field($many_to_many_table,$second_field);
				d()->Scaffold->create_field($many_to_many_table,$first_field);
			}
			$columns_names=array_flip($this->columns($many_to_many_table));
			if(!isset($columns_names[$first_field])){
				d()->Scaffold->create_field($many_to_many_table,$first_field);
			}
			if(!isset($columns_names[$second_field])){
				d()->Scaffold->create_field($many_to_many_table,$second_field);
			}
			
			$original_data = $data;
			foreach($original_data as $key=>$value){
				if(is_array($value)){
					$data[$key] = $value[0];
				}
			}
			//1.удаляем существующие данные из таблицы
			if(count($data)>0){
				$_query_string='delete from '.ActiveRecord::DB_FIELD_DEL.''.$many_to_many_table . ActiveRecord::DB_FIELD_DEL." where ".ActiveRecord::DB_FIELD_DEL. $second_field .ActiveRecord::DB_FIELD_DEL." NOT IN (". implode(', ',$data) .") AND ".ActiveRecord::DB_FIELD_DEL. $first_field .ActiveRecord::DB_FIELD_DEL." =  ". e($id) . "";
			}else{
				$_query_string='delete from '.ActiveRecord::DB_FIELD_DEL.''.$many_to_many_table . ActiveRecord::DB_FIELD_DEL." where ".ActiveRecord::DB_FIELD_DEL. $first_field .ActiveRecord::DB_FIELD_DEL." =  ". e($id) . "";
			}
			doitClass::$instance->db->exec($_query_string);
			//2.добавляем нове записи в таблицу
			$exist = doitClass::$instance->db->query("SELECT ".ActiveRecord::DB_FIELD_DEL.''.$second_field . ActiveRecord::DB_FIELD_DEL." as cln FROM ".ActiveRecord::DB_FIELD_DEL.''.$many_to_many_table . ActiveRecord::DB_FIELD_DEL."  where ".ActiveRecord::DB_FIELD_DEL. $first_field .ActiveRecord::DB_FIELD_DEL." =  ". e($id) . "")->fetchAll(PDO::FETCH_COLUMN);
			$exist = array_flip($exist);

			foreach($original_data as $second_id){
				$additional_keys = '';
				$additional_values = '';
				$need_keys = array();
				$need_values = array();
				//В случае, если при записи to_users = array() передали массив массивов с дополнительными полями
				if(is_array($second_id)){
					if(count($second_id)>1){
						foreach ($second_id as $key=>$value){
							if(!is_numeric($key)){
								$need_keys[]=ActiveRecord::DB_FIELD_DEL . $key . ActiveRecord::DB_FIELD_DEL;
								if(SQL_NULL === $value){
									$need_values[]='NULL';
								}else{
									$need_values[]=e($value);
								}
								
							}
						}
						$additional_keys = ', ' . implode(', ',$need_keys);
						$additional_values = ', ' . implode(', ',$need_values);
					}
					$second_id = $second_id[0];
			
				}
				if(!isset($exist[$second_id])){
					$_query_string='insert into '.ActiveRecord::DB_FIELD_DEL. $many_to_many_table .ActiveRecord::DB_FIELD_DEL." (".ActiveRecord::DB_FIELD_DEL. $first_field .ActiveRecord::DB_FIELD_DEL.", ".ActiveRecord::DB_FIELD_DEL. $second_field .ActiveRecord::DB_FIELD_DEL." , ".ActiveRecord::DB_FIELD_DEL."created_at".ActiveRecord::DB_FIELD_DEL.",  ".ActiveRecord::DB_FIELD_DEL."updated_at".ActiveRecord::DB_FIELD_DEL . $additional_keys . ") values (". e($id) . ",". e( $second_id) . ", NOW(), NOW() " . $additional_values . " )";
					doitClass::$instance->db->exec($_query_string);
					$insert_id = doitClass::$instance->db->lastInsertId();
					$_query_string = 'update ' . ActiveRecord::DB_FIELD_DEL . $many_to_many_table . ActiveRecord::DB_FIELD_DEL . ' set ' . ActiveRecord::DB_FIELD_DEL . 'sort' . ActiveRecord::DB_FIELD_DEL . '=' . ActiveRecord::DB_FIELD_DEL . 'id' . ActiveRecord::DB_FIELD_DEL . ' where ' . ActiveRecord::DB_FIELD_DEL . 'id' . ActiveRecord::DB_FIELD_DEL . '=' . e($insert_id);
					doitClass::$instance->db->exec($_query_string);
				}
			}
		}
	}
	
	
	
	//Синоним id_or_insert_id
	public function ioi()
	{
		return $this->insert_id ? $this->insert_id : $this->id;
	}
	
	public function id_or_insert_id()
	{
		return $this->insert_id ? $this->insert_id : $this->id;
	}
	public function save_and_load()
	{
		$this->save();
		$class = get_class($this);
		$result = new $class;
		return $result->find($this->insert_id ? $this->insert_id : $this->id);
	}
	
}
