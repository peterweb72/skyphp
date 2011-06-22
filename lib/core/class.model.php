<?

/**
	
	@class  model 
	@param	(mixed) id/ide or null
	@param	(string) aql/model_name/ or null

**/

class model implements ArrayAccess {

	const READ_ONLY = 'The site is currently in "read only" mode. Changes have not been saved. Try again later.';

	public $_aql = null; // store actual .aql file when found or input aql
	public $_aql_array = array(); // generated by $_aql
	public $_data = array(); // all stored data, corresponds to each of $_properties
	public $_do_set = false;
	public $_errors = array(); // return errors
	public $_id; // identifier set in loadDB if successsful
	public $_ignore = array(); // tables (array) , models (array) , subs (array)
	public $_objects = array(); // names of objects
	public $_model_name = null; // from classname
	public $_primary_table; // primary table name of the model. 
	public $_properties = array(); // array of fields generated by the model
	public $_required_fields = array(); // 'field' => 'Name'
	public $_return = array();

	protected $_abort_save = false; // if true, the save will return after_save() without saving.

	public function __construct($id = null, $aql = null, $do_set = false) {
		$this->_model_name = get_class($this);
		$this->getAql($aql)->makeProperties();
		if ($do_set || $_GET['refresh'] == 1) $this->_do_set = true;
		if ($id) $this->loadDB($id, $do_set);
		if (method_exists($this, 'construct')) $this->construct();
	} 

/**
	
	@function	__get
	@return		(mixed)
	@param		(string)

**/
	public function __get($name) {
		if (!$this->propertyExists($name)) {
		//	$model_name = ($this->_model_name != 'model')?$this->_model_name:$this->_primary_table;
		//	$this->_errors[] = "Property \"{$name}\" does not exist and cannot be called in the model: {$model_name}";
			return false;
		} else {
			return $this->_data[$name];
		}
	}

/**
	
	@function	__set
	@return		(model)
	@param		(string)
	@param		(mixed)

**/
	public function __set($name, $value) {
		if ($this->propertyExists($name) || preg_match('/_id(e)*?$/', $name)) {
			if (method_exists($this, 'set_'.$name)) {
				if ($this->{'set_'.$name}($value)) {
					$this->_data[$name] = $value;
				}
			} else {
				$this->_data[$name] = $value;
			}
		} else {
			$this->_errors[] = 'Property '.$name.' does not exist in this model.';
		}
		return $this;
	}

	public function abortSave() {
		$this->_abort_save = true;
	}

	public function addProperty() {
		$num_args = func_num_args();
		$args = func_get_args();
		for ($i = 0; $i < $num_args; $i++) {
			$this->_properties[$args[$i]] = true;
		}
		return $this;
	}


	public function addSubModel($args, $always = false) {
		if (!$args['property']) {
			$this->_errors[] = '<strong>model::addSubModel</strong> expects a <em>property</em> argument.';
		} 
		if (!aql::is_aql($args['aql'])) {
			$this->_errors[] = '<strong>model::addSubModel</strong> expects a <em>aql</em> argument.';
		}
		if (!$args['clause']) {
			$this->_errors[] = '<strong>model::addSubModel</strong> expects a <em>clause</em> argument.';
		} else if (!is_array($args['clause'])) {
			$this->_errors[] = '<strong>model::addSubModel</strong> expects the <em>clause</em> argument to be an array.';
		}

		if ($this->_errors) return $this;

		$this->addProperty($args['property']);
		$subquery = aql2array($args['aql']);
		$this->_aql_array[$this->_primary_table]['subqueries'][$args['property']] = $subquery;
		if ($this->_id || $always) $this->{$args['property']} = aql::select($args['aql'], $args['clause']);
		return $this;
	}


/**

	@function 	after_fail
	@return		(null)
	@param		(array) -- the save array

**/

	public function after_fail($arr = array()) {
		return array(
			'status' => 'Error',
			'errors' => $this->_errors,
			'data' => $this->dataToArray()
		);
	}

/**

	@function	after_save
	@return		(null)
	@param		(array) -- save array

**/

