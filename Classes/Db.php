<?php

namespace Classes;

class Db
{

    const PLUS_ONE_INCREMENT = 'PLUS_ONE_INCREMENT_FIELD';

    public static $count = 0;
    public static $queries = array();
    protected static $_instance;
    protected $statement;
    private $db_connect_name;
    private $dbh;
    private $result;
    private $sqlQuery = '';

    private function __construct($key, $config = array())
    {
        $db = (!empty($config) ? $config : (self::loadConfig()));
        $this->db_connect_name = $db['dbname'].'/'.$db['user'];
        try {
            $this->dbh = new \PDO(
                'mysql:host='.$db['host'].';dbname='.$db['dbname'], $db['user'], $db['pwd'], array()
            );
            $this->dbh->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->exec('SET NAMES utf8');
        } catch (\PDOException $e) {
            $this->log($e->getMessage());
            exit;
        }
    }

    private function exec($sql)
    {
        $this->sqlQuery = $sql;
        $this->dbh->exec($sql);
    }

    /**
     * @param string $key
     *
     * @return Db
     */

    public static function getDB($key = 'db')
    {
        if (!isset(self::$_instance[$key]) || self::$_instance[$key] === null) {
            self::$_instance[$key] = new self($key);
        }

        return self::$_instance[$key];
    }

    public function update($table, $data, $expr)
    {
        if (!empty($data)) {
            if (is_array($data)) {
                $nobind = false;
                $keys = $this->getKeys($data);
                $values = $this->getValues($data);
                $list = array();
                foreach ($keys as $value) {
                    if ($values[0][':'.$value] === self::PLUS_ONE_INCREMENT) {
                        $nobind = 1;
                        $list[] = $value.' = '.$value.' + 1';
                    } else {
                        $list[] = $value.' = :'.$value;
                    }
                }
                $this->sqlQuery = 'UPDATE `'.$table.'` SET '.implode(', ', $list).' '.$this->expr($expr);
                $this->statement = $this->dbh->prepare($this->sqlQuery);
                if (!empty($expr)) {
                    $this->setExpr($expr);
                }
                if (!$nobind) {
                    $this->bindValues($values);
                }
                $this->execute();
            }
        }
    }

    private function getKeys($data)
    {

        $keys = array();

        if (is_array($data)) {
            foreach ($data as $row) {
                if (is_array($row)) {
                    $keys = array_keys($row);
                } else {
                    $keys = array_keys($data);
                }
            }
        }

        return $keys;
    }

    private function getValues($data)
    {
        $values = array();
        if (!empty($data)) {
            foreach ($data as $index => $row) {
                if (!empty($row) && is_array($row)) {
                    foreach ($row as $key => $value) {
                        $values[$index][':'.$key.$index] = $value;
                    }
                } else {
                    $values[0][':'.$index] = $row;
                }
            }
        }

        return $values;
    }

    private function expr($expr, $action = 'AND')
    {
        $e = array();
        if (!empty($expr) && is_array($expr)) {
            foreach ($expr as $exp) {
                switch ($exp[2]) {
                    case 'IN':
                        $expr = array();
                        foreach ($exp[1] as $k => $one) {
                            $expr[] = ':in_exp'.$k;
                        }
                        $e[] = $exp[0].' IN ('.implode(', ', $expr).')';
                        break;
                    case 'BETWEEN':
                        break;
                    default:
                        $e[] = $exp[0].' '.$exp[2].' :exp'.$exp[0];
                }
            }
        } else {
            $exp = $expr;
            if (isset($exp[2])) {
                switch ($exp[2]) {
                    case 'IN':
                        $expr = array();
                        foreach ($exp[1] as $k => $one) {
                            $expr[] = ':in_exp'.$k;
                        }
                        $e[] = $exp[0].' IN ('.implode(', ', $expr).')';
                        break;
                    case 'BETWEEN':
                        break;
                    default:
                        $e[] = $exp[0].' '.$exp[2].' :exp'.$exp[0];
                }
            }
        }
        if (!empty($e)) {
            return ' WHERE '.implode(' '.$action.' ', $e);
        }
    }

