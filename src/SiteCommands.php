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
    $timestamp_string = BackupFileInfo::getTimestampString();
    $file_system = new Filesystem();
    $temporary_dir = \sys_get_temp_dir();
    $temporary_file = $file_system->tempnam($temporary_dir, 'ddm_');
    unlink($temporary_file);
    $file_system->mkdir($temporary_file);
    $this->io()->writeln($temporary_file);
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
      // 'extra' => self::REQ,
      // 'extra-dump' => self::REQ,
      'format' => 'null'
    ];
    $sql = SqlBase::create($options);
    $return = $sql->dump();
    $this->io()->writeln($return);

    $backup_file_name = BackupFileInfo::getBackupFileName($site_name, $platform_name, $description, $timestamp_string);

    $tar_file_path = Path::join($temporary_dir, $backup_file_name) . ".tar";
    $tar = new Archive_Tar($tar_file_path);

    $gz_file_path = Path::join($temporary_dir, $backup_file_name) . BackupFileInfo::BACKUP_EXTENSION;
    // Open the gz file (w9 is the highest compression)
    $fp = gzopen ($gz_file_path, 'w9');

    // Compress the file
    gzwrite ($fp, file_get_contents($tar_file_path));

    // Close the gz file and we're done
    gzclose($fp);
  }

}
