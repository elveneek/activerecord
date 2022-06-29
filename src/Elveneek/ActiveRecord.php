<?php

namespace Elveneek;

use PDOStatement;

define('SQL_NULL', 'CONST' . md5(time()) . 'MYSQL_NULL_CONST' . rand()); //FIXME однозначно. Например, ->where('category_id = ?', null), почему бы и нет В Yii2 устроено странно ->andWhere(['is', 'activated_at', new \yii\db\Expression('null')]) для положительных и ->andWhere(['not', ['activated_at' => null]]) для отрицательных, они не приемлят обычный null
//define ('ActiveRecord::DB_FIELD_DEL', '`');	//FIXME постргя не должна устанавливаться сменой константы. На крайний случай константа класса в конце концов или забить на поддержку постгри на данном этапе
//Класс Active Record, обеспечивающий простую добычу данных
abstract class ActiveRecord implements \ArrayAccess, \Iterator, \Countable //extends ArrayIterator
{
	public static $db;
	const NAMED_STATIC_FUNCTIONS = ['where' => true, 'stub' => true, 'w' => true]; //Эти функции доступны как статически, так и динамически. Предполагается, что для каждой из них есть соответсвующий динамический метод класса, начинающийся на "_"

	const DB_FIELD_DEL = '`';

	public static $_queries_cache = array();
	public static $_columns_cache = array();

	public $_data; //В этом массиве хранятся чистые данные, полученные из PDO, в виде объектов. Ключ - порядковый номер, начинается с 0.
	private $_get_by_id_cache = false;
	public $insert_id = false;
	private $_used_tree_branches;
	private $_future_data = array();
	public $current_page = 0;
	public $per_page = 10;
	private $_is_sliced = false;
	private $_revinded = 0;
	public $_count = 0;
	private $_slice_size = 5;
	private $_objects_cache = array();
	private $_safe_mode = false;
	
	
	
	private $_must_rewind = false;

	public static $curentTableColumns = false;
	public $table = "";
	public $queryConditions = [];
	public $queryConditionsParams = [];
	public $querySelect = '*';
	public $queryLimit = '';
	public $queryOrder = ' ORDER BY ' . ActiveRecord::DB_FIELD_DEL . 'sort' . ActiveRecord::DB_FIELD_DEL . ' ';
	public $queryGroupBy = '';
	public $queryNew = false;
	public $queryTree = false;
	public $queryCalcRows = false;
	public $queryReady = false;
	public $queryId = 0;

	public $queryDisablePrepare = false;
	public bool | PDOStatement $currentPDOStatement = false;

	public $_cursor = 0;
	public $fetchedCount = 0;
	public $isFetchedAll = 0;


	public $pluralToOne = "";


	use ActiveRecordLINQ;
	use ActiveRecordInlineQueries;
	use ActiveRecordPaginator;
	use ActiveRecordLinked;
	use ActiveRecordSave;

	public static $preparedStatements = [];

	public static function connect()
	{

		ActiveRecord::$preparedStatements = [];

		$dsn = 'mysql:host=' . $_ENV['DB_HOST'] . ';dbname=' . $_ENV['DB_NAME'];
		$opt = [
			\PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
			\PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_OBJ,
			\PDO::ATTR_EMULATE_PREPARES   => false,
		];
		if (isset($_ENV['DB_AUTO_RECONNECT']) && $_ENV['DB_AUTO_RECONNECT'] == 1) {
			$db = new PDOProxy($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $opt);
		} else {
			$db = new \PDO($dsn, $_ENV['DB_USER'], $_ENV['DB_PASSWORD'], $opt);
		}

		$db->exec('SET CHARACTER SET utf8');
		$db->exec('SET NAMES utf8'); //FIXME: возможно еще SET sql_mode = "";
		return $db;
	}

	//__construct() сильно похудел, но нужно похудеть еще сильнее!
	function __construct()
	{
		$_current_class = get_class($this);
		if (substr($_current_class, -5) == '_safe') { //FIXME: не факт что в админке это будет сохранено в будущем. Сейчас используется.
			$_current_class = substr($_current_class, 0, -5);
			$this->_safe_mode = true;
		}
		$this->table = self::one_to_plural(strtolower($_current_class));
		$this->pluralToOne = self::plural_to_one($this->table); //FIXME - должно же совпадать с именем класса, не? Может просто $this->pluralToOne= strtolower($_current_class). Будет быстрее.
	}



	static function plural_to_one($string)
	{
		$_p_to_o = array(
			'criteria' => 'criteria',
			'men' => 'man',
			'women' =>	'woman',
			'mice' => 'mouse',
			'konkurses' => 'konkurs',
			'teeth' =>	'tooth',
			'feet' => 'foot',
			'children' => 'child',
			'oxen' => 'ox',
			'geese' =>	'goose',
			'sheep' =>	'sheep',
			'deer' => 'deer',
			'swine' => 'swine',
			'news' => 'news'
		);
		$_arr_p = array(
			'/(^.*)xes$/' => '$1x',
			'/(^.*)ches$/' => '$1ch',
			'/(^.*)sses$/' => '$1ss',
			'/(^.*)quies$/' => '$1quy',
			'/(^.*)ies$/' => '$1y',
			'/(^.*)lves$/' => '$1lf',
			'/(^.*)rves$/' => '$1rf',
			'/(^.*)ves$/' => '$1fe',
			'/(^.*)men$/' => '$1man',
			'/(^.+)people$/' => '$1person',
			'/(^.*)statuses$/' => '$1status',
			'/(^.*)konkurses$/' => '$1konkurs',
			'/(^.*)diseases$/' => '$1disease',
			'/(^.*)responses$/' => '$1response',
			'/(^.*)ses$/' => '$1sis',
			'/(^.*)ta$/' => '$1tum',
			'/(^.*)ia$/' => '$1ium',
			'/(^.*)children$/' => '$1child',
			'/(^.*)focuses$/' => '$1focus',
			'/(^.*)s$/' => '$1'
		);

		//Слова - исключения
		if (isset($_p_to_o[$string])) {
			return $_p_to_o[$string];
		}

		//TODO: (.*s) -> $1;
		foreach ($_arr_p as $key => $value) {
			$new = preg_replace($key, $value, $string);
			if ($new != $string) {
				break;
			}
		}
		return $new;
	}

