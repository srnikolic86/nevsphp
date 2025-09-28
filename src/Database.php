<?php

namespace Nevs;

class Database
{
    public \mysqli|null $db;
    private array $config;

    public function __construct()
    {
        $this->LoadConfig(Config::Get('db'));
    }

    public function LoadConfig(array $config): void {
        $this->config = $config;
        $this->LoadDatabase();
    }

    private function LoadDatabase(): void
    {
        $this->db = null;
        try {
            $this->db = new \mysqli($this->config['host'], $this->config['username'], $this->config['password'], $this->config['database']);
        } catch (\Exception $e) {
            Log::Write('Database', 'cannot connect to db');
            die($e->getMessage());
        }
        $this->db->set_charset($this->config['charset']);
    }

    public function CreateTable(array $data): bool
    {
        $query = "SHOW TABLES LIKE '".mysqli_real_escape_string($this->db, $data['name'])."'";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $results = $stmt->get_result();
        while ($table = $results->fetch_assoc()) {
            return false;
        }

        $primary = null;
        $relations = [];
        $query = 'CREATE TABLE `' . mysqli_real_escape_string($this->db, $data['name']) . '` (';
        foreach ($data['fields'] as $field) {
            if (isset($field['primary_key']) && $field['primary_key'] === true) $primary = $field;
            if (isset($field['foreign_key'])) $relations[] = $field;
            $field_type = null;
            $field_length = null;
            $this->ResolveField($field['type'], $field_type, $field_length);
            if ($field_type !== null) {
                $field_nullable = isset($field['nullable']) && $field['nullable'] === true;
                $field_default = (isset($field['default'])) ? $field['default'] : null;
                $field_auto_increment = isset($field['auto_increment']) && $field['auto_increment'] === true;
                $query .= $this->MakeFieldQuery($field['name'], $field_type, $field_length, $field_nullable, $field_default, $field_auto_increment);
                $query .= ', ';
            }
        }
        if ($primary !== null) {
            $query .= 'PRIMARY KEY (`' . mysqli_real_escape_string($this->db, $primary['name']) . '`)';
        } else {
            $query = substr($query, 0, -2);
        }
        $query .= ') ENGINE = InnoDB;';
        if ($this->db->query($query) === false) return false;

        $foreign_ids = [];

        foreach ($relations as $relation) {
            $foreign_ids[$relation['foreign_key']] = null;
            $query = 'SELECT * FROM `' . $this->config['model_table'] . '` WHERE `table`=? AND `primary_key`=1';
            $stmt = $this->db->prepare($query);
            $stmt->execute([$relation['foreign_key']]);
            $results = $stmt->get_result();
            while ($result = $results->fetch_assoc()) {
                $foreign_ids[$relation['foreign_key']] = $result['field'];
            }
            if ($foreign_ids[$relation['foreign_key']] !== null) {
                $this->db->query('ALTER TABLE `' . mysqli_real_escape_string($this->db, $data['name']) . '` ADD CONSTRAINT `' . mysqli_real_escape_string($this->db, $data['name'] . '_' . $relation['name'] . '_' . $relation['foreign_key']) . '` FOREIGN KEY (`' . mysqli_real_escape_string($this->db, $relation['name']) . '`) REFERENCES `' . mysqli_real_escape_string($this->db, $relation['foreign_key']) . '`(`' . mysqli_real_escape_string($this->db, $foreign_ids[$relation['foreign_key']]) . '`) ON DELETE RESTRICT ON UPDATE RESTRICT;');
            }
        }

        foreach ($data['fields'] as $field) {
            $related_table = (isset($field['foreign_key'])) ? $field['foreign_key'] : null;
            $related_field = (isset($field['foreign_key']) && isset($foreign_ids[$field['foreign_key']])) ? $foreign_ids[$field['foreign_key']] : null;
            $nullable = (isset($field['nullable']) && $field['nullable'] === true) ? 1 : 0;
            $primary_key = (isset($field['primary_key']) && $field['primary_key'] === true) ? 1 : 0;
            $query = 'INSERT INTO `' . $this->config['model_table'] . '` (`table`, `field`, `type`, `nullable`, `primary_key`, `related_table`, `related_field`) VALUES (?, ?, ?, ?, ?, ?, ?)';
            $stmt = $this->db->prepare($query);
            $stmt->execute([$data['name'], $field['name'], $field['type'], $nullable, $primary_key, $related_table, $related_field]);
        }

        Log::Write('Database', 'create table: ' . json_encode($data));

        return true;
    }