	public function after_save($arr = array()) {
		return array(
			'status' => 'OK',
			'data' => $this->dataToArray()
		) + $this->_return;
	}

/**

	@function 	dataToArray
	@return		(array)
	@param		(null)

**/

	public function dataToArray() {
		$return = array();
		if (!$arr) $arr = $this->_data;
		foreach ($arr as $k => $v) {
			if ($this->_objects[$k] === 'plural') {
				foreach ($v as $i => $o) {
					if (self::isModelClass($o)) $return[$k][$i] = $o->dataToArray();
				}
			} else if ($this->_objects[$k] && get_class($v) != 'ArrayObject') {
				$return[$k] = $v->dataToArray();
			} else if (is_object($v) && get_class($v) == 'ArrayObject') {
				$return[$k] = self::dataToArraySubQuery($v);
			} else {
				$return[$k] = $v;
			}
		}
		unset($arr);
		return $return;
	}

	public function dataToArraySubQuery($arr = array()) {
		$return = array();
		foreach ($arr as $k => $v) {
			if (is_object($v) && self::isModelClass($v)) {
				$return[$k] = $v->dataToArray();
			} elseif (is_object($v) && get_class($v) == 'ArrayObject') {
				$return[$k] = self::dataToArraySubQuery($v);
			} else {
				$return[$k] = $v;
			}
		}
		unset($arr);
		return $return;
	}

/**

	@function	delete
	@return		(array) either a success or fail status array
	@param		(null)

**/

	public function delete() {
		$p = reset($this->_aql_array);
		$table = $p['table'];
		if ($this->_errors) {
			return array(
				'status' => 'Error',
				'errors' => $this->_errors
			);
		}
		if ($this->_id) {
			if (aql::update($table, array('active' => 0), $this->_id)) {
				global $model_dependencies;
				// clears the memcache of stored objects of this identifier.
				if ($this->_model_name != 'model') {
					$mem_key = $this->_model_name.':loadDB:'.$this->_id;
					mem($mem_key, null);
					if (is_array($model_dependencies[$this->_primary_table])) {
						foreach ($model_dependencies[$this->_primary_table] as $m) {
							$tmp_key = $m.':loadDB:'.$this->_id;
							mem($tmp_key, null);
						}
					}
				}
				return array(
					'status' => 'OK'
				);
			} else {
				$this->_errors[] = 'Error Deleting.';
				return array(
					'status' => 'Error',
					'errors' => $this->_errors
				);
			}
		} else {
			$this->_errors[] = 'Identifier is not set, there is nothing to delete.';
			return array(
				'status' => 'Error',
				'errors' => $this->_errors
			);
		}
	}

/**

	@function 	failTransaction
	@return		(null)
	@param		(null)

**/
	public function failTransaction() {
		global $dbw;
		$dbw->FailTrans();
	}

/**

	@function	get
	@return		(model) of that name
	@param		(string)
	
**/

	public static function get($str = null, $id = null, $sub_do_set = false) {
		if (!is_string($str)) die('Model Error: You must specify a model name using model::get.');
		aql::include_class_by_name($str);
		if (class_exists($str)) {
			return new $str($id, null, $sub_do_set);
		} else {
			return new model($id, $str);
		}
	}

/**

	@function	getActualObjectName
	@return		(string)
	@param		(string)

**/

	public function getActualObjectName($str) {
		if (!$this->isObjectParam($str)) return null;
		foreach ($this->_aql_array as $table) {
			if ($table['objects'][$str]) {
				return $table['objects'][$str]['model'];
			}
		}
		return null;
	}

/**
	
	@function	getAql
	@return		(model)
	@param		(string) -- aql, or model name, or null

**/

	public function getAql($aql = null) {
		if (!$aql) {
			$this->_aql = aql::get_aql($this->_model_name);
		} else if (aql::is_aql($aql)) {
			$this->_aql = $aql;
		} else {
			$this->_model_name = $aql;
			$this->_aql = aql::get_aql($aql);
		}
		return $this;
	}

/**

	@function	getAqlArray
	@return		(array)
	@param		(null)

**/