	static function one_to_plural($string)
	{
		$_o_to_p = array( //FIXME: сделать статическим массивом в ActiveRecord, включая в doit, return Должен добавлять исключение
			'criteria' => 'criteria',
			'man' => 'men',
			'pike' => 'pike',
			'woman' => 'women',
			'mouse' => 'mice',
			'tooth' => 'teeth',
			'konkurs' => 'konkurses',
			'foot' => 'feet',
			'child' => 'children',
			'ox' => 'oxen',
			'goose' => 'geese',
			'sheep' => 'sheep',
			'deer' => 'deer',
			'swine' => 'swine',
			'news' => 'news'
		);
		$_arr_p = array(
			'/(^.*)x$/' => '$1xes',
			'/(^.*)ch$/' => '$1ches',
			'/(^.*)ss$/' => '$1sses',
			'/(^.*)quy$/' => '$1quies',
			'/(^.*[bcdfghklmnpqrstvxz])y$/' => '$1ies',
			'/(^.*)fe$/' => '$1ves',
			'/(^.*)lf$/' => '$1lves',
			'/(^.*)rf$/' => '$1rves',
			'/(^.+)person$/' => '$1people',
			'/(^.*)status$/' => '$1statuses',
			'/(^.*)konkurs$/' => '$1konkurses',
			'/(^.*)disease$/' => '$1diseases',
			'/(^.*)man$/' => '$1men',
			'/(^.*)sis$/' => '$1ses',
			'/(^.*)tum$/' => '$1ta',
			'/(^.*)ium$/' => '$1ia',
			'/(^.*)response$/' => '$1responses',
			'/(^.*)child$/' => '$1children',
			'/(^.*)focus$/' => '$1focuses', //foci?
			'/(^.*)$/' => '$1s'
		);

		//Слова - исключения
		if (isset($_o_to_p[$string])) {
			return $_o_to_p[$string];
		}

		//TODO: (.*s) -> $1;
		foreach ($_arr_p as $key => $value) {
			$new = preg_replace($key, $value, $string);
			if ($new != $string) {
				break;
			}
		}
		return $new;
	}



	//Статические конструкторы
	//TODO: параметры должны быть аналогичны where (типа Product->all('id = ?', 12)), в этом случае ::all будет выполнять роль статического Product::where()
	public static function all()
	{
		return  new static();
	}



	//парадокс, но __call Больше не нужен; //FIXME: может удалить? см. all()
	function __call($name, $arguments) //DONE
	{

		if (isset(ActiveRecord::NAMED_STATIC_FUNCTIONS[$name])) {
			$this->{'_' . $name}(...$arguments);
			return $this;
		}



		return $this;
	}


	public static function __callStatic($name, $arguments)
	{
		if (isset(ActiveRecord::NAMED_STATIC_FUNCTIONS[$name])) {
			$object = new static;
			$object->{'_' . $name}(...$arguments);
			return $object;
		}
		//FIXME: При вызове Product::ramabambaharummamburum() должно чтото происходть. Пусть падает к херам
		throw new \Exception('так нельзя');
	}

	//Функция find указывает на то, что необходимо искать нечто по полю ID
	public static function find($id)
	{
		$object = new static;
		$object->queryId = (int)$id;
		$object->find_by('id', (int)$id);
		$object->limit(1);
		return $object;
	}


	//FIXME: всё таки надо подумать... Бывают, когда нужны не статические вызовы, например ActiveRecord::fromTable('products')->find(); поэтому пока создал вот такое
	public function _findOne($id)
	{
		$this->queryId = (int)$id;
		$this->find_by('id', (int)$id);
		$this->limit(1);
		return $this;
	}


	public   function find_by($by, $what)
	{

		//FIXME: быстрый кеш по столбцу в массиве (url:id)
		$this->queryReady = false;
		$this->queryOrder = ''; //чтобы не делать доп сортио
		$this->queryConditions = ["( " . ActiveRecord::DB_FIELD_DEL . $by . ActiveRecord::DB_FIELD_DEL . " = " . ActiveRecord::$db->quote($what) . " )"];
		return $this;
	}


	function clone($param = false)
	{
		if ($param !== false) {
			//FIXME: я не уверен, что clone_copy сейчас работает
			return $this->clone_copy($param);
		} else {
			return $this->clone_copy();
		}
	}


	public function sql($query)
	{
		//FIXME: переделать полностью!
		/*
		$this->_options['queryready']=true;
		$this->_data=doitClass::$instance->db->query($query)->fetchAll();
		$this->_count = count($this->_data);
		return $this;
		*/
	}

	public function slice($pieces = 2)
	{
		//FIXME зависит от родительского класса
		$this->_is_sliced = true;
		$this->_slice_size = $pieces + 2;
		return $this;
	}

