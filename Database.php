<?php

/**
 * Database class
 *
 * A simple database wrapper, inspired by ADOdb.
 */
class Database {

  /**
   * Database connection credentials.
   */
  private $host;
  private $username;
  private $password;
  private $database;
  private $port;

  /**
   * MySQL link identifier of open database connection.
   * @var Resource.
   */
  private $db = NULL;

  /**
   * Log of all executed queries.
   * @var array
   */
  public $queries = array();

  /**
   * Tracks nested transaction handling.
   */
  private $transaction = FALSE;

  /**
   * Class constructor, which stores connection credentials.
   *
   * @param string $host
   * @param string $username
   * @param string $password
   * @param string $database
   * @param int $port
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
    $this->db = mysqli_connect($host, $this->username, $this->password, $this->database) or trigger_error("Could not connect to '{$host}'.", E_USER_ERROR);
  }

  /**
   * Executes query.
   *
   * @param string $query The query string.
   * @param mixed $vars Values to inject into $query.
   *
   * @return mixed Result set for some queries (SELECT, etc.), TRUE/FALSE for
   * others (INSERT, UPDATE, etc.)
   **/
  public function &query($query, $vars = array()) {
    // Connect if not already connected.
    if ($this->db === NULL) {
      $this->connect();
    }
    // Inject query vars.
    $query = $this->replace($query, $vars);
    // Execute query.
    $results = mysqli_query($this->db, $query) or trigger_error('Query failed: ' . mysqli_error($this->db) . " (Query: $query)", E_USER_WARNING);
    // Log the query.
    $this->queries[] = $query;
    return $results;
  }

  /**
   * Safely injects query replacements.
   *
   * Query replacements can be named:
   *
   * <code>
   * $query1 = $db->replace("SELECT * FROM users WHERE id = :id", array(':id' => 13));
   * $query2 = $db->replace("SELECT * FROM users WHERE name = :name AND type IN :type", array(
   *   ':name' => 'John',
   *   ':type' => array('active', 'blocked'),
   * ));
   * </code>
   *
   * Or, they can be sequential question marks:
   *
   * <code>
   * $query1 = $db->replace("SELECT * FROM table WHERE id = ?", 13);
   * $query2 = $db->replace("SELECT * FROM table WHERE name = ? AND type IN ?", array(
   *   'John',
   *   array('active', 'blocked'),
   * ));
   * </code>
   *
   * @param string $query The query string.
   * @param mixed $vars Values to escape and inject into $query.
   *
   * @return string Query with injected replacements.
   **/
  public function replace($query, $vars = array()) {
    // Skip if no vars were sent.
    if (empty($vars)) {
      return $query;
    }
    // Named substitutions.
    if (strpos($query, ':')) {
      $query = strtr($query, array_map(array($this, 'escape'), $vars));
    }
    // Sequential question-mark substitutions.
    else if (strpos($query, '?')) {
      $queryParts = explode('?', $query);
      settype($vars, 'array');
      if (count($queryParts) != count($vars) + 1) {
        $array = !empty($vars) ? 'Input $vars array of ' . print_r($vars, 1) : 'Empty $vars array';
        trigger_error("{$array} does not match number of ?'s in query '{$query}'", E_USER_ERROR);
      }
      $query = '';
      for ($i = 0; $i < count($vars); $i++) {
        $query .= $queryParts[$i] . $this->escape($vars[$i]);
      }
      $query .= $queryParts[$i];
    }
    return $query;
  }

