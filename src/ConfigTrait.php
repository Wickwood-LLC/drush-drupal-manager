<?php

namespace Drush\drush_drupal_manager;

use Symfony\Component\Yaml\Yaml;

trait ConfigTrait {

    protected $ddm_config = NULL;

    public function getDDMConfig(): mixed {
        if (!$this->ddm_config) {
            $config_file_path = $_SERVER['HOME'] . '/.drush-drupal-manager.yml';
            if (file_exists($config_file_path)) {
                $this->ddm_config = Yaml::parseFile($config_file_path);
            }
            else {
                $config_file_path = $_SERVER['HOME'] . '/.wwm-app.yml';
                if (file_exists($config_file_path)) {
                    $this->ddm_config = Yaml::parseFile($config_file_path);
                }
            }
        }
        return $this->ddm_config;
    }

    public function getBackupDir() {
        $ddm_config = $this->getDDMConfig();
        return $ddm_config['backup-dir'];
    }


    public function validateBackupDir(&$message): string | bool {
        $backup_dir = $this->getBackupDir();
        if (empty($backup_dir)) {
            $message = "Backup directory is not configured!";
            return FALSE;
        }
        else if (!is_dir($backup_dir)) {
            $message = "Backup directory '$backup_dir' does not exist!";
            return FALSE;
        }
        return $backup_dir;
    }
}