<?php

/**
 * Database class
 * 
 * A simple database wrapper, inspired by ADOdb.
 */
class Database {

  private $host;
  private $username;
  private $password;
  private $database;
  private $port;

  /**
   * MySQL link identifier of open database connection
   * @var Resource
   */
  private $db = NULL;

  /**
   * Contains SQL of all queries run in this script.
   * @var array
   */
  public $queries = array();

  /**
   * Class constructor, which stores database's credentials.
   * 
   * @param string $host
   * @param string $username
   * @param string $password
   * @param string $database
   **/
  public function __construct($host, $username, $password, $database, $port = NULL) {
    $this->host = $host;
    $this->username = $username;
    $this->password = $password;
    $this->database = $database;
    $this->port = $port;
  }

  /**
   * Connects to database.
   */
  public function connect() {
    $host = $this->port === NULL ? $this->host : "{$this->host}:{$this->port}";
    $this->db = mysql_connect($host, $this->username, $this->password, TRUE) or trigger_error("Could not connect to '{$host}'", E_USER_ERROR);
    mysql_select_db($this->database, $this->db) or trigger_error('Could not select database: ' . mysql_error($this->db), E_USER_ERROR);
  }

  /**
   * Prepares and executes MySQL query, connecting to the database on the first
   * query.
   * 
   * @param string $sql SQL to execute
   * @param string|array|boolean $vars Values to escape and substitute into SQL
   * @return Resource|boolean Result set for some queries (SELECT, etc.),
   * TRUE/FALSE for others (INSERT, UPDATE, etc.)
   **/
  public function &query($sql, $vars = FALSE) {
    if ($this->db === NULL) {
      $this->connect();
    }
    if ($vars !== FALSE) {
      $sql = $this->prepare($sql, $vars);
    }
    $results = mysql_query($sql, $this->db) or trigger_error('Query failed: ' . mysql_error($this->db) . " (Query: $sql)", E_USER_WARNING);
    $this->queries[] = $sql;
    return $results;
  }

  /**
   * Prepares SQL by replacing question marks with escaped variable
   * substitutions.
   * 
   * <code>
   * $sql1 = $db->prepare("SELECT * FROM table WHERE id = ?", 13);
   * $sql2 = $db->prepare("SELECT * FROM table WHERE id = ? AND name = ?", array(13, 'John'));
   * $sql3 = $db->prepare("SELECT * FROM table WHERE id IN ? AND name = ?", array(array(1,2,3), 'John'));
   * </code>
   * 
   * @param string $sql SQL with question marks in place of values
   * @param string|array $vars Values to escape and substitute into SQL; if
   * $vars is an array and contains and array, the "IN" syntax will be used
   * @return string Substituted and escaped SQL
   **/
  public function prepare($sql, $vars) {
    if (strpos($sql, '?')) {
      $sqlParts = explode('?', $sql);
      settype($vars, 'array');
      if (count($sqlParts) != count($vars) + 1) {
        $array = !empty($vars) ? 'Input $vars array of '. print_r($vars, 1) : 'Empty $vars array';
        trigger_error("{$array} does not match ? in sql '{$sql}'", E_USER_ERROR);
      }
      $sql = '';
      for ($i = 0; $i < count($vars); $i++) {
        if (is_array($vars[$i])) {
          $escaped = array();
          foreach ($vars[$i] as $var) {
            $escaped[] = $this->escape($var);
          }
          $escaped = '('. implode(',', $escaped) .')';
        }
        else {
          $escaped = $this->escape($vars[$i]);
        }
        $sql .= $sqlParts[$i] . $escaped;
      }
      $sql .= $sqlParts[$i];
    }
    return $sql;
  }

