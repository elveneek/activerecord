<?php

namespace Elveneek;

use Elveneek\Query\MySqlGrammar;
class Scaffold
{
	public static function create_field($table,$field)
	{
        MySqlGrammar::assertIdentifier($table);
        MySqlGrammar::assertIdentifier($field);
		if (substr($field,-3)=='_id' || $field=='sort') {
			ActiveRecord::$db->exec("ALTER TABLE `".$table."` ADD COLUMN `$field` int NULL");
		} elseif (substr($field,0,3)=='is_') {
			ActiveRecord::$db->exec("ALTER TABLE `".$table."` ADD COLUMN `$field` tinyint(4) NOT NULL DEFAULT 0");
		} elseif (substr($field,-3)=='_at') {
			ActiveRecord::$db->exec("ALTER TABLE `".$table."` ADD COLUMN `$field` datetime NULL");
		} else {
			ActiveRecord::$db->exec("ALTER TABLE `".$table."` ADD COLUMN `$field` text NULL, DEFAULT CHARACTER SET=utf8");
		}
		
		//После выполнения скаффолда сервер перезагружается
	
	}
	public static function rename_column($table,$field,$new_name)
	{
        MySqlGrammar::assertIdentifier($table);
        MySqlGrammar::assertIdentifier($field);
        MySqlGrammar::assertIdentifier($new_name);
        $quotedNewName = MySqlGrammar::quoteIdentifier($new_name);
		if (substr($field,-3)=='_id' || $field=='sort') {
			ActiveRecord::$db->exec("ALTER TABLE `".$table."` CHANGE COLUMN `$field` " . $quotedNewName ." int NULL");
		} elseif (substr($field,0,3)=='is_') {
			ActiveRecord::$db->exec("ALTER TABLE `".$table."` CHANGE COLUMN `$field` " . $quotedNewName ." tinyint(4) NOT NULL DEFAULT 0");
		} elseif (substr($field,-3)=='_at') {
			ActiveRecord::$db->exec("ALTER TABLE `".$table."` CHANGE COLUMN `$field` " . $quotedNewName ." datetime NULL");
		} else {
			ActiveRecord::$db->exec("ALTER TABLE `".$table."` CHANGE COLUMN `$field` " . $quotedNewName ." text NULL, DEFAULT CHARACTER SET=utf8");
		}
		//После выполнения скаффолда сервер перезагружается
		
	}	
	public static function create_table($table,$one_element="")
	{
		if($one_element==''){
			$result = ActiveRecord::$db->exec("CREATE TABLE `".$table."` (
				`id`  int(11) NOT NULL AUTO_INCREMENT ,
				`title`  text CHARACTER SET utf8 COLLATE utf8_general_ci NULL ,
				`created_at`  datetime NULL,		
				`updated_at`  datetime NULL,		
				`sort`  int(11) NULL DEFAULT NULL ,
				PRIMARY KEY (`id`)
				)
				ENGINE=InnoDB
				DEFAULT CHARACTER SET=utf8 COLLATE=utf8_general_ci
				;");
		}else{
			$result = ActiveRecord::$db->exec("CREATE TABLE `".$table."` (
				`id`  int(11) NOT NULL AUTO_INCREMENT ,
				`title`  text CHARACTER SET utf8 COLLATE utf8_general_ci NULL ,
				`".$one_element."_id`  int(11) NULL DEFAULT NULL ,
				`created_at`  datetime NULL,		
				`updated_at`  datetime NULL,		
				`sort`  int(11) NULL DEFAULT NULL ,
				PRIMARY KEY (`id`)
				)
				ENGINE=InnoDB
				DEFAULT CHARACTER SET=utf8 COLLATE=utf8_general_ci
				;");		
		}

		if(strpos($table,'_to_')!==false){
			//Создаём дополнительные столбики
			$tablefields = explode('_to_', $table);
			foreach ($tablefields as $field){
				$field = ActiveRecord::plural_to_one($field).'_id';
				static::create_field($table,$field);
			}

		}
		//После выполнения скаффолда сервер перезагружается
		
		return $result;
	}
	 
}