    public function ModifyTable(array $data): bool
    {
        $table_exists = false;
        $query = "SHOW TABLES LIKE '".mysqli_real_escape_string($this->db, $data['name'])."'";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $results = $stmt->get_result();
        while ($table = $results->fetch_assoc()) {
            $table_exists = true;
        }
        if (!$table_exists) return false;

        $fields = $data['fields'];

        //modify fields
        if (isset($fields['modify'])) {
            //modify actual fields, identify new relations and drop old foreign keys
            $relations = [];
            foreach ($fields['modify'] as $field) {
                //drop foreign key if needed
                $query = 'SELECT * FROM `' . $this->config['model_table'] . '` WHERE `table`=? AND `field`=? AND related_table IS NOT NULL;';
                $stmt = $this->db->prepare($query);
                $stmt->execute([$data['name'], $field['old_name']]);
                $results = $stmt->get_result();
                while ($record = $results->fetch_assoc()) {
                    if (!isset($field['foreign_key']) || $field['foreign_key'] != $record['related_table'] || $field['new_name'] != $field['old_name']) {
                        $query = 'ALTER TABLE `' . mysqli_real_escape_string($this->db, $record['table']) . '` DROP FOREIGN KEY `' . $record['table'] . '_' . $record['field'] . '_' . $record['related_table'] . '`';
                        $stmt2 = $this->db->prepare($query);
                        $stmt2->execute();
                    }
                }
                //modify actual field
                if (isset($field['foreign_key'])) $relations[] = $field;
                $field_type = null;
                $field_length = null;
                $this->ResolveField($field['type'], $field_type, $field_length);
                if ($field_type != null) {
                    $query = 'ALTER TABLE `' . mysqli_real_escape_string($this->db, $data['name']) . '` CHANGE `' . mysqli_real_escape_string($this->db, $field['old_name']) . '` ';
                    $field_nullable = isset($field['nullable']) && $field['nullable'] === true;
                    $field_default = (isset($field['default'])) ? $field['default'] : null;
                    $field_auto_increment = isset($field['auto_increment']) && $field['auto_increment'] === true;
                    $query .= $this->MakeFieldQuery($field['new_name'], $field_type, $field_length, $field_nullable, $field_default, $field_auto_increment);
                    $query .= ';';
                    if ($this->db->query($query) === false) return false;
                }
            }
            //add new relations
            $foreign_ids = [];
            foreach ($relations as $relation) {
                $relation_exists = false;
                $query = 'SELECT * FROM `' . $this->config['model_table'] . '` WHERE `table`=? AND `field`=? AND related_table=?;';
                $stmt = $this->db->prepare($query);
                $stmt->execute([$data['name'], $relation['new_name'], $relation['foreign_key']]);
                $results = $stmt->get_result();
                while ($record = $results->fetch_assoc()) {
                    $relation_exists = true;
                }
                if (!$relation_exists) {
                    $foreign_ids[$relation['foreign_key']] = null;
                    $query = 'SELECT * FROM `' . $this->config['model_table'] . '` WHERE `table`=? AND `primary_key`=1';
                    $stmt = $this->db->prepare($query);
                    $stmt->execute([$relation['foreign_key']]);
                    $results = $stmt->get_result();
                    while ($result = $results->fetch_assoc()) {
                        $foreign_ids[$relation['foreign_key']] = $result['field'];
                    }
                    if ($foreign_ids[$relation['foreign_key']] !== null) {
                        $this->db->query('ALTER TABLE `' . mysqli_real_escape_string($this->db, $data['name']) . '` ADD CONSTRAINT `' . mysqli_real_escape_string($this->db, $data['name'] . '_' . $relation['new_name'] . '_' . $relation['foreign_key']) . '` FOREIGN KEY (`' . mysqli_real_escape_string($this->db, $relation['new_name']) . '`) REFERENCES `' . mysqli_real_escape_string($this->db, $relation['foreign_key']) . '`(`' . mysqli_real_escape_string($this->db, $foreign_ids[$relation['foreign_key']]) . '`) ON DELETE RESTRICT ON UPDATE RESTRICT;');
                    }
                }
            }
            //modify model table
            foreach ($fields['modify'] as $field) {
                $related_table = (isset($field['foreign_key'])) ? $field['foreign_key'] : null;
                $related_field = (isset($field['foreign_key']) && isset($foreign_ids[$field['foreign_key']])) ? $foreign_ids[$field['foreign_key']] : null;
                $nullable = (isset($field['nullable']) && $field['nullable'] === true) ? 1 : 0;
                $primary_key = (isset($field['primary_key']) && $field['primary_key'] === true) ? 1 : 0;
                $query = 'UPDATE `' . $this->config['model_table'] . '` SET `field`=?, `type`=?, `nullable`=?, `primary_key`=?, `related_table`=?, `related_field`=? WHERE `table`=? AND `field`=?';
                $stmt = $this->db->prepare($query);
                $stmt->execute([$field['new_name'], $field['type'], $nullable, $primary_key, $related_table, $related_field, $data['name'], $field['old_name']]);
            }
        }

        //add fields
        if (isset($fields['add'])) {
            //add actual fields and identify relations
            $relations = [];
            foreach ($fields['add'] as $field) {
                if (isset($field['foreign_key'])) $relations[] = $field;
                $field_type = null;
                $field_length = null;
                $this->ResolveField($field['type'], $field_type, $field_length);
                if ($field_type != null) {
                    $query = 'ALTER TABLE `' . mysqli_real_escape_string($this->db, $data['name']) . '` ADD  ';
                    $field_nullable = isset($field['nullable']) && $field['nullable'] === true;
                    $field_default = (isset($field['default'])) ? $field['default'] : null;
                    $field_auto_increment = isset($field['auto_increment']) && $field['auto_increment'] === true;
                    $query .= $this->MakeFieldQuery($field['name'], $field_type, $field_length, $field_nullable, $field_default, $field_auto_increment);
                    $query .= ';';
                    if ($this->db->query($query) === false) return false;
                }
            }
            //add relations
            $foreign_ids = [];
            foreach ($relations as $relation) {
                $foreign_ids[$relation['foreign_key']] = null;
                $query = 'SELECT * FROM `' . $this->config['model_table'] . '` WHERE `table`=? AND `primary_key`=1';
                $stmt = $this->db->prepare($query);
                $stmt->execute([$relation['foreign_key']]);
                $results = $stmt->get_result();
                while ($result = $results->fetch_assoc()) {
                    $foreign_ids[$relation['foreign_key']] = $result['field'];
                }
                if ($foreign_ids[$relation['foreign_key']] !== null) {
                    $this->db->query('ALTER TABLE `' . mysqli_real_escape_string($this->db, $data['name']) . '` ADD CONSTRAINT `' . mysqli_real_escape_string($this->db, $data['name'] . '_' . $relation['name'] . '_' . $relation['foreign_key']) . '` FOREIGN KEY (`' . mysqli_real_escape_string($this->db, $relation['name']) . '`) REFERENCES `' . mysqli_real_escape_string($this->db, $relation['foreign_key']) . '`(`' . mysqli_real_escape_string($this->db, $foreign_ids[$relation['foreign_key']]) . '`) ON DELETE RESTRICT ON UPDATE RESTRICT;');
                }
            }
            //add to model table
            foreach ($fields['add'] as $field) {
                $related_table = (isset($field['foreign_key'])) ? $field['foreign_key'] : null;
                $related_field = (isset($field['foreign_key']) && isset($foreign_ids[$field['foreign_key']])) ? $foreign_ids[$field['foreign_key']] : null;
                $nullable = (isset($field['nullable']) && $field['nullable'] === true) ? 1 : 0;
                $primary_key = (isset($field['primary_key']) && $field['primary_key'] === true) ? 1 : 0;
                $query = 'INSERT INTO `' . $this->config['model_table'] . '` (`table`, `field`, `type`, `nullable`, `primary_key`, `related_table`, `related_field`) VALUES (?, ?, ?, ?, ?, ?, ?)';
                $stmt = $this->db->prepare($query);
                $stmt->execute([$data['name'], $field['name'], $field['type'], $nullable, $primary_key, $related_table, $related_field]);
            }
        }

        //remove fields
        if (isset($fields['remove'])) {
            foreach ($fields['remove'] as $field) {
                //drop foreign key if needed
                $query = 'SELECT * FROM `' . $this->config['model_table'] . '` WHERE `table`=? AND `field`=? AND related_table IS NOT NULL';
                $stmt = $this->db->prepare($query);
                $stmt->execute([$data['name'], $field]);
                $results = $stmt->get_result();
                while ($record = $results->fetch_assoc()) {
                    $query = 'ALTER TABLE `' . mysqli_real_escape_string($this->db, $record['table']) . '` DROP FOREIGN KEY `' . $record['table'] . '_' . $record['field'] . '_' . $record['related_table'] . '`';
                    $stmt2 = $this->db->prepare($query);
                    $stmt2->execute();
                }
                //drop field
                $query = 'ALTER TABLE `' . mysqli_real_escape_string($this->db, $data['name']) . '` DROP  `' . $field . '`;';
                if ($this->db->query($query) === false) return false;
            }
            //remove from model table
            foreach ($fields['remove'] as $field) {
                $query = 'DELETE FROM `' . $this->config['model_table'] . '` WHERE `table`=? AND `field`=?';
                $stmt = $this->db->prepare($query);
                $stmt->execute([$data['name'], $field]);
            }
        }

        return true;
    }