	public function getAqlArray() {
		return $this->_aql_array;
	}

/**

	@function 	getModel
	@return		(string)
	@param		(null)

**/

	public function getModel() {
		return $this->_aql;
	}

/**
 
 	@function	getProperties
 	@return		(array)
 	@param		(null)

**/
	public function getProperties() {
		return array_keys($this->_properties);
	}

/**
 
 	@function	isModelClass
 	@return		(bool)
 	@param		(mixed) class instance

**/
	public static function isModelClass($class) {
		if (!is_object($class)) return false;
		if (get_class($class) == 'model') return true;
		$class = new ReflectionClass($class);
		$parent = $class->getParentClass();
		if ($parent->name == 'model') return true;
		else return false;
	}

/**

	@function	isObjectParam
	@return		(bool)
	@param		(string)

**/

	public function isObjectParam($str) {
		if (array_key_exists($str, $this->_objects)) return true;
		else return false;
	}

/**

	@function 	loadArray
	@return		(model)
	@param		(array) -- data array in the proper format

**/

	public function loadArray( $array) {
		if (is_array($array)) foreach ($array as $k => $v) {
			if ($this->propertyExists($k) || preg_match('/(_|\b)id(e)*?$/', $k)) {
				if ($this->isObjectParam($k)) { 
					$obj = $this->getActualObjectName($k);
					aql::include_class_by_name($obj);
					if ($this->_objects[$k] === 'plural') {
						foreach ($v as $key => $arr) {
							if (is_array($arr)) {
								if (class_exists($obj))
									$this->_data[$k][$key] = new $obj();
								else
									$this->_data[$k][$key] = new model(null, $obj);
								$this->_data[$k][$key]->loadArray($arr);
							} else {
								$this->_data[$k][$key] = $arr;
							}
						}
						$this->_data[$k] = new ArrayObject($this->_data[$k]);
					} else {
						if (is_array($v)) {
							if (class_exists($obj))
								$this->_data[$k] = new $obj();
							else
								$this->_data[$k] = new model(null, $obj);
							$this->_data[$k]->loadArray($v);
						} else {
							$this->_data[$k] = $v;
						}
					}
				} else if (is_array($v)) {
					$this->_data[$k] = $this->toArrayObject($v);
				} else {
					if (substr($k, -4) == '_ide') {
						$d = aql::get_decrypt_key($k);
						$decrypted = decrypt($v, $d);
						$field = substr($k, 0, -1);
						$this->_data[$field] = $decrypted;
						$this->_properties[$field] = true;
					}
					$this->_data[$k] = $v;
					if (!$this->propertyExists($k)) $this->_properties[$k] = true;
				}
			} 
		}
		return $this;
	}
/**
 
 	@function	loadDB
 	@return 	(model)
 	@param		(string) identifier

**/
	public function loadDB( $id , $do_set = false) {
		if (!is_numeric($id)) {
			$id = decrypt($id, $this->_primary_table);
		}
		if (is_numeric($id)) {
			$mem_key = $this->_model_name.':loadDB:'.$id;
			$reload_subs = false;
			if ($do_set || $this->_do_set || $_GET['refresh']) $do_set = true;
			if (!$do_set && $this->_model_name != 'model') {
				$o = mem($mem_key);
				if (!$o) {
					$o = aql::profile($this->_model_name, $id, true, $this->_aql, true);
					mem($mem_key, $o);
				} else {
					$reload_subs = true;
				}
			} else if ($do_set && $this->_model_name != 'model') {
				$o = aql::profile($this->_model_name, $id, true, $this->_aql, true);
				mem($mem_key, $o);
			} else {
				$o = aql::profile($this->_aql_array, $id, true, $this->_aql);
			}
			$rs = $o->_data;
			if (self::isModelClass($o) && is_array($rs)) {
				$this->_data = $rs;
				$this->_properties = $o->_properties;
				$this->_objects = $o->_objects;
				$this->_id = $id;
				if ($reload_subs) $this->reloadSubs();
			} else {
				$this->_errors[] = 'No data found for this identifier.';
			}
			return $this;
		} else {
			$this->_errors[] = 'AQL Model Error: identifier needs to be an integer or an IDE.';
			return $this;
		}
	}

/**

	@function 	loadIDs
	@return		(null)
	@param		(array) ids, used for save

**/

