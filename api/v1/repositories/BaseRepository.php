<?php
/**
 * Base Repository Class
 * Abstracts database access for all repositories
 */

namespace NamosaAPI\Repositories;

use NamosaAPI\Config\Database;

class BaseRepository
{
    protected $db;
    protected $tablePrefix;
    
    public function __construct()
    {
        $dbInstance = Database::getInstance();
        $this->db = $dbInstance->getConnection();
        $this->tablePrefix = $dbInstance->getTablePrefix();
    }
    
    /**
     * Execute a query and return all results
     */
    protected function fetchAll($sql, $params = [])
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }
    
    /**
     * Execute a query and return single result
     */
    protected function fetchOne($sql, $params = [])
    {
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetch();
    }
    
    /**
     * Insert a record and return last insert ID
     */
    protected function insert($table, $data)
    {
        $keys = array_keys($data);
        $fields = implode(', ', $keys);
        $placeholders = ':' . implode(', :', $keys);
        
        $sql = "INSERT INTO {$this->tablePrefix}{$table} ($fields) VALUES ($placeholders)";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($data);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Update records
     */
    protected function update($table, $data, $where, $whereParams = [])
    {
        $setParts = [];
        foreach ($data as $key => $value) {
            $setParts[] = "$key = :$key";
        }
        $setClause = implode(', ', $setParts);
        
        $sql = "UPDATE {$this->tablePrefix}{$table} SET $setClause WHERE $where";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute(array_merge($data, $whereParams));
    }
    
    /**
     * Delete records
     */
    protected function delete($table, $where, $whereParams = [])
    {
        $sql = "DELETE FROM {$this->tablePrefix}{$table} WHERE $where";
        
        $stmt = $this->db->prepare($sql);
        return $stmt->execute($whereParams);
    }
    
    /**
     * Begin transaction
     */
    protected function beginTransaction()
    {
        $this->db->beginTransaction();
    }
    
    /**
     * Commit transaction
     */
    protected function commit()
    {
        $this->db->commit();
    }
    
    /**
     * Rollback transaction
     */
    protected function rollback()
    {
        $this->db->rollBack();
    }
    
    /**
     * Quote identifier (prevent SQL injection)
     */
    protected function quoteIdentifier($identifier)
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
    
    /**
     * Quote value
     */
    protected function quote($value)
    {
        return $this->db->quote($value);
    }
}