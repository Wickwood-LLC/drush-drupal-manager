<?php
namespace Drush\Commands\drush_drupal_manager;

use Archive_Tar;
use Drush\Commands\DrushCommands;
use Drush\Attributes as CLI;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Filesystem\Path;
use Symfony\Component\Finder\Finder;
use Drush\Drush;
use Drush\Boot\DrupalBootLevels;
use Drush\Sql\SqlBase;
use Drush\drush_drupal_manager\BackupFileInfo;
use Drush\drush_drupal_manager\ConfigTrait;
use Symfony\Component\Console\Helper\FormatterHelper;

/**
 * Command file for setting-get.
 */
class SiteCommands extends DrushCommands {

  use ConfigTrait;

  /**
   * Create backup for the site
   */
  #[CLI\Command(name: 'site:backup', aliases: [])]
  #[CLI\Argument(name: 'description', description: 'Describe the backup. It will become part of the backup file so make it not too long.')]
  public function get($description) {
    $backup_dir = $this->getBackupDir();
    if (empty($backup_dir)) {
      $this->io()->error("Backup directory is not configured!");
      return static::EXIT_FAILURE;
    }
    else if (!is_dir($backup_dir)) {
      $this->io()->error("Backup directory '$backup_dir' does not exist!");
      return static::EXIT_FAILURE;
    }

    $timestamp_string = BackupFileInfo::getTimestampString();
    $file_system = new Filesystem();
    $temporary_dir = \sys_get_temp_dir();
    $temporary_file = $file_system->tempnam($temporary_dir, 'ddm_');
    unlink($temporary_file);
    $file_system->mkdir($temporary_file);

    $bootstrapManager = Drush::bootstrapManager();
    $bootstrapManager->doBootstrap(DrupalBootLevels::FULL);
    $root = $bootstrapManager->getRoot();

    $platform_name = basename($root);

    $site_path = \Drupal::getContainer()->getParameter('site.path');
    $site_full_path = Path::join($root, $site_path);

    $site_name = basename($site_full_path);
    
    $finder = new Finder();
    $finder->in($site_full_path);
    // $finder->exclude('modules');
    // $finder->notPath('config/local.config.php');
    $this->io()->writeln('Copying files from new release ... ');
    $file_system->mirror($site_full_path, $temporary_file, $finder, ['override' => TRUE]);

    $options = [
      'result-file' => Path::join($temporary_file, 'database.ddm.sql'),
      'create-db' => false,
      'data-only' => false,
      'ordered-dump' => false,
      'gzip' => TRUE,
      'extra' => NULL,
      'extra-dump' => NULL,
      'format' => 'null',
      'skip-tables-key' => '',
      'skip-tables-list' => '',
      'structure-tables-key' => '',
      'structure-tables-list' => '',
      'tables-key' => '',
      'tables-list' => '',
    ];

    $this->io()->writeln('Creating database dump ... ');
    $sql = SqlBase::create($options);
    $return = $sql->dump();

    $backup_file_name = BackupFileInfo::getBackupFileName($site_name, $platform_name, $description, $timestamp_string);

    $tar_file_path = Path::join($temporary_dir, $backup_file_name);
    $this->io()->writeln('Creating archive ... ');
    $tar = new Archive_Tar($tar_file_path);
    $tar->addModify([$temporary_file], '', $temporary_file);

    $this->io()->writeln('Compressing the archive ... ');
    $gz_file_path = Path::join($backup_dir, basename($tar_file_path )) . '.gz';
    // Open the gz file (w9 is the highest compression)
    $fp = gzopen ($gz_file_path, 'w9');

    // Compress the file
    gzwrite ($fp, file_get_contents($tar_file_path));

    // Close the gz file and we're done
    gzclose($fp);
    $file_system->remove([$tar_file_path, $temporary_file]);

    $this->io()->success('Backup created "' . $gz_file_path . '" (' . FormatterHelper::formatMemory(filesize($gz_file_path)) . ')');
  }

}
