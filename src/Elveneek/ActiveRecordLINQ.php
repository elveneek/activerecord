<?php 
namespace Elveneek;
//Здесь собраны методы, которые работают с уже полученными данными
trait ActiveRecordLINQ {
	//FIXME - непонятно зачем нужная, недокументированная функция
	function arrange_by_groups($group_name='id')
	{
		
		return ;
		if ($this->queryReady===false) {
			$this->fetch_data_now();
		}
		
		
		$_tmparr=array();
		$_class_name = get_class($this);
		foreach($this->_data as $element){
			if(isset ($element[$group_name])){
				$key = $element[$group_name];
			} else {
				$key = '';
			}
			$_tmparr[$key][]=$element;
		}
		$result_arr=array();
		foreach($_tmparr as $key => $value){
			$result_arr[$key] = new  $_class_name (array('table'=>$this->_options['table'],'data'=>$value ));
		}
		  
		return $result_arr;
	}
	
	//FIXME: переписать на объекты. Функция сортирует результаты уже в пямяти
	function sort_by($column_name='id',$asc_or_desc=SORT_ASC)
	{
		return;
		if($asc_or_desc === 'asc' || $asc_or_desc==='ASC'){
			$asc_or_desc = SORT_ASC;
		}
		if($asc_or_desc === 'desc' || $asc_or_desc==='DESC'){
			$asc_or_desc = SORT_DESC;
		}
		if ($this->queryReady===false) {
			$this->fetch_data_now();
		}
		$column_values = array();
		foreach ($this->_data as $key => $row) {
			if(isset($row[$column_name])){
				$column_values[$key]  = $row[$column_name];
			}else{
				$column_values[$key]  = "";
			}
		}

		$data = $this->_data;
		array_multisort($column_values, $asc_or_desc, $data);
		$this->_data= $data;
		$this->_count = count($this->_data);
		return $this;
	}
	
	//FIXME: метод считается устаревшим
	function add_rows($data)
	{
		
		if ($this->queryReady===false) {
			$this->fetch_data_now();
		}
		
		$this->_data = array_merge($this->_data, $data);
		$this->_count = count($this->_data);
		return $this;
	}
	
	
	//FIXME: метод считается устаревшим
	//FIXME: закинуть всё что связано с работой существующих полученных данных в отдельный трейт вроде LINQ
	function arrange_by($group_name='id')
	{
		
		if ($this->queryReady===false) {
			$this->fetch_data_now();
		}
		return false;
		$_tmparr=array();
		$_class_name = get_class($this);
		foreach($this->_data as $element){
			if(isset ($element[$group_name])){
				$key = $element[$group_name];
			} else {
				$key = '';
			}
			$_tmparr[$key]=$element;
		}
		$result_arr=array();
		foreach($_tmparr as $key => $value){
			$result_arr[$key] = new  $_class_name (array('table'=>$this->_options['table'],'data'=>array($value) ));
		}
		  
		return $result_arr;
	}
	
	
}