    public function DeleteTable(string $table_name): bool
    {
        //check if the table exists
        $table_exists = false;
        $query = "SHOW TABLES LIKE '".mysqli_real_escape_string($this->db, $table_name)."'";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $results = $stmt->get_result();
        while ($table = $results->fetch_assoc()) {
            $table_exists = true;
        }
        if (!$table_exists) return false;

        //drop the foreign keys
        $query = 'SELECT * FROM `' . $this->config['model_table'] . '` WHERE (`table`=? AND `related_table` IS NOT NULL) OR `related_table`=?;';
        $stmt = $this->db->prepare($query);
        $stmt->execute([$table_name, $table_name]);
        $results = $stmt->get_result();
        while ($record = $results->fetch_assoc()) {
            $query = 'ALTER TABLE `' . mysqli_real_escape_string($this->db, $record['table']) . '` DROP FOREIGN KEY `' . $record['table'] . '_' . $record['field'] . '_' . $record['related_table'] . '`';
            $stmt2 = $this->db->prepare($query);
            $stmt2->execute();
        }

        //drop the table
        $this->db->query('DROP TABLE `' . $table_name . '`');

        //delete from model
        $query = 'DELETE FROM `' . $this->config['model_table'] . '` WHERE `table`=?';
        $stmt = $this->db->prepare($query);
        $stmt->execute([$table_name]);
        $query = "UPDATE `" . $this->config['model_table'] . "` SET `related_table`=NULL, `related_field`=NULL WHERE `related_table`=?";
        $stmt = $this->db->prepare($query);
        $stmt->execute([$table_name]);


        return true;
    }