	public function loadIDs($ids = array()) {
		foreach ($ids as $k => $v) {
			if (!$this->_data[$k] && $this->propertyExists($k)) $this->_data[$k] = $v;
		}
	}

/**
 
 	@function	loadJSON
 	@return		(model)
 	@param		(string)

**/
	public function loadJSON($json) {
		$array = json_decode($json, true);
		if (is_array($array)) return $this->loadArray($array);
		$this->_errors[] = 'ERROR Loading JSON. JSON was not valid.';
		return $this;
	}



	public function makeAqlArray() {
		if ($this->_model_name == 'model' || !$this->_model_name) {
			$this->_aql_array = aql2array($this->_aql);
		} else {
			$this->_aql_array = aql2array::get($this->_model_name, $this->_aql);
		}
	}

/**

	@function 	makeFKArray
	@return		(array) 
	@param		(array)

	makes a foreign key array from the aql_array

**/
	public function makeFKArray($aql_array) {
		$fk = array();
		foreach ($aql_array as $k => $v) {
			if (is_array($v['fk'])) foreach ($v['fk'] as $f) {
				$fk[$f][] = $v['table']	;
			}
		}
		return $fk;
	}

/**

	@function 	makeSaveArray (recursive)
	@return		(array) save array
	@param		(array) data array
	@param		(array) aql_array

**/

	public function makeSaveArray($data_array, $aql_array) {
		$tmp = array();
		if (is_array($data_array)) foreach($data_array as $k => $d) {
			if (!is_object($d) && !$this->isObjectParam($k)) { // this query
				foreach ($aql_array as $table => $info) {
					if ($info['fields'][$k]) {
						$field_name = substr($info['fields'][$k], strpos($info['fields'][$k], '.') + 1);
						if ($tmp[$info['table']]['fields'][$field_name] != 'id') $tmp[$info['table']]['fields'][$field_name] = $d;
						else $tmp[$info['table']]['id'] = $d;
					} else if (substr($k, '-4') == '_ide') {
						$table_name = aql::get_decrypt_key($k);
						if ($info['table'] == $table_name) {
							$tmp[$info['table']]['id'] = decrypt($d, $info['table']);
						}
					} else if (substr($k, '-3') == '_id') {
						$table_name = explode('__', substr($k, 0, -3));
						$table_name = ($table_name[1]) ? $table_name[1] : $table_name[0];
						if ($info['table'] == $table_name && $d !== NULL) {
							$tmp[$info['table']]['id'] = $d;
						}
					}
				}
			} else if ($this->isObjectParam($k)) { // sub objects
				if ($this->_objects[$k] === 'plural') {
					foreach ($d as $i => $v) {
						$tmp['__objects__'][] = array('object' => get_class($v), 'data' => $v->_data);
					}
				} else {
					$tmp['__objects__'][] = array('object' => get_class($d), 'data' => $d->_data);
				}
			} else { // sub queries
				$d = $this->toArray($d);
				foreach ($aql_array as $table => $info) {
					if (is_array($info['subqueries'])) foreach($info['subqueries'] as $sub_k => $sub_v) {
						if ($k == $sub_k) {
							foreach ($d as $i => $s) {
								$tmp[$info['table']]['subs'][] = $this->makeSaveArray($s, $sub_v);
							}
							break;
						}
					}
				}
			}
		}
		// make sure that the array is in the correct order
		$fk = self::makeFKArray($aql_array);
		unset($aql_array); unset($data_array);
		return self::makeSaveArrayOrder($tmp, $fk);
	}

/**

	@function	makeSaveArrayOrder
	@return		(array) reordered by foreign keys
	@param		(array)	needs reordering
	@param		(array)	foreign keys

**/