	//FIXME: надо подумать. Тут было where_equal. Функция w() задумывается как ->w('category_id', 12), или ->add_filter('category_id', 12); Возможно, where будет так работать, если 2 параметра пришло, и в первом нет вопросика и пробелов
	public function _w($field, $value)
	{
		return $this->_where('`' .  str_replace(['"', "'", '\\', ' ', '.', '*', '/', '`', ')'], ['', '', '', '', '', '', '', '', ''], $field)  . '` = ?', $value);
	}

	function only($field)
	{
		return $this->where(ActiveRecord::DB_FIELD_DEL . 'is_' . $field . ActiveRecord::DB_FIELD_DEL . ' = 1');
	}
	//Функция принимает строку (которая пойдёт в условие) и массив дополнительных значений, которые пойдут как скобки
	public function _where(...$args)
	{

		//OLDTODO: переписать на preg_replace с исполльзованием последнего параметра
		$this->queryReady = false;
		//Проверяем, был ли массив
		foreach ($args as &$arg) {
			if (is_array($arg)) {
				//Если есть хотя бы один массив, то мы попадаем в режим схлопывания запроса
				$this->queryDisablePrepare = true;
				//Логика объединения массива способом номер один: простое склеивание


				$_conditions = explode('?', ' ' . $args[0] . ' ');
				$_condition = '';
				for ($i = 1; $i <= count($_conditions) - 1; $i++) {
					$param = $args[$i];
					if (is_array($param)) {
						//Пришёл WHERE IN (?)$array
						if (empty($param)) {
							$param = ' null ';
						} else {
							// $param - ждём строго массив
							foreach ($param as $key => $value) {
								$param[$key] = ActiveRecord::$db->quote($param[$key]);
							}
							$param = implode(", ", array_unique($param));
						}
					} else {
						$param = ActiveRecord::$db->quote($param);
					}
					$_condition .= $_conditions[$i - 1] . " " . $param . " ";
				}
				$_condition .= $_conditions[$i - 1];
				$this->queryConditions[] = '(' . $_condition . ')';
				return $this;
			}
		}

		//Режим сборки запроса для $prepared
		$this->queryConditions[] = '(' . $args[0] . ')';
		$this->queryConditionsParams[count($this->queryConditions) - 1] = array_slice($args, 1);
		return $this;
	}


	/**
	 * Указывает LIMIT для будущего SQL запроса, возвращает объект для дальнейшего использования
	 *
	 * @param $limit первый параметр в директиве LIMIT (количество или отступ от начала). Может быть строкой с запятой.
	 * @param $count второй параметр (необязательный) в дирекиве LIMIT (количество)
	 * @return ActiveRecord текущий экземплятр объекта
	 */
	public function limit($limit, $count = false) //DONE
	{
		$this->queryReady = false;
		if ($count !== false) {
			$limit = $limit . ', ' . $count;
		}
		if ($limit != '') {
			$this->queryLimit = ' LIMIT ' . $limit . ' ';
		} else {
			$this->queryLimit = '';
		}
		return $this;
	}


	public function order_by($order_by) //DONE
	{
		$this->queryReady = false;
		if (trim($order_by) != '') {
			$this->queryOrder = ' ORDER BY ' . $order_by . ' ';
		} else {
			$this->queryOrder = '';
		}
		return $this;
	}
	public function group_by($group_by) //DONE
	{
		$this->queryReady = false;
		if (trim($group_by) != '') {
			$this->queryGroupBy = ' GROUP BY ' . $group_by . ' ';
		} else {
			$this->queryGroupBy = '';
		}
		return $this;
	}

	public function select($select)
	{
		$this->queryReady = false;
		if (trim($select) != '') {
			$this->querySelect = $select;
		} else {
			$this->querySelect = ' * ';
		}
		return $this;
	}

	//FIXME: возможно вместо and_select("title") должно быть ->select( $this->select() . ', title' ), т.е. select() без параметров должен возвращать текущий querySelect для дальнейней модификации
	public function and_select($select)
	{
		$this->queryReady = false;
		$this->querySelect = $this->querySelect . ' , ' . $select;
		return $this;
	}



	//FIXME: переписать на where, а затем развернуть
	function search(...$args)
	{

		$this->queryReady = false;

		if (count($args) < 2) {
			return $this;
		}
		$this->queryDisablePrepare = true; //FIXME: пока работает как массив, выключая в принципе prepared queries

		$param = ActiveRecord::$db->quote('%' . $args[count($args) - 1] . '%');
		$_pieces = array();

		for ($i = 0; $i <= count($args) - 2; $i++) {
			$_pieces[] = " " . ActiveRecord::DB_FIELD_DEL . $args[$i] . ActiveRecord::DB_FIELD_DEL . " LIKE  " . $param . " ";
		}

		$this->queryConditions[] =  '(' . implode(' OR ', $_pieces)  . ')';
		return $this;
	}

