<?php 
namespace Elveneek;
//Здесь собраны методы, которые делают запрос и тут же возвращают результат
trait ActiveRecordInlineQueries {
 
	//Общее количество строк в таблице
	//FIXME: позже
	function all_rows_count()
	{
		//FIXME: а если упадёт?
		//FIXME: вынести всё вычисляемое в отдельный трейт
		$_count_result = ActiveRecord::$db->query("SELECT COUNT(*) as counting FROM ".$this->table)->fetch();
		return $_count_result->counting;
	}
	
	function truncate($are_you_sure=false)
	{
		if($are_you_sure===true){
			d()->db->exec('TRUNCATE TABLE ' . et($this->_options['table']));
		}else{
			die('Произошла непредвиденная ошибка. Использование truncate без подтверждения запрещено. Возможно, это ошибка.');
		}
	}
	
		//CRUD
	public function delete()
	{
		//FIXME: надо написать
		return;
		if (!$this->_options['queryready']) {
				$this->fetch_data_now();
		}
			
		if(isset($this->_data[0])){
			$_query_string='delete from '.ActiveRecord::DB_FIELD_DEL.''.$this->_options['table'] . ActiveRecord::DB_FIELD_DEL." where ".ActiveRecord::DB_FIELD_DEL."id".ActiveRecord::DB_FIELD_DEL." = '".$this->_data[0]['id']."'";
			doitClass::$instance->db->exec($_query_string);
		}
		ActiveRecord::$_queries_cache = array();
		return $this;
	}

	//ОН сука медленный
	function only_count()
	{
		if ($this->queryReady===false) {
			$this->select('count(*) as _only_count');
			$this->fetch_data_now();
			if(empty($this->_data)){
				return 0;
			}
		}
		return $this->_data[0]['_only_count'];
	}

	
}