	public function makeSaveArrayOrder($save_array, $fk) {
		$return_array = array();
		$first = array(); // prepends to return array
		foreach ($fk as $parent => $subs) {
			foreach ($subs as $dependent) {
				if ($save_array[$dependent]) {
					if (!array_key_exists($dependent, $fk)) {
						$return_array[$dependent] = $save_array[$dependent];
						unset($save_array[$dependent]);
					} else {
						$return_array = array($dependent => $save_array[$dependent]) + $return_array;
						unset($save_array[$dependent]);
					}
				}
			}
		}
		return $save_array + $return_array;
	}

	public function removeIgnores($save_array = array()) {
		if (!$this->_ignore) return $save_array;

		// remove tables
		if (is_array($this->_ignore['tables'])) {
			foreach ($this->_ignore['tables'] as $remove) {
				if (!array_key_exists($remove, $save_array)) continue;
				unset($save_array[$t]);
			}
		}

		// remove objects
		if (is_array($this->_ignore['objects']) && $save_array['__objects__']) {
			foreach ($this->_ignore['objects'] as $remove) {
				foreach ($save_array['__objects__'] as $k => $v) {
					if ($v['object'] == $remove) {
						unset($save_array['__objects__'][$k]);
					}
				}
			}
		}

		// remove subs
		if (is_array($this->_ignore['subs'])) {
			foreach ($this->_ignore['subs'] as $remove) {
				foreach ($save_array as $i => $k) {
					if (is_array($k['subs'])) foreach ($k['subs'] as $n => $sub) {
						if (array_key_exists($remove, $sub)) {
							unset($save_array[$i]['subs'][$n]);
						} // endif exists
					} // end subs
				} // end tables
			} // end removes
		}

		return $save_array;
	}

/**
 	
 	@function	makeProperties
 	@return		(null)
 	@param		(null)

**/
	public function makeProperties() {
		if ($this->_aql) {
			$this->makeAqlArray();
			$i = 0;
			foreach ($this->_aql_array as $table) {
				if ($i == 0) {
					$this->_primary_table = $table['table'];
					$this->addProperty($this->_primary_table.'_id');
				}
				$this->tableMakeProperties($table);
				$i++;
			}
			unset($i);
		} else {
			if (!is_ajax_request())
				die('AQL Error: <strong>'.$this->_model_name.'</strong> is not a valid model.');
			else {
				exit_json(array(
					'status' => 'Error',
					'data' => array(
						'AQL Error: <strong>'.$this->_model_name.'</strong> is not a valid model.'
					)
				));
			}
		}
	} // end makeParms

	public function offsetExists($offset) {
		return isset($this->_data[$offset]);
	}

	public function offsetGet($offset) {
		return (isset($this->_data[$offset])) ? $this->_data[$offset] : null;
	}

	public function offsetSet($offset, $value) {
		$this->$offset = $value;
	}

	public function offsetUnset($offset) {
		unset($this->_data[$offset]);
	}

/**

	@function 	reload
	@return		(null)
	@param		(array)

**/

	public function reload($save_array = null) {
		global $model_dependencies;
		$f = reset($this->_aql_array);
		$first = $f['table'];
		$id = $save_array[$first]['id'];
		if ($id || $this->_id) {
			$this->_id = ($id) ? $id : $this->_id;
			$this->loadDB($this->_id, true);
			// reloads models with the same primary table
			if ($this->_primary_table) {
				if (is_array($model_dependencies[$this->_primary_table])) {
					foreach ($model_dependencies[$this->_primary_table] as $m) {
						if ($m == $this->_model_name) continue;
						$o = model::get($m, $this->_id, true);
					}
				}
			}
		}
		if (method_exists($this, 'construct')) $this->construct();
	}

/**
	
	@function	reloadSubs
	@return		(null)

	for when using memcache, if there are sub objects, reload them to make sure they are up do date

**/

	public function reloadSubs() {
		foreach (array_keys($this->_objects) as $o) {
			if ($this->_objects[$o] === 'plural') {
				foreach ($this->_data[$o] as $k) {
					if (self::isModelClass($k)) {
						$k->_do_set = false;
						$k->loadDB($k->_id);
					}
				}
			} else if (self::isModelClass($this->_data[$o])) {
				$this->$o->_do_set = false;
				$this->$o->loadDB($this->$o->_id);
			}
		}
	}	

