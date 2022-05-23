<?php

namespace Nevs;

use Cassandra\Date;
use \JsonSerializable;

class Model implements JsonSerializable
{
    public array $nevs_raw_data;
    public string $table;
    public string $id_field;
    public array $hidden;

    public function __construct()
    {
        $this->nevs_raw_data = [];
        $this->table = 'table_name';
        $this->id_field = 'id';
        $this->hidden = [];
    }

    static function Create($data): null|object
    {
        global $DB;

        $class = get_called_class();
        $object = new $class();
        $table = $object->table;

        $data = $object::PrepareDataForDB($data);

        $params = [];
        $fields = [];
        $values = [];
        foreach ($data as $field => $value) {
            $fields[] = "`" . mysqli_real_escape_string($DB->db, $field) . "`";
            $values[] = "?";
            $params[] = $value;
        }
        $query = "INSERT INTO `" . $table . "` (" . implode(', ', $fields) . ") VALUES (" . implode(', ', $values) . ")";

        $result = $DB->ExecuteInsert($query, $params);
        if ($result === false) return null;

        return $object::Find($result);
    }

    function Update($data): bool
    {
        global $DB;

        foreach ($data as $field => $value) {
            $this->nevs_raw_data[$field] = $value;
        }

        $data = $this::PrepareDataForDB($data);

        $params = [];
        $fields = [];

        foreach ($data as $field => $value) {
            $fields[] = "`" . mysqli_real_escape_string($DB->db, $field) . "` = ?";
            $params[] = $value;
        }
        $query = "UPDATE `" . $this->table . "` SET " . implode(', ', $fields) . " WHERE `" . $this->id_field . "` = ?";
        $params[] = $this->nevs_raw_data[$this->id_field];

        return $DB->Execute($query, $params) !== false;
    }

    function Delete(): bool
    {
        global $DB;

        $model = $this::GetModel();

        foreach ($model as $field => $settings) {
            $query = 'SELECT * FROM `' . Config::Get('db.model_table') . '` WHERE `related_table`=? AND `related_field` = ?';
            $stmt = $DB->db->prepare($query);
            $stmt->execute([$this->table, $field]);
            $results = $stmt->get_result();
            while ($record = $results->fetch_assoc()) {
                $query = 'SELECT * FROM `' . $record['table'] . '` WHERE `' . $record['field'] . "`=?";
                $stmt2 = $DB->db->prepare($query);
                $stmt2->execute([$this->nevs_raw_data[$record['related_field']]]);
                $results2 = $stmt2->get_result();
                while ($record2 = $results2->fetch_assoc()) {
                    return false;
                }
            }
        }

        $query = "DELETE FROM `" . $this->table . "` WHERE `" . $this->id_field . "`=?";

        return $DB->Execute($query, [$this->nevs_raw_data[$this->id_field]]) !== false;
    }

    function GetRelated(string $table): array
    {
        global $DB;

        $related = [];

        $raw_data = $this::PrepareDataForDB($this->nevs_raw_data);

        $query = 'SELECT * FROM `' . Config::Get('db.model_table') . '` WHERE `table`=? AND `related_table`=?';
        $results = $DB->ExecuteSelect($query, [$table, $this->table]);
        foreach ($results as $record) {
            $related[$record['field']] = [];
            $query = 'SELECT * FROM `' . $table . '` WHERE `' . $record['field'] . "` = ?";
            $results2 = $DB->ExecuteSelect($query, [$raw_data[$record['related_field']]]);
            foreach ($results2 as $record2) {
                $related[$record['field']][] = $record2;
            }
        }

        $query = 'SELECT * FROM `' . Config::Get('db.model_table') . '` WHERE `table`=? AND `related_table`=?';
        $results = $DB->ExecuteSelect($query, [$this->table, $table]);
        foreach ($results as $record) {
            if ($this->nevs_raw_data[$record['field']] !== null) {
                $related[$record['field']] = null;
                $query = 'SELECT * FROM `' . $table . '` WHERE `' . $record['related_field'] . "` = ?";
                $results2 = $DB->ExecuteSelect($query, [$raw_data[$record['field']]]);
                foreach ($results2 as $record2) {
                    $related[$record['field']] = $record2;
                }
            }
        }

        return $related;
    }

