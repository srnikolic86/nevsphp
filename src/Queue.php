<?php

namespace Nevs;

class Queue
{
    public static function Add(string $queue, string $command, array $data = []): void
    {
        global $DB;
        $DB->ExecuteInsert('INSERT INTO `' . mysqli_real_escape_string($DB->db, Config::Get('db.queue_table')) . '` (`queue`, `command`, `data`) VALUES(?, ?, ?)', [$queue, $command, json_encode($data)]);
    }

    public static function Process(string $queue_name): void
    {
        $DB = new Database();

        $queue = $DB->ExecuteSelect('SELECT * FROM `' . mysqli_real_escape_string($DB->db, Config::Get('db.queue_table')) . '` WHERE `queue`=?', [$queue_name]);

        foreach ($queue as $action) {
            if ($action['processing'] == 1) {
                die();
            }
            $DB->Execute('UPDATE `' . mysqli_real_escape_string($DB->db, Config::Get('db.queue_table')) . '` SET `processing`=1 WHERE id=?', [$action['id']]);
            $command_name = $action['command'];
            $command_class = "App\\Commands\\" . $command_name;
            $data = json_decode($action['data'], true);

            $command = new $command_class();
            $command->resolve($data);
        }

        $DB->Execute('DELETE FROM `' . mysqli_real_escape_string($DB->db, Config::Get('db.queue_table')) . '` WHERE `processing`=1 AND `queue`=?', [$queue_name]);
    }
}