<?php 
namespace Elveneek;
class PDOProxy extends \PDO {


	public function prepare(string $statement, array $driver_options = []):  \PDOStatement|false
	{
		try {
			return parent::prepare($statement, $driver_options);
		} catch  (\PDOException $exception) {
			if($exception->getCode() == 'HY000' && $exception->errorInfo[1]==2006){
				ActiveRecord::$db = ActiveRecord::connect();
				return ActiveRecord::$db->prepare($statement, $driver_options);
			}
			throw $exception;
		}
	}
	/*
	public function query($statement, $fetch_style=false, $classname=false, $ctorargs=false)
	{
		try {
			if($fetch_style===false){
				$result = parent::query($statement);
			}elseif($classname===false){
				$result = parent::query($statement, $fetch_style);
			}elseif($classname===false){
				$result = parent::query($statement, $fetch_style, $classname);
			}else{
				$result = parent::query($statement, $fetch_style, $classname, $ctorargs);
			}
		}catch  (PDOException $exception) {
			if($exception->getCode() === 'HY000' && $exception->errorInfo[1]===2006){
				ActiveRecord::$db = ActiveRecord::connect();
				return call_user_func_array(array(ActiveRecord::$db, 'query'), func_get_args());
			}
			throw $exception;
		}

		return($result);
	}
	*/

    /*
     * Ниже устройство метода именно такое, из-за особеннстей PHP8:
     * "Fatal error: Declaration of PDOProxy::query($statement, $fetch_style = false, $classname = false, $ctorargs = false)
     * must be compatible with PDO::query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs)
     * in C:\\elveneek\\vendor\\elveneek\\framework\\core\\orm\\PDOProxy.php on line 42"}
     * */



	public function query($statement, ?int $fetchMode = null, ...$fetch_mode_args): \PDOStatement|false
	{
		try {
			if($fetchMode===null){
				$result = parent::query($statement);
			} else{
				$result = parent::query($statement, $fetchMode,  ...$fetch_mode_args);
			}
		}catch  (\PDOException $exception) {
			if($exception->getCode() === 'HY000' && $exception->errorInfo[1]===2006){
				ActiveRecord::$db = ActiveRecord::connect();
				return call_user_func_array(array(ActiveRecord::$db, 'query'), func_get_args());
			}
			throw $exception;
		}
		
		return($result);
	}
	
	
	
	public function exec(string $statement):int|false
	{
		try {
			return parent::exec($statement);
		} catch  (\PDOException $exception) {
			if($exception->getCode() == 'HY000' && $exception->errorInfo[1]==2006){
				ActiveRecord::$db = ActiveRecord::connect();
				return ActiveRecord::$db->exec($statement);
			}
			throw $exception;
		}
	} 
}