	//если были массивы, то тут будет пустенько
	function prepared_params()
	{
	}
	//В связи с тем, что используются prepared queries, to_sql вернёт только первую часть. Вторая часть (массив) хранится в скомпилированном виде гдето ниже. т.е. кроме to_sql()
	function to_sql()
	{

		$_query_string = 'SELECT ';

		if ($this->queryCalcRows) { //FIXME: отказываемся от этого
			$_query_string .= ' SQL_CALC_FOUND_ROWS ';
		}
		$_query_string .= ' ' . $this->querySelect . ' FROM ' . ActiveRecord::DB_FIELD_DEL . '' . $this->table . '' . ActiveRecord::DB_FIELD_DEL . ' ';

		//В первую очередь надо схлопнуть запросы, если были массивы



		//FIXME: создать второй массив, в котором будут не условия а значения
		if (!empty($this->queryConditions)) {

			if ($this->queryDisablePrepare) {
				//Ветка с схопнутыми массивами
				//Буквально меняем массивы на месте
				//Берём ВСЁ что есть в $this->queryConditions, затем меняем вопросики

				foreach ($this->queryConditionsParams as $key => &$oneParamsArr) {

					//////
					$_conditions = explode('?', ' ' . $this->queryConditions[$key] . ' ');
					$_condition = '';
					for ($i = 0; $i <= count($_conditions) - 2; $i++) {
						$_condition .= $_conditions[$i] . ' ' . ActiveRecord::$db->quote($oneParamsArr[$i]) . ' ';
					}
					$_condition .= $_conditions[$i];
					$this->queryConditions[$key] = $_condition;
				}
			}
			// Если всё окей, то все условия просто соединяем
			$_condition = implode(' AND ', $this->queryConditions);
			$_query_string .= 'WHERE ' . $_condition;
		}

		if ($this->queryGroupBy != '') {
			$_query_string .=  ' ' . $this->queryGroupBy . ' ';
		}
		if ($this->queryOrder != '') {
			$_query_string .=  $this->queryOrder;
		}

		if ($this->queryLimit != '') {
			$_query_string .=  $this->queryLimit;
		}

		return $_query_string;
	}

	//FIXME: fetchDataNow ?...
	function fetch_data_now()
	{
		$this->queryReady = true;
		$this->_data = [];
		$this->_count = 0;
		$this->fetchedCount = 0;
		$this->isFetchedAll = false;
		$_sql = $this->to_sql();

		//Сначала смотрим в результаты кеша
		//FIXME: сделай локальный кеш

		//проверяем, делаем прямо сейчас или делаем prepare
		if ($this->queryDisablePrepare) {
			//делаем прямо сейчас
			$statement = ActiveRecord::$db->query($_sql . ' -- non prepared');


			//FIXME: проверка и реконнект
		} else {
			//Смотрим нужный запрос в кеше подготовленных запросов
			//ActiveRecord::$db->prepare($_sql, array(PDO::ATTR_CURSOR => PDO::CURSOR_FWDONLY));
			//FIXME: посмотреть настройки курсора
			$_sql = $_sql . ' -- prepared'; // комментарий для отладки
			if (isset(static::$preparedStatements[$_sql])) {
				$statement = static::$preparedStatements[$_sql];
			} else {
				$statement = ActiveRecord::$db->prepare($_sql);
				static::$preparedStatements[$_sql] = $statement;
			}




			$flatten = [];
			foreach ($this->queryConditionsParams as $array) {
				$flatten = array_merge($flatten, $array);
			}

			try {
				$statement->execute($flatten);
			} catch (\PDOException $exception) {
				if ($exception->getCode() === 'HY000' && $exception->errorInfo[1] === 2006) {

					//Переподключаемся и очищаем массив prepared statements
					ActiveRecord::$db = ActiveRecord::connect();

					//Повторно создаем текущий statement и помещаем в массив
					$statement = ActiveRecord::$db->prepare($_sql);
					static::$preparedStatements[$_sql] = $statement;

					//Выполняем
					$statement->execute($flatten);
				} else {
					throw $exception;
				}
			}
		}
		//throw new PDOException('Херня случилась.'.json_encode($statement));
		$this->currentPDOStatement = $statement;

		//Дёргаем одну строку
		$row = $this->currentPDOStatement->fetch();
		if ($row === false) {
			$this->fetchedCount = 0;
			$this->_count = 0;
			$this->isFetchedAll = true; //Мы молодцы, можем делать not_empty
		} else {
			$this->fetchedCount = 1;  //Мы молодцы, можем делать not_empty
			$this->_data[] = $row;  //Объекты передаются по ссылке, поэтому можем перекидывать и копировать.
		}

		//Получаем список колонок текущей таблицы:
		if (isset(ActiveRecord::$_columns_cache[$this->table])) {
			$columns = ActiveRecord::$_columns_cache[$this->table];
		} else {

			if ($this->querySelect == '*') { //Если мы пытались получить все столбцы
				$_res = $this->currentPDOStatement;
			} else {
				try {
					$_res = ActiveRecord::$db->query('SELECT * FROM ' . ActiveRecord::DB_FIELD_DEL . $this->table . ActiveRecord::DB_FIELD_DEL . ' LIMIT 0', \PDO::ERRMODE_SILENT);
				} catch (\PDOException $exception) {
					$_res = false; //FIXME: а можно без Exception?...
				}
			}
			if ($_res !== false) {
				$columns  = array();
				$columns_count =  $_res->columnCount();
				for ($i = 0; $i <= $columns_count - 1; $i++) {
					$column = $_res->getColumnMeta($i);
					$columns[$column['name']] = true;
				}
				ActiveRecord::$_columns_cache[$this->table] = $columns;
			} else {
				ActiveRecord::$_columns_cache[$this->table] = false;
				$columns = false;
			}
		}
		static::$curentTableColumns = $columns;


		return;
		//FIXME: count Должен быть ленивым
		//FIXME: здесь был пересчет known columns
		//FIXME: здесь был пересчет calc_rows для пагинатора
		//FIXME: здесь должны быть проверки на ошибки, в случае необходимости предложить админу создать столбец.

	}




	//Итератор