  /**
   * Saves $data to a $table record, either inserting or updating.
   * 
   * This function helps avoid long SQL queries for a basic task - saving a
   * record to the database. It follows several conventions:
   * 
   * - If $primaryKey exists and is not empty in $data, and exists in $table,
   *   then update the record. Otherwise, insert the record.
   * - Only those $data keys which correspond to actual field names in $table
   *   will be saved.
   * - Any of $table's fields which are not in $data will remain untouched in
   *   the database record.
   * 
   * As a convenience, when a record is inserted, $data[$primaryKey] will be
   * populated with the record's id.
   * 
   * @param string $table Table in which to save record
   * @param array &$data Associated array (by reference) in which the keys
   * correspond to the $table's field names
   * @param string $where Conditions used when updating; defaults to
   * "id = $data[$primaryKey]" (escaped)
   * @param array $$primaryKeys Defaults to primary keys in table.
   * @return boolean Whether or not the save was successful
   **/
  public function save($table, &$data, $where = NULL, $primaryKeys = array()) {
    // Get column info about table.
    // @todo Cache this in static variable.
    $columns = $this->getAll("SHOW COLUMNS FROM `{$table}`");

    // Auto-determine primary keys (if not provided).
    if (empty($primaryKeys)) {
      foreach ($columns as $column) {
        if ($column['Key'] == 'PRI') {
          $primaryKeys[] = $column['Field'];
        }
      }
    }

    // Determine if inserting or updating.
    $updating = TRUE;
    $conditions = array();

    // If any primary key is not set or is empty, then we're inserting.
    foreach ($primaryKeys as $primaryKey) {
      if (!isset($data[$primaryKey]) || empty($data[$primaryKey])) {
        $updating = FALSE;
        break;
      }
    }

    // If all primary keys have values, check if row exists.
    if ($updating) {
      foreach ($primaryKeys as $primaryKey) {
        $conditions[] = "`{$primaryKey}` = " . $this->escape($data[$primaryKey]);
      }
      if (!$this->getOne("SELECT COUNT(*) FROM `{$table}` WHERE " . implode(' AND ', $conditions))) {
        $updating = FALSE;
      }
    }

    // Prepare data based on table's columns.
    $sqlData = $sqlFields = array();
    foreach ($columns as $column) {
      $field = $column['Field'];
      // If column exists in data.
      if (array_key_exists($field, $data)) {
        // Updating.
        if ($updating) {
          if (!in_array($field, $primaryKeys)) {
            $sqlData[] = "`{$field}` = " . $this->escape($data[$field]);
          }
        }
        // Inserting.
        else {
          $sqlFields[] = "`{$field}`";
          $sqlData[] = $this->escape($data[$field]);
        }
      }
    }

    // Generate sql.
    if ($updating) {
      if ($where === NULL) {
        foreach ($primaryKeys as $primaryKey) {
          $conditions[] = "`{$primaryKey}` = " . $this->escape($data[$primaryKey]);
        }
        $where = implode(' AND ', $conditions);
      }
      $sql = sprintf("UPDATE `%s` SET %s WHERE %s LIMIT 1", $table, implode(', ', $sqlData), $where);
    }
    else {
      $sql = sprintf("INSERT INTO `%s` (%s) VALUES (%s)", $table, implode(', ', $sqlFields), implode(', ', $sqlData));
    }

    // Execute sql.
    $success = $this->query($sql);

    // If successful, inserting new record, there is only one primary key, and
    // $data's $primaryKey is not set, set $data's $primaryKey.
    if ($success && !$updating && count($primaryKeys) == 1 && empty($data[$primaryKey])) {
      $data[$primaryKey] = mysql_insert_id($this->db);
    }

    return $success;
  }

  /**
   * Escapes a value to be used in a SQL query.
   * 
   * @param mixed $value Value to escape; booleans are returned as 1 or 0, NULL
   * values are returned NULL; everything else is escaped using
   * mysql_real_escape_string()
   * @param boolean $quote If TRUE, and if $value is a string, the escaped
   * value will be encapsulated in single quotes.
   * @return string
   **/
  public function escape($value, $quote = TRUE) {
    if ($this->db === NULL) {
      $this->connect();
    }
    if (is_bool($value)) {
      $value = $value ? 1 : 0;
    }
    else if ($value === NULL) {
      $value = 'NULL';
    }
    else if (!is_numeric($value) and !is_array($value)) {
      $value = mysql_real_escape_string($value, $this->db);
      if ($quote) {
        $value = "'". $value ."'";
      }
    }
    return $value;
  }

  /**
   * Returns $field's enum options into an array.
   * 
   * @param string $table
   * @param string $field
   * @return array
   **/
  public function enum($table, $field) {
    $info = $this->getRow("DESCRIBE `{$table}` `{$field}`");
    return explode(',', preg_replace('/enum\(([^\)]+)\)/', '$1', str_replace("'", '', $info['Type'])));
  }

  /**
   * Gets all query results.
   * 
   * @param string $sql SQL query
   * @param string|array|boolean $vars Values to escape and substitute into SQL
   * @return array Associative array
   */
  public function getAll($sql, $vars = FALSE) {
    if ($vars !== FALSE) {
      $sql = $this->prepare($sql, $vars);
    }
    $result =& $this->query($sql);
    $rows = array();
    while ($row = mysql_fetch_array($result, MYSQL_ASSOC)) {
      $rows[] = $row;
    }
    return $rows;
  }

