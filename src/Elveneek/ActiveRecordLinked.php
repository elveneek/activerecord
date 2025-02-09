<?php 
namespace Elveneek;
//Здесь собраны методы, которые отвечают за linked связи, а также за вспомогательные методы, которые ими используются
trait ActiveRecordLinked {//DONE
	
	//Второстепенная функция, используется другими функциями
	function column_exists($column,$table){
 
		if(isset(ActiveRecord::$_columns_cache [$table])){
			$columns = ActiveRecord::$_columns_cache [$table]; //Двойной запрос одного IF быстрее чем вызов функции
		}else {
			$columns = $this->columns($table);
		}
		return $columns !== false && isset($columns[$column]);
			
	}
	/*
	//Кажется, не нужна
	//Второстепенная функция, используется другими функциями
	function table_exists($table ){ //FIXME: unused
 		if(isset(ActiveRecord::$_columns_cache [$table])){
			return false !== ActiveRecord::$_columns_cache [$table]; //Двойной запрос одного IF быстрее чем вызов функции
		}
		return false !== $this->columns($table);
	}
	*/

	function linked($tablename){

		//Случай первый: Catalog->_goods (many_to_one): дочерние
		 
		
		//Так как table_exists работает только для двух таблиц: $tablename и по названию текущего класса (зачем?..) то делаем ее раньше
		
		//category->_products, в таблице products ищем category_id
		$column_name = $this->pluralToOne .'_id'; //category_id
		
		if($this->column_exists($column_name, $tablename)){
			//Получаем массив идентификаторов
			$ids = $this->all_of('id');
			
			$_modelname=ActiveRecord::plural_to_one(strtolower($tablename));
			$_modelname = strtoupper($_modelname[0]).substr($_modelname,1);

			return (new $_modelname())-> where($column_name.' IN (?)', $ids);
		}
		
		//product->_categories
		
		
		if(isset(ActiveRecord::$_columns_cache [$tablename])){
			$target_table_exists =  false !== ActiveRecord::$_columns_cache [$tablename]; //Двойной запрос одного IF быстрее чем вызов функции
		}else{
			$target_table_exists =  false !== $this->columns($tablename);
		}
		
		if($target_table_exists){ //Проверяем что существует таблица categories
			$column_name = ActiveRecord::plural_to_one($tablename).'_id'; //category_id
			if($this->column_exists($column_name, $this->table)){
				//Получаем массив идентификаторов
				$ids = $this->all_of($column_name);
				//FIXME модели вида ProductCategory
				$_modelname=ActiveRecord::plural_to_one(strtolower($tablename));
				$_modelname = strtoupper($_modelname[0]).substr($_modelname,1);
				return (new $_modelname())->where('id IN (?)', $ids);
			}
		}
		//FIXME: убраны две ветки, когда мы ссылались на имена классов, а не имена таблиц.

		
		//Случай второй: Catalog->goods (many_to_many)



		//Случай непонятный
		return null;

	}
	
	
	function plus(int | array | ActiveRecord $elements = array())
	{
		$curr_array = $this->all_of('id');
		if (is_numeric($elements)) {
			$curr_array[] = $elements;
			return ActiveRecord::fromTable($this->table)->_where('id IN (?)', $curr_array);
		}
		if (is_object($elements)) {
			$elements = $elements->all_of('id');
			$curr_array = array_merge($curr_array, $elements);
			return ActiveRecord::fromTable($this->table)->_where('id IN (?)', $curr_array);
		}
		if (is_array($elements) && is_numeric($elements[0])) {
			$curr_array = array_merge($curr_array, $elements);
			return ActiveRecord::fromTable($this->table)->_where('id IN (?)', $curr_array);
		}
		if (is_array($elements) && isset($elements[0]) && is_numeric($elements[0]['id'])) {


			$result_array = array();
			foreach ($elements[0] as $value) {
				$result_array[] = $value['id'];
			}
			$curr_array = array_merge($curr_array, $result_array);
			return ActiveRecord::fromTable($this->table)->_where('id IN (?)', $curr_array);
		}
		return $this;
	}


	function all_linked($what)
	{

		$antireqursy = 0;
		$results = array();
		$current_step = $this;
		while (!$current_step->isEmpty && $antireqursy < 100){
			$results = array_merge($results, $current_step->all_of('id'));
			$current_step = $current_step->linked($what);
			$antireqursy++;
		}
		
		$_modelname=ActiveRecord::plural_to_one(strtolower($what));
		$_modelname = strtoupper($_modelname[0]).substr($_modelname,1);
		return (new $_modelname())->where('id IN (?)', $results);
	}
}