	//Для получаения count мы вынуждаем систему скачать все данные до конца.
	function count(): int //DONE
	{
		if ($this->queryReady === false) {
			$this->fetch_data_now();
		}
		if ($this->isFetchedAll === true) {
			return $this->_count;
		}
		while ($row = $this->currentPDOStatement->fetch()) {
			$this->fetchedCount++;
			$this->_data[] = $row;
		}
		$this->isFetchedAll = true;
		$this->_count = count($this->_data);
		return $this->_count;
	}


	function current(): mixed //DONE
	{
		return $this;
	}

	//не делаем fetch, пока не понадобились данные. Если курсор обгорит fetch на несколько шагов, fetch будет сделан несколько раз. До тех пор он ленивый.
	function next(): void //DONE
	{

		if (!$this->_is_sliced) {
			$this->_cursor++;
			return;
		}
		if (!$this->_is_sliced) {
			$this->_cursor++;
			return;
		}
		if (!$this->_must_rewind) {
			$this->_cursor++;
		}
	}

	function valid(): bool //DONE
	{

		if ($this->queryReady === false) {
			$this->fetch_data_now();
		}
		if (!$this->_is_sliced) {
			//Если не включен режим слайса, то конец происходит в двух случаях:
			//1. Всё уже скачано ранее ($this->isFetchedAll == true) и при этом курсор < количества. Например, количество = 3, курсор = 3 (0, 1, 2 уже были)
			//2. Если ещё не открыт текущий fetch по тем или иным причинам, делаем fetch. Если не получилось, то false. В противном случае (курсор гдето в начале) всё валидно
			if ($this->isFetchedAll === true) {
				return $this->_cursor < $this->_count;
			}

			while ($this->fetchedCount <= $this->_cursor) {
				//Например, получено 2 (были 0 и 1), а текущий курсор = 5, мы должны получить 2, 3, 4, 5
				//Например, получено 3 (были 0, 1, 2), а текущий курсор = 2, мы не должны ничего делать
				//Например, получено 3 (были 0, 1, 2), а текущий курсор = 3, мы должны скачать одну строчку
				//Например, получено 3 (были 0, 1, 2), а текущий курсор = 4, мы должны скачать две строчки
				if ($row = $this->currentPDOStatement->fetch()) {
					//FIXME: переподключение?
					$this->fetchedCount++;
					$this->_data[] = $row;
				} else {
					//не получилось скачать очередную строчку. Это означает, что мы достигли конца
					$this->_count = count($this->_data);
					$this->isFetchedAll = true; //Мы молодцы, можем делать not_empty
					return false;
				}
			}
			return true;
		}

		//Вот тут смотрим, обогнал ли курсор то количество данных, которое нам надо получить.
		//Если курсор указывает на дальше, чем скачано на данный момент, то скачиваем
		if ($this->isFetchedAll === true && $this->_cursor >= $this->_count) {
			return false;
		}
		$this->_revinded++;
		if ($this->_revinded % $this->_slice_size == 0) {
			$this->_must_rewind = true;
			return false;
		}
		return true;
	}

	function key(): mixed
	{
		return $this->_cursor;
	}

	function rewind(): void //DONE
	{

		if (!$this->_is_sliced) {
			$this->_cursor = 0;
			return;
		}

		if ($this->_must_rewind) {
			$this->_must_rewind = false;
		} else {
			$this->_cursor = 0;
		}
	}

	function offsetGet(mixed $index): mixed //DONE
	{
		if (is_numeric($index)) {
			$this->_cursor = $index;
			return $this;
		} else {
			return $this->{$index};
		}
	}

	function offsetExists(mixed $offset): bool
	{
		if ($this->queryReady === false) {
			$this->fetch_data_now();
		} //FIXME: а если фетч не прошёл?...
		return isset($this->_data[$this->_cursor]);
	}


	function offsetSet(mixed $offset, mixed $value): void
	{
		if (is_null($offset)) {
			//ничего пока не делать
		} else {
			$this->{$offset} = $value;
		}
	}
	function seek($position) //FIXME: если прыгаем через 30 строк в будущее, нужно сделать 30 запросов к базе данных
	{
		$this->_cursor = $position;
		while ($this->fetchedCount <= $this->_cursor) {
			if ($row = $this->currentPDOStatement->fetch()) {
				//FIXME: переподключение?
				$this->fetchedCount++;
				$this->_data[] = $row;
			} else {
				//не получилось скачать очередную строчку. Это означает, что мы достигли конца
				$this->_count = count($this->_data);
				$this->isFetchedAll = true; //Мы молодцы, можем делать not_empty
				return $this;
			}
		}
	}
	function offsetUnset(mixed $offset): void
	{
		//unset($this->_data[$this->_cursor]);
		//Я этого делать не буду. Пока. //FIXME, наверное $user->name = "вася"; unset($user->name); должен отменять присваивание. А в случае поиска и изменении - записывать в базу null;. Надо подумать про числовые и буквенные ансеты.
	}

	//Получение одного поля