    static function Find($id): null|object
    {
        global $DB;
        $class = get_called_class();
        $object = new $class();

        $data = null;
        $query = "SELECT * FROM `" . mysqli_real_escape_string($DB->db, $object->table) . "` WHERE `" . mysqli_real_escape_string($DB->db, $object->id_field) . "` = ?";
        $results = $DB->ExecuteSelect($query, [$id]);
        foreach ($results as $row) {
            $data = $row;
        }

        if ($data === null) return null;
        foreach ($data as $key => $value) {
            if (in_array($key, $object->hidden)) {
                unset($data[$key]);
            }
        }

        return $object::MakeFromRawData($data);
    }

    static function Select(string $where, array $params = []): array
    {
        global $DB;
        $class = get_called_class();
        $object = new $class();

        $query = "SELECT * FROM `" . mysqli_real_escape_string($DB->db, $object->table) . "` WHERE " . $where;
        $results = $DB->ExecuteSelect($query, $params);
        if ($results !== false) {
            foreach ($results as $row) {
                $results[] = $object::MakeFromRawData($row);
            }
        }

        return ($results !== false) ? $results : [];
    }

    static function GetModel(): array
    {
        global $DB;
        $model = [];
        $class = get_called_class();
        $object = new $class();

        $query = 'SELECT * FROM `' . Config::Get('db.model_table') . '` WHERE `table`=?';
        $stmt = $DB->db->prepare($query);
        $stmt->execute([$object->table]);
        $results = $stmt->get_result();
        while ($record = $results->fetch_assoc()) {
            $model[$record['field']] = $record;
            unset($model[$record['field']]['id']);
            unset($model[$record['field']]['table']);
            unset($model[$record['field']]['field']);
        }

        return $model;
    }

    private static function MakeFromRawData(array $data): object
    {
        $class = get_called_class();
        $object = new $class();
        foreach ($data as $key => $value) {
            if (in_array($key, $object->hidden)) {
                unset($data[$key]);
            }
        }
        $object->nevs_raw_data = $object::PrepareDataFromDB($data);
        return $object;
    }

    private static function PrepareDataForDB(array $data): array
    {
        global $DB;

        $class = get_called_class();
        $object = new $class();

        $model = $object::GetModel();
        foreach ($data as $field => $value) {
            if (!isset($model[$field])) {
                unset($data[$field]);
                continue;
            }
            $field_settings = $model[$field];
            switch ($field_settings['type']) {
                case 'datetime':
                    if ($value instanceof \DateTime) {
                        $data[$field] = $value->format('Y-m-d H:i:s');
                    } else {
                        $data[$field] = null;
                    }
                    break;
                case 'date':
                    if ($value instanceof \DateTime) {
                        $data[$field] = $value->format('Y-m-d');
                    } else {
                        $data[$field] = null;
                    }
                    break;
                case 'json':
                    $data[$field] = json_encode($value);
                    break;
                case 'bool':
                    $data[$field] = $value ? 1 : 0;
                    break;
            }
        }

        return $data;
    }

    private static function PrepareDataFromDB(array $data): array
    {
        global $DB;

        $class = get_called_class();
        $object = new $class();

        $model = $object::GetModel();
        foreach ($data as $field => $value) {
            if (!isset($model[$field])) {
                unset($data[$field]);
                continue;
            }
            $field_settings = $model[$field];
            switch ($field_settings['type']) {
                case 'datetime':
                    if ($value !== null) {
                        $data[$field] = \DateTime::createFromFormat('Y-m-d H:i:s', $value);
                    }
                    break;
                case 'date':
                    if ($value !== null) {
                        $data[$field] = \DateTime::createFromFormat('Y-m-d', $value);
                    }
                    break;
                case 'json':
                    $data[$field] = json_decode($value, true);
                    break;
                case 'bool':
                    $data[$field] = $value == 1;
                    break;
            }
        }

        return $data;
    }

    public function __get($varName)
    {
        if (array_key_exists($varName, $this->nevs_raw_data)) {
            return $this->nevs_raw_data[$varName];
        }
        return null;
    }

    public function jsonSerialize(): mixed
    {
        return $this->nevs_raw_data;
    }
}