	public function removeProperty() {
		$num_args = func_num_args();
		$args = func_get_args();
		for ($i = 0; $i < $num_args; $i++) {
			unset($this->_properties[$args[$i]]);
		}
		return $this;
	}

/** 
	
	@function	save
	@return		(bool)
	@param		(null)

	has hooks each takes the save_array as param
		before_save
		after_save
		after_fail

**/

	public function save($inner = false) {
		global $dbw, $db_platform, $aql_error_email, $is_dev;
		if (!$dbw) $this->_errors[] = model::READ_ONLY;
		$this->validate();
		if (empty($this->_errors)) {
			if (!$this->_aql_array) $this->_errors[] = 'Cannot save model without an aql statement.';
			if (empty($this->_errors)) {
				$save_array = $this->makeSaveArray($this->_data, $this->_aql_array);
				if (!$save_array) {
					if (!$inner) $this->_errors[] = 'Error generating save array based on the model. There may be no data set.';
					else return;
				} 
				$save_array = $this->removeIgnores($save_array);
				if (empty($this->_errors)) {
					if ($this->_abort_save) {
						return $this->after_save($save_array);
					}
					$dbw->StartTrans();
					if (method_exists($this, 'before_save')) $save_array = $this->before_save($save_array);
					$save_array = $this->saveArray($save_array);
					$transaction_failed = $dbw->HasFailedTrans();
					$dbw->CompleteTrans();
					if ($transaction_failed) {
						if (!in_array('Save Failed.', $this->_errors)) {
							$this->_errors[] = 'Save Failed.';
							if ($is_dev) $this->_errors[] = 'Failure in model: '.$this->_model_name;
						}
						if (method_exists($this, 'after_fail')) 
							return $this->after_fail($save_array);
						return false;
					} else {
						if (method_exists($this, 'before_reload')) 
							$this->before_reload();
						$this->reload($save_array);
						if (method_exists($this, 'after_save')) 
							return $this->after_save($save_array);
					}
				}
			} 
		} 
		if (!empty($this->_errors)) {
			if (method_exists($this, 'after_fail')) 
				return $this->after_fail();
			return false;
		} 
	}

/**

	@function	saveArray (recursive)
	@return		(array)
	@param		(array)
	@param		(array)

**/

	public function saveArray($save_array, $ids = array()) {
		$objects = $save_array['__objects__'];
		unset($save_array['__objects__']);
		foreach ($save_array as $table => $info) {
			foreach ($ids as $n => $v) {
				if (is_array($info['fields']) && !$info['fields'][$n]) {
					$save_array[$table]['fields'][$n] = $v;
					$info['fields'][$n] = $v;
				}
			}
			if (is_numeric($info['id'])) {
				if (is_array($info['fields']) && $info['fields']) {
					$info['fields']['update_time'] = 'now()';
					if (PERSON_ID) {
						if (!$info['fields']['mod__person_id']) $info['fields']['mod__person_id'] = PERSON_ID;
						if (!$info['fields']['update__person_id']) $info['fields']['update__person_id'] = PERSON_ID;
					}
					aql::update($table, $info['fields'], $info['id'], true);
				}
			} else {
				if (is_array($info['fields']) && $info['fields']) {
					$rs = aql::insert($table, $info['fields'], true);
					if (PERSON_ID && !$info['fields']['insert__person_id']) $info['fields']['insert__person_id'] = PERSON_ID;
					$save_array[$table]['id'] = $info['id'] = $rs[0][$table.'_id'];
				}
			}
			$ids[$table.'_id'] = $info['id'];
			if (is_array($info['subs'])) foreach ($info['subs'] as $i=>$sub) {
				$save_array[$table]['subs'][$i] = $this->saveArray($sub, $ids);
			}
		}
		if (is_array($objects)) foreach ($objects as $o) {
			if ($o['data']) {
				$tmp = model::get($o['object']);
				$tmp->_data = $o['data'];
				$tmp->loadIDs($ids);
				$pt = $tmp->_primary_table;
				$pt_id = $pt.'_id';
				if (!$tmp->{$pt_id} && $this->$pt_id) {
					$tmp->$pt_id = $this->$pt_id;
				}
				$return = $tmp->save(true);
				if ($return['status'] != 'OK') {
					if (is_array($return['errors']))
						$this->_errors = $this->_errors + $return['errors'];
					// $this->_errors[] = "Error on model: '{$o['object']}'";
					$this->failTransaction();
				}
			}
		}
		$save_array['objects'] = $objects;
		return $save_array;
	}

/**
 
 	@function	tableMakeProperties
 	@return		(null)
 	@param		(array)
 	@param		(bool)

**/
	public function tableMakeProperties($table, $sub = null) {
		if (is_array($table['objects'])) foreach ($table['objects'] as $k => $v) {
			$this->_data[$k] =  new ArrayObject;
			$this->_properties[$k] = true;
			$this->_objects[$k] = ($v['plural']) ? 'plural' : true;
		}
		if (is_array($table['fields'])) foreach ($table['fields'] as $k => $v) {
			if (preg_match('/[\b_]id$/', $k)) {
				$this->_properties[$k.'e'] = true;
			}
			$this->_properties[$k] = true;
		}
		if (is_array($table['subqueries'])) foreach($table['subqueries'] as $k => $v) {
			$this->_data[$k] = new ArrayObject;
			$this->_properties[$k] = true;
		}
		$this->addProperty($table['table'].'_id');
	}

/**

	@function	toArray
	@return		(array)
	@param		(arrayObject)
**/

