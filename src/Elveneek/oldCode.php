<?php

/*
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
	
	*/


	/**
	* Сохранение связей для запросов вида connected_friend_id_in_user_friends
	*/
	/*
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
	
	*/