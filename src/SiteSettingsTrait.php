<?php

namespace Drush\drush_drupal_manager;

use Symfony\Component\Filesystem\Filesystem;

trait SiteSettingsTrait {
    public function getSettingsFromFile($settings_file, $approot = NULL) {
        include $settings_file;
        return get_defined_vars();
    }

    public function updateDrushRC($drushrc_file_path, $settings) {
        $file_system = new Filesystem();

        $original_permission = fileperms($drushrc_file_path);

        chmod($drushrc_file_path, 0755);
        $database_params = $settings['databases']['default']['default'];
        $lines = file($drushrc_file_path);
        foreach ($lines as $index => $line) {
            if (preg_match('/^\$options\[\'db_passwd\'\]\s*\=\s*\'.*?\'\s*\;/', $line)) {
                $lines[$index] = "\$options['db_passwd'] = '{$database_params['password']}';\n";
            }
            elseif (preg_match('/^\$options\[\'db_name\'\]\s*\=\s*\'.*?\'\s*\;/', $line)) {
                $lines[$index] = "\$options['db_name'] = '{$database_params['database']}';\n";
            }
            elseif (preg_match('/^\$options\[\'db_user\'\]\s*\=\s*\'.*?\'\s*\;/', $line)) {
                $lines[$index] = "\$options['db_user'] = '{$database_params['username']}';\n";
            }
        }
        file_put_contents($drushrc_file_path, implode('', $lines));
        chmod($drushrc_file_path, $original_permission);
    }
}