  /**
   * Saves $record to a $table record, either inserting or updating.
   *
   * This function helps avoid writing long queries for a common task - saving
   * a record to the database. It follows several conventions:
   *
   * - If each $primaryKeys exists and is not empty in $record, and exists in
   *   $table, then it updates the record. Otherwise, it inserts the record.
   * - Only those $record keys which correspond to actual column names in
   *   $table will be saved.
   * - Any of $table's columns which are not in $record will remain untouched
   *   in the database record.
   *
   * As a convenience, when a record is inserted, each of the table's primary
   * keys will be populated into the corresponding $record->$primaryKey.
   *
   * @param $table
   *   A string containing the table in which to save the record.
   * @param &$record
   *   An object or array representing the record to write, passed in by
   *   reference, in which the keys correspond to the $table's column names.
   * @param $where
   *   A string containing conditions used when updating; defaults to
   *   "id = $data[$primaryKey]" (for each primary key).
   * @param $primaryKeys
   *   An array containing the primary keys. Defaults to primary keys in table.
   *
   * @return boolean TRUE if save was successful, FALSE otherwise.
   **/
  public function save($table, &$record, $where = NULL, $primaryKeys = array()) {
    static $columns = array();

    // Convert to object.
    $object = (object) $record;

    // Get column info about table.
    if (!isset($columns[$table])) {
      $columns[$table] = $this->getAll("SHOW COLUMNS FROM `{$table}`");
    }

    // Auto-determine primary keys (if not provided).
    if (empty($primaryKeys)) {
      foreach ($columns[$table] as $column) {
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
      if (!isset($object->$primaryKey) || empty($object->$primaryKey)) {
        $updating = FALSE;
        break;
      }
    }

    // If all primary keys have values, check if row exists.
    if ($updating) {
      foreach ($primaryKeys as $primaryKey) {
        $conditions[] = "`{$primaryKey}` = " . $this->escape($object->$primaryKey);
      }
      if (!$this->getOne("SELECT COUNT(*) FROM `{$table}` WHERE " . implode(' AND ', $conditions))) {
        $updating = FALSE;
      }
    }

    // Prepare object based on table's columns.
    $queryData = $queryFields = array();
    foreach ($columns[$table] as $column) {
      $field = $column['Field'];
      // If column exists in object.
      if (property_exists($object, $field)) {
        // Updating.
        if ($updating) {
          if (!in_array($field, $primaryKeys)) {
            $queryData[] = "`{$field}` = " . $this->escape($object->$field);
          }
        }
        // Inserting.
        else {
          $queryFields[] = "`{$field}`";
          $queryData[] = $this->escape($object->$field);
        }
      }
    }

    // Build query.
    if ($updating) {
      if ($where === NULL) {
        foreach ($primaryKeys as $primaryKey) {
          $conditions[] = "`{$primaryKey}` = " . $this->escape($object->$primaryKey);
        }
        $where = implode(' AND ', $conditions);
      }
      $query = sprintf("UPDATE `%s` SET %s WHERE %s LIMIT 1", $table, implode(', ', $queryData), $where);
    }
    else {
      $query = sprintf("INSERT INTO `%s` (%s) VALUES (%s)", $table, implode(', ', $queryFields), implode(', ', $queryData));
    }

    // Execute query.
    $success = $this->query($query);

    // If successful, inserting a new record, there is only one primary key,
    // and $object's $primaryKey is not set, then set $object's $primaryKey.
    if ($success && !$updating && count($primaryKeys) == 1 && empty($object->$primaryKey)) {
      $object->$primaryKey = mysqli_insert_id($this->db);
    }

    // If we began with an array, convert back.
    if (is_array($record)) {
      $record = (array) $object;
    }

    return $success;
  }

  /**
   * Escapes a value to be injected into a query.
   *
   * @param mixed $value Value to escape.
   * @param boolean $quote Unless FALSE, if $value is a string, the escaped
   * value will be encapsulated in this value, or single quotes if TRUE.
   *
   * @return string The escaped value.
   **/
  public function escape($value, $quote = TRUE) {
    // Connect if not already connected.
    if ($this->db === NULL) {
      $this->connect();
    }
    // For arrays, recursively escape and join in comma-separated list.
    if (is_array($value)) {
      return '(' . join(', ', array_map(array($this, 'escape'), $value, array(TRUE))) . ')';
    }
    // Booleans get converted to 1 or 0.
    if (is_bool($value)) {
      return $value ? 1 : 0;
    }
    // Numeric values remain as-is.
    if (is_numeric($value)) {
      return $value;
    }
    // Null values are converted to NULL string.
    if (is_null($value)) {
      return 'NULL';
    }
    // String values are escaped, and optionally wrapped in quotes.
    $value = mysqli_real_escape_string($this->db, $value);
    if ($quote !== FALSE) {
      $separator = TRUE ? "'" : $quote;
      $value = $separator . $value . $separator;
    }
    return $value;
  }

  /**
   * Gets $column's enum options.
   *
   * @param string $table The table.
   * @param string $column The table's column.
   *
   * @return array
   **/
  public function enum($table, $column) {
    $info = $this->getRow("DESCRIBE `{$table}` `{$column}`");
    return explode(',', preg_replace('/enum\(([^\)]+)\)/', '$1', str_replace("'", '', $info['Type'])));
  }

  /**
   * Gets all query results.
   *
   * @param string $query The query string.
   * @param mixed $vars Values inject into $query.
   *
   * @return array Associative array of results.
   */
  public function getAll($query, $vars = array()) {
    $result =& $this->query($query, $vars);
    $rows = array();
    while ($row = mysqli_fetch_array($result, MYSQL_ASSOC)) {
      $rows[] = $row;
    }
    return $rows;
  }

  /**
   * Gets first row of query results.
   *
   * "LIMIT 1" will be auto-appended to $query, if not already included, and if
   * not a DESCRIBE query.
   *
   * @param string $query The query string.
   * @param mixed $vars Values to inject into $query.
   *
   * @return array Associative array of first row of results.
   */
  public function getRow($query, $vars = array()) {
    if (!strpos($query, 'LIMIT 1') && substr($query, 0, 8) != 'DESCRIBE') {
      $query .= ' LIMIT 1';
    }
    $result =& $this->query($query, $vars);
    return mysqli_fetch_array($result, MYSQL_ASSOC);
  }

  /**
   * Gets first column of first row of query results.
   *
   * "LIMIT 1" will be auto-appended to $query, if not already included.
   *
   * @param string $query The query string.
   * @param mixed $vars Values to inject into $query.
   *
   * @return mixed The first column of the first row.
   */
  public function getOne($query, $vars = FALSE) {
    if (!strpos($query, 'LIMIT 1')) {
      $query .= ' LIMIT 1';
    }
    $result =& $this->query($query, $vars);
    $row = mysqli_fetch_array($result, MYSQL_NUM);
    return $row[0];
  }

  /**
   * Gets first column of all query results.
   *
   * @param string $query The query string.
   * @param mixed $vars Values to inject into $query.
   *
   * @return array Array of first column of all rows.
   */
  public function getCol($query, $vars = FALSE) {
    $result =& $this->query($query, $vars);
    $rows = array();
    while ($row = mysqli_fetch_array($result, MYSQL_NUM)) {
      $rows[] = $row[0];
    }
    return $rows;
  }

  /**
   * Generates a key => value list from query results.
   *
   * This function will create an associative array where the first column is
   * the key, and the second column is the value.
   *
   * <code>
   * $list1 = $db->getList("SELECT id, name FROM users WHERE id < ?", 10);
   * $list2 = $db->getList("SELECT id, first_name, last_name FROM users", FALSE, ' ');
   * $list3 = $db->getList("SELECT id, first_name, last_name, email FROM users", FALSE, TRUE);
   * </code>
   *
   * @param string $query The query string.
   * @param mixed $vars Values to inject into $query.
   * @param mixed $join If TRUE, remaining columns will be an associative
   * array. If a string, that string will be used to join all remaining
   * columns.
   *
   * @return array Array of key => values.
   */
  public function getList($query, $vars = array(), $join = FALSE) {
    $results =& $this->query($query, $vars);
    $list = array();
    if ($join === TRUE) {
      while ($row = mysqli_fetch_array($results, MYSQL_ASSOC)) {
        $list[reset($row)] = $row;
      }
    }
    else {
      while ($row = mysqli_fetch_array($results, MYSQL_NUM)) {
        $value = is_string($join) ? implode($join, array_slice($row, 1)) : $row[1];
        $list[$row[0]] = $value;
      }
    }
    return $list;
  }

  /**
   * Tracks database transactions, gracefuly handling nested transactions.
   *
   * @param string $action The action to take, either "start", "rollback", or
   * "commit".
   */
  public function transaction($action = 'start') {
    switch ($action) {
      case 'start':
        if (!$this->transaction) {
          $this->query('START TRANSACTION');
          $this->transaction = TRUE;
        }
        return;

      case 'rollback':
        $this->query("ROLLBACK");
        $this->transaction = FALSE;
        return;

      case 'commit':
        $this->query("COMMIT");
        $this->transaction = FALSE;
        return;
    }
  }

}