  /**
   * Gets first row of query results.
   * 
   * "LIMIT 1" will be auto-appended to $sql, if not already included and $sql
   * is not a DESCRIBE query.
   * 
   * @param string $sql SQL query
   * @param string|array|boolean $vars Values to escape and substitute into SQL
   * @return array Associative array
   */
  public function getRow($sql, $vars = FALSE) {
    if (!strpos($sql, 'LIMIT 1') and substr($sql, 0, 8) != 'DESCRIBE') {
      $sql .= ' LIMIT 1';
    }
    if ($vars !== FALSE) {
      $sql = $this->prepare($sql, $vars);
    }
    $result =& $this->query($sql);
    return mysql_fetch_array($result, MYSQL_ASSOC);
  }

  /**
   * Gets first column of first row of query results.
   * 
   * "LIMIT 1" will be auto-appended to $sql, if not already included.
   * 
   * @param string $sql SQL query
   * @param string|array|boolean $vars Values to escape and substitute into SQL
   * @return mixed
   */
  public function getOne($sql, $vars = FALSE) {
    if (!strpos($sql, 'LIMIT 1')) {
      $sql .= ' LIMIT 1';
    }
    if ($vars !== FALSE) {
      $sql = $this->prepare($sql, $vars);
    }
    $result =& $this->query($sql);
    $row = mysql_fetch_array($result, MYSQL_NUM);
    return $row[0];
  }

  /**
   * Gets first column of all query results.
   * 
   * @param string $sql SQL query
   * @param string|array|boolean $vars Values to escape and substitute into SQL
   * @return array
   */
  public function getCol($sql, $vars = FALSE) {
    if ($vars !== FALSE) {
      $sql = $this->prepare($sql, $vars);
    }
    $result =& $this->query($sql);
    $rows = array();
    while ($row = mysql_fetch_array($result, MYSQL_NUM)) {
      $rows[] = $row[0];
    }
    return $rows;
  }

  /**
   * Generates a key => value list from query results.
   * 
   * This function will create an associative array where the first field is
   * the key, and the second field is the value. If $join is TRUE, remaining
   * fields will be an associative array. If it's a string, that string will
   * be used to join all remaining fields.
   * 
   * <code>
   * $list1 = $db->getList("SELECT id, name FROM users WHERE id < ?", 10);
   * $list2 = $db->getList("SELECT id, first_name, last_name FROM users", FALSE, ' ');
   * $list3 = $db->getList("SELECT id, first_name, last_name, email FROM users", FALSE, 'array');
   * </code>
   * 
   * @param string $sql SQL query
   * @param string|array|boolean $vars Values to escape and substitute into SQL.
   * @param string|boolean $join String with which to join additional
   * fields together.
   * @return array
   */
  public function getList($sql, $vars = FALSE, $join = FALSE) {
    if ($vars !== FALSE) {
      $sql = $this->prepare($sql, $vars);
    }
    $results =& $this->query($sql);
    $list = array();
    if ($join === TRUE) {
      while ($row = mysql_fetch_array($results, MYSQL_ASSOC)) {
        $list[reset($row)] = $row;
      }
    }
    else {
      while ($row = mysql_fetch_array($results, MYSQL_NUM)) {
        $value = is_string($join) ? implode($join, array_slice($row, 1)) : $row[1];
        $list[$row[0]] = $value;
      }
    }
    return $list;
  }

}

/**
 * Helper functions.
 */

function db_query($sql, $vars = FALSE) {
  global $db;
  return $db->query($sql, $vars);
}

function db_rows($sql, $vars = FALSE) {
  global $db;
  return $db->getAll($sql, $vars);
}

function db_row($sql, $vars = FALSE) {
  global $db;
  return $db->getRow($sql, $vars);
}

function db_col($sql, $vars = FALSE) {
  global $db;
  return $db->getCol($sql, $vars);
}

function db_list($sql, $vars = FALSE, $join = FALSE) {
  global $db;
  return $db->getList($sql, $vars, $join);
}

function db_one($sql, $vars = FALSE) {
  global $db;
  return $db->getOne($sql, $vars);
}

function db_escape($value, $quote = TRUE) {
  global $db;
  return $db->escape($value, $quote);
}

function db_save($table, &$data, $where = NULL, $primaryKey = NULL) {
  global $db;
  return $db->save($table, $data, $where, $primaryKey);
}

function db_enum($table, $field) {
  global $db;
  return $db->enum($table, $field);
}
