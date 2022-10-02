<?php

namespace Nevs;

class Migrations
{
    public static function Migrate(bool $fresh = false, bool $output = true, array|null $config = null): void
    {
        date_default_timezone_set(Config::Get('timezone'));

        $DB = new Database();
        if ($config !== null) {
            $DB->LoadConfig($config);
        } else {
            $config = Config::Get('db');
        }
        $db = $DB->db;

        if ($handle = opendir(Config::Get('app_root') . $config['migrations_folder'])) {
            while (false !== ($file = readdir($handle))) {
                if (substr($file, -4) == '.php') {
                    require_once(Config::Get('app_root') . $config['migrations_folder']  . $file);
                }
            }
        }

        if ($fresh) {
            $query = "SHOW TABLES";
            $stmt = $db->prepare($query);
            $stmt->execute();
            $results = $stmt->get_result();
            $db->query("SET foreign_key_checks = 0");
            while ($table = $results->fetch_assoc()) {
                $db->query("DROP TABLE `" . mysqli_real_escape_string($db, $table['Tables_in_' . $config['database']]) . "`");
            }
            $db->query("SET foreign_key_checks = 1");
            if ($output) {
                echo("\e[31mdatabase emptied\n\r");
            }
        }

        $migrations_table_exists = false;
        $query = "SHOW TABLES LIKE '" . mysqli_real_escape_string($DB->db, $config['migrations_table']) . "'";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $results = $stmt->get_result();
        while ($table = $results->fetch_assoc()) {
            $migrations_table_exists = true;
        }
        if (!$migrations_table_exists) {
            $db->query("CREATE TABLE `" . mysqli_real_escape_string($db, $config['migrations_table']) . "` ( `id` INT NOT NULL AUTO_INCREMENT , `name` VARCHAR(255) NOT NULL , `time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP , PRIMARY KEY (`id`)) ENGINE = InnoDB;");
        }

        $model_table_exists = false;
        $query = "SHOW TABLES LIKE '" . mysqli_real_escape_string($DB->db, $config['model_table']) . "'";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $results = $stmt->get_result();
        while ($table = $results->fetch_assoc()) {
            $model_table_exists = true;
        }
        if (!$model_table_exists) {
            $db->query("CREATE TABLE `" . mysqli_real_escape_string($db, $config['model_table']) . "` ( `id` INT NOT NULL AUTO_INCREMENT , `table` VARCHAR(255) NOT NULL , `field` VARCHAR(255) NOT NULL , `type` VARCHAR(255) NOT NULL , `nullable` INT NOT NULL, `primary_key` int NOT NULL, `related_table` VARCHAR(255) NULL, `related_field` VARCHAR(255) NULL , PRIMARY KEY (`id`)) ENGINE = InnoDB;");
        }

        $queue_table_exists = false;
        $query = "SHOW TABLES LIKE '" . mysqli_real_escape_string($DB->db, $config['queue_table']) . "'";
        $stmt = $db->prepare($query);
        $stmt->execute();
        $results = $stmt->get_result();
        while ($table = $results->fetch_assoc()) {
            $queue_table_exists = true;
        }
        if (!$queue_table_exists) {
            $db->query("CREATE TABLE `" . mysqli_real_escape_string($db, $config['queue_table']) . "` ( `id` INT NOT NULL AUTO_INCREMENT , `queue` VARCHAR(255) NOT NULL, `command` VARCHAR(255) NOT NULL , `data` LONGTEXT NOT NULL , `processing` INT NOT NULL DEFAULT 0, PRIMARY KEY (`id`)) ENGINE = InnoDB;");
        }

        $migration_files = [];
        if ($handle = opendir(Config::Get('app_root') . $config['migrations_folder'])) {
            while (false !== ($file = readdir($handle))) {
                if ('.' === $file) continue;
                if ('..' === $file) continue;
                $full_path = '/' . $file;
                if (str_ends_with($file, '.php')) {
                    $migration_files[] = $file;
                }
            }
            closedir($handle);
        }
        sort($migration_files);
        $migrated = false;
        foreach ($migration_files as $migration_file) {
            $migration_name = substr($migration_file, 0, -4);
            $migration_resolved = false;
            $query = "SELECT * FROM `" . mysqli_real_escape_string($db, $config['migrations_table']) . "` WHERE `name`=?";
            $stmt = $db->prepare($query);
            $stmt->execute([$migration_name]);
            $results = $stmt->get_result();
            while ($migration = $results->fetch_assoc()) {
                $migration_resolved = true;
            }
            if (!$migration_resolved) {
                $migrated = true;
                if ($output) {
                    echo("\e[33m" . $migration_name . "\n\r");
                }
                $migration = new $migration_name();
                $migration->migrate($DB);

                $query = "INSERT INTO `" . mysqli_real_escape_string($db, $config['migrations_table']) . "` (`name`) VALUES(?)";
                $stmt = $db->prepare($query);
                $stmt->execute([$migration_name]);
            }
        }

        if (!$migrated && $output) {
            echo("\e[33mnothing to migrate\n\r");
        }

        if ($output) {
            echo("\e[32m---done---\e[39m\n\r");
        }
    }
}