<?php

/**
 * Helper functions, assuming you have a global variable called $db.
 */

function db_query($query, $vars = array()) {
  global $db;
  return $db->query($query, $vars);
}

function db_rows($query, $vars = array()) {
  global $db;
  return $db->getAll($query, $vars);
}

function db_row($query, $vars = array()) {
  global $db;
  return $db->getRow($query, $vars);
}

function db_col($query, $vars = array()) {
  global $db;
  return $db->getCol($query, $vars);
}

function db_list($query, $vars = array(), $join = FALSE) {
  global $db;
  return $db->getList($query, $vars, $join);
}

function db_one($query, $vars = array()) {
  global $db;
  return $db->getOne($query, $vars);
}

function db_escape($value, $quote = TRUE) {
  global $db;
  return $db->escape($value, $quote);
}

function db_save($table, &$data, $where = NULL, $primaryKey = NULL) {
  global $db;
  return $db->save($table, $data, $where, $primaryKey);
}

function db_enum($table, $column) {
  global $db;
  return $db->enum($table, $column);
}

function db_queries() {
  global $db;
  return array_map(function($query) {
    return preg_replace("/\n +/", ' ', $query);
  }, $db->queries);
}

function db_transaction($action) {
  global $db;
  return $db->transaction($action);
}