	function __get($name)
	{


		//Item.something
		if (method_exists($this, $name)) {
			return $this->{$name}();
		} //Item.something

		if ($name == 'stub') {
			return $this->{$name}();
		}

		//Item.ml_title
		//FIXME: ненужно пока
		/*
		if (substr($name, 0, 3) == 'ml_') {
			$lang = doitClass::$instance->lang;
			if ($lang != '') {
				return $this->{$lang.substr($name,2)};
            }
		}
		*/

		if ($name[0] == '_') {
			//FIXME: перенести linked прямо сюда
			return $this->linked(substr($name, 1));
		}

		/*
		//FIXME: убрали to_*. Возможно, оно и нужно? Или нет? Наверное надо вынести в отдельный метод для чисто кода, типа $user->to('products')
		if (substr($name,0,3)=='to_') {
			if ($this->queryReady===false) {
				$this->fetch_data_now(); //FIXME: только айдишники=(
			}

			$target_table = substr($name,3);
			if($target_table > $this->$table){
				$many_to_many_table = $this->$table.'_to_'.$target_table;
			}else{
				$many_to_many_table = $target_table.'_to_'.$this->$table;
			}

			$column = ActiveRecord::plural_to_one(strtolower(substr($name,3))).'_id';

			if(isset($this->_data[0])){
				//d()->bad_table = et($many_to_many_table ); //FIXME: другие способы определять ошибку
				$result = ActiveRecord::$db->query(
					"SELECT " . ActiveRecord::DB_FIELD_DEL . $column . ActiveRecord::DB_FIELD_DEL .
					" FROM ". ($many_to_many_table ).  //FIXME: fetch() ???
					" WHERE ". ActiveRecord::DB_FIELD_DEL . $this->pluralToOne . "_id". ActiveRecord::DB_FIELD_DEL ." = ". (int) $this->_data[$this->_cursor]->id //FIXME: может ли id null=>0?
				)->fetchAll(PDO::FETCH_COLUMN);
				//FIXME: переподключение
				// et() не нужен, потому что ['"',"'",'\\',' ','.','*','/','`',')'] не может быть при вызове через стрелочку
				return implode(',', $result);
			}else{
				return '';
			}

		}
		*/

		$as_substr = strpos($name, '_as_');
		if ($as_substr !== false) {
			$first_word = substr($name, 0, $as_substr);
			$second_word = 'as_' . substr($name, $as_substr + 4);
			return $second_word($this->{$first_word}, $first_word, $this); //FIXME: call_user_func?... //FIXME: if($first_word[0]>= 'A' && <='Z' ) { $first_word::call()?... (Типа это сервис объект) }?...
		}


		return $this->get($name, true);
	}


	//Функция получает имя таблицы, и возвращает массив столбцов. Если таблицы не существует, она возвращает false
	//FIXME: функция должна быть статической. Вернее везде где её дергают - дёргаться статически. В идеале сам активрекорд не дёргает для своих нужд, просто содержимое функции есть тут и там, благо мест очень мало. Вроде как сейчас эта функция уже не используется
	public function columns($tablename)
	{
		/*
		//$tablename - обязательное поле. Хочешь колонки - передай
		if($tablename=='') {
			$tablename = $this->_options['table'];
		}
		*/
		if (isset(ActiveRecord::$_columns_cache[$tablename])) {
			return ActiveRecord::$_columns_cache[$tablename];
		}


		try {
			$_res = ActiveRecord::$db->query('SELECT * FROM ' . ActiveRecord::DB_FIELD_DEL . $tablename . ActiveRecord::DB_FIELD_DEL . ' LIMIT 0', \PDO::ERRMODE_SILENT);
		} catch (\PDOException $exception) {
			$_res = false; //FIXME: а можно без Exception?...
		}

		if ($_res !== false) {
			$columns  = array();
			$columns_count =  $_res->columnCount();
			for ($i = 0; $i <= $columns_count - 1; $i++) {
				$column = $_res->getColumnMeta($i);
				$columns[$column['name']] = true;
			}
			ActiveRecord::$_columns_cache[$tablename] = $columns;
			return $columns;
		}
		ActiveRecord::$_columns_cache[$tablename] = false;
		return false;
	}


	/**
	 * Возвращает ключ массива (курсор, для обращения как к элементу массива), по id объекта
	 *
	 * @param $id ID Объекта
	 * @return int искомый ключ
	 */
	function get_cursor_key_by_id($id)
	{

		if ($this->queryReady === false) {
			$this->fetch_data_now();
		}
		//Мы опять вынуждены получить все данные заранее.
		if (!$this->isFetchedAll) {
			while ($row = $this->currentPDOStatement->fetch()) {
				$this->fetchedCount++;
				$this->_data[] = $row;
			}
			$this->isFetchedAll = true;
			$this->_count = count($this->_data);
		}
		if ($this->_get_by_id_cache === false) {
			$this->_get_by_id_cache = array();
			foreach ($this->_data as $k => $value) {
				$this->_get_by_id_cache[$value->id] = $k;
			}
			if (isset($this->_get_by_id_cache[$id])) {
				return $this->_get_by_id_cache[$id];
			}
		} else {
			if (isset($this->_get_by_id_cache[$id])) {
				return $this->_get_by_id_cache[$id];
			}
		}

		return false;
	}