    public function Execute(string $statement, array $params = []): \mysqli_stmt | false
    {
        $stmt = $this->db->prepare($statement);
        if ($stmt->execute($params) === false) return false;
        return $stmt;
    }

    public function ExecuteSelect(string $statement, array $params = []): array|false
    {
        $stmt = $this->Execute($statement, $params);
        if ($stmt === false) return false;
        return $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
    }

    public function ExecuteInsert(string $statement, array $params = []): int|string|false
    {
        $stmt = $this->Execute($statement, $params);
        if ($stmt === false) return false;
        return $stmt->insert_id;
    }

    private function ResolveField($in_type, &$out_type, &$out_length): void
    {
        switch ($in_type) {
            case 'int':
            case 'bool':
                $out_type = 'INT';
                break;
            case 'bigint':
                $out_type = 'BIGINT';
                break;
            case 'float':
                $out_type = 'FLOAT';
                break;
            case 'double':
                $out_type = 'DOUBLE';
                break;
            case 'string':
                $out_type = 'VARCHAR';
                $out_length = '255';
                break;
            case 'tinytext':
                $out_type = 'TINYTEXT';
                break;
            case 'text':
                $out_type = 'TEXT';
                break;
            case 'mediumtext':
                $out_type = 'MEDIUMTEXT';
                break;
            case 'longtext':
            case 'json':
                $out_type = 'LONGTEXT';
                break;
            case 'date':
                $out_type = 'DATE';
                break;
            case 'datetime':
                $out_type = 'DATETIME';
                break;
        }
    }

    private function MakeFieldQuery(string $name, string|null $type = null, string|null $length = null, bool $nullable = false, string|null $default = null, bool $auto_increment = false): string
    {
        $query = '`' . $name . '` ';
        if ($type !== null) {
            $query .= ' ' . $type . ' ';
        }
        if ($length !== null) {
            $query .= ' (' . mysqli_real_escape_string($this->db, $length) . ') ';
        }
        if ($nullable) {
            $query .= ' NULL ';
        } else {
            $query .= ' NOT NULL ';
        }
        if ($default !== null) {
            if (!in_array($type, ['INT', 'BIGINT', 'FLOAT', 'DOUBLE'])) {
                $default = "'" . $default . "'";
            }
            $query .= ' DEFAULT ' . $default;
        }
        if ($auto_increment != null) {
            $query .= ' AUTO_INCREMENT ';
        }
        return $query;
    }
}