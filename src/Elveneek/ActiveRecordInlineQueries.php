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
	
    /**
     * Truncate the table associated with the calling model class.
     *
     * @param bool $areYouSure Must be true to proceed with the truncation.
     * @return void
     */
    public static function truncate($areYouSure = false)
    {
        if ($areYouSure !== true) {
            throw new \Exception('You must pass true to the $areYouSure parameter to truncate the table.');
        }

        $calledClass = get_called_class();
        if (substr($calledClass, -5) == '_safe') {
            $calledClass = substr($calledClass, 0, -5);
        }
        $table = self::one_to_plural(strtolower($calledClass));
        $query = "TRUNCATE TABLE " . self::DB_FIELD_DEL . $table . self::DB_FIELD_DEL;
        self::$db->exec($query);
    }
	
		//CRUD
	public function delete()
	{
		//FIXME: надо написать
		if ($this->queryReady===false) {
			$this->fetch_data_now();
		}
		if(isset($this->_data[0])){
			$_query_string='delete from '.ActiveRecord::DB_FIELD_DEL.''.$this->_options['table'] . ActiveRecord::DB_FIELD_DEL." where ".ActiveRecord::DB_FIELD_DEL."id".ActiveRecord::DB_FIELD_DEL." = '".$this->_data[0]['id']."'";
			self::$db->exec($_query_string);
		}
		ActiveRecord::$_queries_cache = array();
		return $this;
	}

	function only_count()
	{
		if ($this->queryReady===false) {
			$this->select('count(*) as _only_count');
			$this->fetch_data_now();
			if(empty($this->_data)){
				return 0;
			}
		}
		return $this->_data[0]->_only_count;
	}

	
}