	//FIXME: get не должен дёргать что попало (category->get('products')). Он должен быть супербыстрым, больше от него ничего не требуется. То есть если используем get, то получаем только чистые значения столбцов из базы. В Doit он делал всю магию, но игнорил определенные программистом методы класса, вместо function products() выполнялось поведение по умолчанию. Админка обращается к ->get() для вывода значений полей в редактировании
	public function get($name, $mutilang = false)
	{
		if ($this->queryReady === false) {
			$this->fetch_data_now();
		}

		if (isset($this->_future_data[$this->_cursor][$name])) {
			return $this->_future_data[$this->_cursor][$name];
		}

		//FIXME: а что делать с мультиязычностью?... подумаем потом. Пока закомментировано
		/*
		if($mutilang && doitClass::$instance->lang != '' && doitClass::$instance->lang!=''){
			if (isset($this->_data[$this->_cursor]) && isset($this->_data[$this->_cursor][doitClass::$instance->lang.'_'.$name]) && $this->_data[$this->_cursor][doitClass::$instance->lang.'_'.$name]!='') {
				return $this->get(doitClass::$instance->lang.'_'.$name);
			}
		}
		*/

		if (isset($this->_data[$this->_cursor])) {
			//Item.title         //Получение одного свойства
			if (property_exists($this->_data[$this->_cursor], $name)) {
				/*

				//FIXME: добавить админские иконки. Как вариант (правильный) это должно быть чтото вроде явного {.text|with_plugins} а не вот это вот всё на каждый чих
				if(isset($this->_data[$this->_cursor]['admin_options']) &&  ($this->_data[$this->_cursor]['admin_options']!='') && $this->_safe_mode === false  ){
					$admin_options = unserialize( $this->_data[$this->_cursor]['admin_options']);

					if(isset($admin_options[$name])){
						return preg_replace_callback(
							'/\<img\ssrc=\"\/cms\/external\/tiny_mce\/plugins\/mymodules\/module\.php\?([\@\-\_0-9a-zA-Z\&]+)\=([\-\_0-9a-zA-Z\&]+)\".[^\>]*\>/',
							create_function(

								'$matches',
								'if(isset(d()->plugins[str_replace("@","#",$matches[1])])){return d()->call(str_replace("@","#",$matches[1]),array($matches[2]));};return "";'
							),
							$this->_data[$this->_cursor][$name]
						);


					}
				}*/

				return $this->_data[$this->_cursor]->$name;
			}


			//Если в текущей таблице есть колонка $name (но нет в результатах ответа) - возвращаем ""
			if (isset(static::$curentTableColumns[$name])) {
				return '';
			}

			//Item.user          //Получение связанного объекта
			if (isset(static::$curentTableColumns[$name . '_id'])) {
				//$this->_objects_cache - список собранных вещей, для решения проблемы N+1, например для foreach products as product; product->category
				if (!isset($this->_objects_cache[$name])) {
					//Чтобы сделать такой запрос, мы вынуждены получить данные до конца.
					if (!$this->isFetchedAll) {
						while ($row = $this->currentPDOStatement->fetch()) {
							$this->fetchedCount++;
							$this->_data[] = $row;
						}
						$this->isFetchedAll = true;
						$this->_count = count($this->_data);
					}

					$ids_array = array();
					foreach ($this->_data as $key => $value) {
						if (!empty($value->{$name . '_id'})) {
							$ids_array[$value->{$name . '_id'}] = true;
						}
					}
					$ids_array = array_keys($ids_array);
					//Создаём временную модель
					$_modelname = strtoupper($name[0]) . substr($name, 1);
					$this->_objects_cache[$name] = (new $_modelname())->all()->order('')->where(' ' . ActiveRecord::DB_FIELD_DEL . 'id' . ActiveRecord::DB_FIELD_DEL . ' IN (?)', $ids_array); //FIXME:!! можно инициировать без вызова же: сделать ActiveRecord::all_by_ids()
				}
				$cursor_key = $this->_objects_cache[$name]->get_cursor_key_by_id($this->_data[$this->_cursor]->{$name . '_id'});
				if ($cursor_key === false) {
					$trash = clone ($this->_objects_cache[$name]);
					return $trash->limit('0')->where('false');
				}
				return $this->_objects_cache[$name][$cursor_key];
			}

			/*
			if (strpos($name,' ')!==false) { //FIXME: Вспомнить бы зачем.... Наверное костыль для админки
				return '';
			}
			*/

			//Item.users
			//Вместо вызова функции columns на месте вставлен код этого метода
			if (isset(ActiveRecord::$_columns_cache[$name])) {
				$columns = ActiveRecord::$_columns_cache[$name];
			} else {
				try {
					$_res = ActiveRecord::$db->query('SELECT * FROM ' . ActiveRecord::DB_FIELD_DEL . $name . ActiveRecord::DB_FIELD_DEL . ' LIMIT 0', \PDO::ERRMODE_SILENT);
				} catch (\PDOException $exception) {
					$_res = false; //FIXME: а можно без Exception?...
				}

				if ($_res !== false) {
					$columns  = array();
					$columns_count =  $_res->columnCount();
					for ($i = 0; $i <= $columns_count - 1; $i++) {
						$column = $_res->getColumnMeta($i);
						$columns[$column['name']] = true;
					}
					ActiveRecord::$_columns_cache[$name] = $columns;
				} else {
					ActiveRecord::$_columns_cache[$name] = false;
					$columns = false;
				}
			}





			//Проверка на возможность показать category->products. Если в таблице $name есть колонка table_id, то создаем новый экземпляр
			if ($columns !== false && isset($columns[$this->pluralToOne . "_id"])) { //если таблица $name существует и там есть колонка table_id...
				//FIXME: должно быть необязательно дергать данные из фактически базы данных, если мы запрашиваем поле типа product->categories
				return ActiveRecord::fromTable($name)->where($this->pluralToOne . "_id = ?", $this->_data[$this->_cursor]->id);

				//FIXME: это последний вызов ActiveRecord::fromTable, стоит тоже переделать. Особенно тут.
			}
			return '';
		} else {
			//Item.ramambaharum_mambu_rum
			return '';
		}
		return '';
	}




