<?php 
namespace Elveneek;	
//Здесь собраны методы, которые отвечают за пагинацию
trait ActiveRecordPaginator {
 
 
	//FIXME: позже
	public function paginate($per_page=10,$current=false)
	{
		if($current===false){
			//Если в контроллере забыли передать это подразумевающееся понятие, поможем контроллеру
			if(isset($_GET['page'])){
				$current=(int)$_GET['page'];
			}else{
				$current=0;
			}
		}
		$this->calc_rows();
		$this->limit($current*$per_page,$per_page);
		$this->current_page=$current;
		$this->per_page=$per_page;
		return $this;
	}
	
	//FIXME: позже
	public function paginator($activeclass=false)
	{
		$paginator = d()->Paginator;
		if($activeclass!==false){
			$paginator->setActive($activeclass);
		}
		return $paginator->generate($this);
	}
	
	//FIXME: позже
	public function calc_rows()
	{
		$this->_options['calc_rows']=true;
		return $this;
	}
	
	
	//Количество строк в найденном запросе
	//TODO: что по поводу LIMIT?
	//FIXME: это нужно для пагинатора
	function found_rows()
	{
		if ($this->queryReady===false) {
			$this->fetch_data_now();
		}
		
		if($this->queryCalcRows) {
			return $this->_count_rows;
		} else {
			return count($this->_data);
		}	
	}
	
	
}