    private function setExpr($expr)
    {
        $values = array();
        if (!empty($expr) && is_array($expr)) {
            foreach ($expr as $exp) {
                if (isset($exp[2])) {
                    switch ($exp[2]) {
                        case 'IN':
                            foreach ($exp[1] as $k => $one) {
                                $values[':in_exp'.$k] = $one;
                            }
                            break;
                        default:
                            $values[':exp'.str_replace('.', '_', $exp[0])] = $exp[1];
                    }
                }
            }
        }
        if (!empty($values)) {
            $this->bindValues($values);
        }
    }

    private function bindValues($values)
    {
        if (!empty($values)) {
            foreach ($values as $index => &$value) {
                if (is_array($value)) {
                    foreach ($value as $key => &$val) {
                        $this->statement->bindParam($key, $val);
                    }
                } else {
                    $this->statement->bindParam($index, $value);
                }
            }
        }
    }

    private function execute()
    {
        try {
            $this->statement->execute();
        } catch (\PDOException $e) {
            $this->log($e->getMessage());
        }
    }

    public function select($table, $fields = array('*'), $expr = array(), $order = '', $limit = '', $sql_cache = false)
    {
        $this->sqlQuery = 'SELECT '.($sql_cache ? 'SQL_CACHE ' : '').$this->keys($fields).' FROM `'.$table.'`'
            .$this->expr($expr).' '.$order.' '.($limit ? 'LIMIT '.$limit : '');

        self::$count++;
        $this->statement = $this->dbh->prepare($this->sqlQuery);
        Db::$queries[] = $this->sqlQuery;
        if (!empty($expr)) {
            $this->setExpr($expr);
        }
        try {
            $this->statement->execute();
        } catch (\PDOException $e) {
            $this->log($e->getMessage());
        }
        $this->result = $this->statement->fetchAll(\PDO::FETCH_OBJ);

        return $this->result;
    }

    private function keys($keys)
    {
        return implode(', ', $keys);
    }

    public function q($sql, $params = array(), $showQuery = false)
    {
        if ($showQuery) {
            print $this->printRealQuery($sql, $params);
        }
        $result = null;
        $this->sqlQuery = $sql;

        self::$count++;
        $this->statement = $this->dbh->prepare($sql);
        Db::$queries[] = $this->sqlQuery;
        $this->bindValues($params);
        $this->execute();
        if (strpos($sql, 'ELECT ')) {
            $result = $this->statement->fetchAll(\PDO::FETCH_OBJ);
        }

        return $result;
    }

    public function merge($table, $data)
    {
        $last_id = null;
        foreach ($data as $row) {
            $last_id = $this->insert($table, $row, true);
        }

        return $last_id;
    }

    public function insert($table, $data, $replace = false)
    {
        if (!empty($data)) {
            if (is_array($data)) {
                $keys = $this->getKeys($data);
                $values = $this->getValues($data);
                $this->sqlQuery
                    = ($replace ? 'REPLACE' : 'INSERT').' INTO `'.$table.'` ('.$this->keys($keys).') VALUE '
                    .$this->values($values);
                $this->statement = $this->dbh->prepare($this->sqlQuery);
                $this->bindValues($values);
                $this->execute();

                return $this->dbh->lastInsertId($table);
            }
        }
    }

    private function values($values)
    {
        $val = array();
        if (!empty($values)) {
            foreach ($values as $value) {
                $val[] = '('.implode(', ', array_keys($value)).')';
            }
        }

        return implode(', ', $val);
    }

    private function __clone()
    {
    }

    private function query($sql)
    {
        $this->dbh->query($sql);
    }

    public function getSqlQuery()
    {
        return $this->sqlQuery;
    }

    public function printRealQuery($sql, $exp)
    {
        return str_replace(array_keys($exp), array_values($exp), $sql);
    }

    static public function loadConfig()
    {
        return array(
            'dbname' => 'bot',
            'user' => 'root',
            'host' => 'localhost',
            'pwd' => 'cbkfvsckb',
        );
    }

    public function log($message)
    {
        print date('Y-m-d H:i:s').': '.$message.PHP_EOL;
    }


}