	public function all_of($field) //DONE
	{

		if ($this->queryReady === false) {
			$this->fetch_data_now();
		}

		//Получаем все данные до конца.
		if (!$this->isFetchedAll) {
			while ($row = $this->currentPDOStatement->fetch()) {
				$this->fetchedCount++;
				$this->_data[] = $row;
			}
			$this->isFetchedAll = true;
			$this->_count = count($this->_data);
		}

		//Заполняем массив с результатами
		$result_array = array();
		foreach ($this->_data as $value) {
			$result_array[] = $value->$field;
		}
		return $result_array;
	}

	public function isEmpty(): bool //DONE
	{
		if ($this->queryReady === false) {
			$this->fetch_data_now();
		}
		if (isset($this->_data[0])) {
			return false;
		}
		return true;
	}

	public function isNotEmpty(): bool
	{
		if ($this->queryReady === false) {
			$this->fetch_data_now();
		}
		if (isset($this->_data[0])) {
			return true;
		}
		return false;
	}

	public function ne(): bool
	{
		if ($this->queryReady === false) {
			$this->fetch_data_now();
		}
		if (isset($this->_data[0])) {
			return true;
		}
		return false;
	}


	function plus(int | array | ActiveRecord $elements = array())
	{
		$to_add = array();
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


	function _stub()
	{
		$this->queryConditions = ['( false )'];
		$this->queryReady = false;
		$this->_data = [];
		$this->_count = 0;
		return $this;
	}


	//Создает модель по имени таблицы. Предполагается, что соответсвующий класс существует.
	static function fromTable($_tablename, $suffix = '')
	{
		//FIXME: Обратить внимание, какой нибудь ProductsCombustor.php в редких случаях работать не будет, так как будет искать файл Productsсombustor.php в линуксе
		$_modelname = ActiveRecord::plural_to_one(strtolower($_tablename));
		$_modelname = strtoupper($_modelname[0]) . substr($_modelname, 1) . $suffix;

		return new $_modelname();
	}



	///HERE >>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>> МЫ СЕЙЧАС ТУТ <<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<<




	//Рекурсивная функция для быстрой сортировки дерева
	private function get_subtree($id)
	{
		$_tmparr = array();
		$_class_name = get_class($this);
		foreach ($this->_data as $element) {
			if (isset($element[$this->_options['plural_to_one'] . "_id"]) && $element[$this->_options['plural_to_one'] . "_id"] == $id) {
				if (empty($this->_used_tree_branches[$element['id']])) {
					$this->_used_tree_branches[$element['id']] = true;
					$_tmparr[] = new  $_class_name(array('table' => $this->_options['table'], 'data' => array($element), 'tree' => $this->get_subtree($element['id'])));
				}
			}
		}
		return $_tmparr;
	}

	public function tree($root = false)
	{
		//Если ленивый запрос ещё не произошёл - самое время.
		if ($this->_options['queryready'] == false) {
			$this->fetch_data_now();
		}

		//Если при создании объекта заранее указали его дерево - возвращаем его
		if ($this->_options['tree'] !== false) {
			return $this->_options['tree'];
		}
		$_tmparr = array();
		$_class_name = get_class($this);
		if (is_object($root)) {
			$root = $root->id;
		}
		$this->_used_tree_branches = array();

		if ($root === false) {
			foreach ($this->_data as $element) {
				//Если данный элемент корневой, родительских элементов нет, поле element_id пустое
				if (!isset($element[$this->_options['plural_to_one'] . "_id"])) {
					//В опцию tree записываем рекурсивно полученные дочерние элементы
					if (empty($this->_used_tree_branches[$element['id']])) {
						$this->_used_tree_branches[$element['id']] = true;
						$_tmparr[] = new  $_class_name(array('table' => $this->_options['table'], 'data' => array($element), 'tree' => $this->get_subtree($element['id'])));
					}
				}
			}
		} else {

			foreach ($this->_data as $element) {
				//Если данный элемент корневой, родительских элементов нет, поле element_id == root
				if (isset($element[$this->_options['plural_to_one'] . "_id"]) && ($element[$this->_options['plural_to_one'] . "_id"] == $root)) {
					//В опцию tree записываем рекурсивно полученные дочерние элементы
					if (empty($this->_used_tree_branches[$element['id']])) {
						$this->_used_tree_branches[$element['id']] = true;
						$_tmparr[] = new  $_class_name(array('table' => $this->_options['table'], 'data' => array($element), 'tree' => $this->get_subtree($element['id'])));
					}
				}
			}
		}

		$this->_used_tree_branches = array();
		return $_tmparr;
	}



	function to_array()
	{
		if ($this->_options['queryready'] == false) {
			$this->fetch_data_now();
		}
		foreach ($this->_data as $key => &$value) {
			$value['table'] = $this->_options['table'];
		}
		return $this->_data;
	}

	function to_json()
	{
		return json_encode($this->to_array);
	}
	function to_json_by_id($pretty = false)
	{
		$result = array();
		foreach ($this->to_array as $value) {
			$result[$value['id']] = $value;
		}
		if ($pretty !== false) {
			return json_encode($result, $pretty);
		}
		return json_encode($result);
	}








	//$this->pluralToOne = self::plural_to_one($this->table);

	function by_id($id)
	{
		return $this[$this->get_cursor_key_by_id($id)];
	}



	/*

	//По идее функция больше не нужна, и не используется.
	public static function calc_many_to_many_table_name($table1, $table2): string
	{
		if ($table1 > $table2) {
			return $table2 . '_to_' . $table1;
		}
		return $table1 . '_to_' . $table2;
	}

*/
}
