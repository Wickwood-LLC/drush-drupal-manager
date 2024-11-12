<?php

namespace Drush\drush_drupal_manager;

use Doctrine\DBAL\DriverManager;

trait DatabaseTrait {
    public function clearDatabase($settings) {
        $params = $settings['databases']['default']['default'];

        $params['user'] = $params['username'];
        $params['dbname'] = $params['database'];

        if ($params['driver'] == 'mysql') {
            $params['driver'] = 'mysqli';
        }

        $db_connection = DriverManager::getConnection($params);

        $schema_manager = $db_connection->createSchemaManager();

        $tables = $schema_manager->listTables();

        foreach ($tables as $table) {
            $schema_manager->dropTable($table);
        }

        $views = $schema_manager->listViews();

        foreach ($views as $view) {
            $schema_manager->dropView($view->getName());
        }
    }
}