	public function toArray($obj) {
		if (is_object($obj) && get_class($obj) == 'ArrayObject') 
			$obj = $obj->getArrayCopy();

		if (is_array($obj)) foreach ($obj as $k => $v) {
			$obj[$k] = self::toArray($v);
		}
		return $obj;
	}

/**

	@function 	toArrayObject
	@return		(arrayObject)
	@param		(array)

**/

	public function toArrayObject($arr = array()) {
		$arr = new ArrayObject($arr);
		foreach ($arr as $k => $v) {
			if (is_array($v)) $arr[$k] = self::toArrayObject($v);
		}
		return $arr;
	}

/**
	
	@function	validate
	@return		(null)
	@param		(null)

**/

	public function validate() {
		if (method_exists($this, 'preValidate')) $this->preValidate();
		foreach (array_keys($this->_properties) as $prop) {
			$isset = true;
			if (array_key_exists($prop, $this->_required_fields)) {
				$n = ($this->_required_fields[$prop]) ? $this->_required_fields[$prop] : $prop;
				$isset = $this->requiredField($n, $this->{$prop}); 
			}
			if (method_exists($this, 'set_'.$prop) && $isset) {
				$this->{'set_'.$prop}($this->{$prop});
			}
		}
	}

/**

	@function	printData
	@return		(null)
	@param		(null)

**/

	public function printData() {
		print_pre($this->_data);
	}

/**
 
 	@function	printErrors
 	@return		(null)
 	@param		(null)

**/

	public function printErrors() {
		print_pre($this->_errors);
	}

/**

	@function	propertyExists
	@return		(bool)
	@param		(string)

**/

	public function propertyExists($p) {
		if (array_key_exists($p, $this->_properties)) return true;
		else return false;
	}

/**

	@function 	returnJSON
	@return		(string) 
	@param		(array)

	encodes the array and dies the content with JSON headers

**/

	public static function returnJSON($arr = array()) {
		return json_encode($arr);
	}

/**
	
	@function	returnDataArray
	@return		(array)
	@param		(null)

**/
	
	public function returnDataArray() {
		return $this->_data;
	}

/**
	
	@function 	requiredField
	@return		(bool)
	@param		(string)
	@param		(string)

**/

	public function requiredField($name, $val) {
		if (!$val) {
			$this->_errors[] = "{$name} is required.";
			return false;
		} else {
			return true;
		}
	}

/**
	@function validEmail
	@return   (bool)
	@param    (string)
	@param    (string)
**/

	public function validEmail($val) {
		$val = trim($val);
		if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
			$this->_errors[] = "{$val} is not a valid email address.";
			return false;
		}
		return true;
	}
}