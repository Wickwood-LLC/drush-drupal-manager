<?php
namespace Drush\Commands\drupal_manager;

use Drupal\Core\Site\Settings;
use Drush\Commands\DrushCommands;
use Drush\Attributes as CLI;

/**
 * Command file for setting-get.
 */
class SiteCommands extends DrushCommands {

  /**
   * Create backup for the site
   */
  #[CLI\Command(name: 'site:backup', aliases: [])]
  #[CLI\Argument(name: 'description', description: 'Describe the backup. It will become part of the backup file so make it not too long.')]
  public function get($description) {
    print_r(Settings::get($setting));
  }

}
