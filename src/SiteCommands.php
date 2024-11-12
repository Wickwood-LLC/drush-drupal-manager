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
use Drush\drush_drupal_manager\SiteSettingsTrait;
use Drush\drush_drupal_manager\DatabaseTrait;
use Symfony\Component\Console\Helper\FormatterHelper;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\InputStream;

/**
 * Command file for setting-get.
 */
class SiteCommands extends DrushCommands {

  use ConfigTrait;
  use SiteSettingsTrait;
  use DatabaseTrait;

  const DB_DUMB_FILE_NAME = 'database.ddm.sql';

  public function dbOptions() {

    $options = [
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
    return $options;
  }
  /**
   * Create backup for the site
   */
  #[CLI\Command(name: 'site:backup', aliases: [])]
  #[CLI\Argument(name: 'description', description: 'Describe the backup. It will become part of the backup file so make it not too long.')]
  public function get($description) {
    $message = NULL;
    $backup_dir = $this->validateBackupDir($message);
    if (!$backup_dir) {
      $this->io()->error($message);
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

    $options = $this->dbOptions();
    $options['result-file'] = Path::join($temporary_file, static::DB_DUMB_FILE_NAME);

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

  /**
   * Restore a backup to the site
   */
  #[CLI\Command(name: 'site:restore', aliases: [])]
  public function restore() {
    $message = NULL;
    $backup_dir = $this->validateBackupDir($message);
    if (!$backup_dir) {
      $this->io()->error($message);
      return static::EXIT_FAILURE;
    }

    $bootstrapManager = Drush::bootstrapManager();
    $bootstrapManager->doBootstrap(DrupalBootLevels::FULL);
    $root = $bootstrapManager->getRoot();

    $platform_name = basename($root);

    $site_path = \Drupal::getContainer()->getParameter('site.path');
    $site_full_path = Path::join($root, $site_path);

    $site_name = basename($site_full_path);

    $finder = new Finder();
    $finder->name( $site_name . '--' . $platform_name . '--*.ddm.tar.gz');

    $finder->depth('== 0'); // Not to search within subdirectories.
    $finder->sortByModifiedTime();
    $finder->reverseSorting();

    $backups = [];

    foreach ($finder->in($backup_dir) as $file) {
      $backup_file_info = new BackupFileInfo($site_name, $file);
      $backups[] = $backup_file_info;
    }

    if (!empty($backups)) {
      $question = new ChoiceQuestion(
          'Please choose a backup to be restored',
          // choices can also be PHP objects that implement __toString() method
          array_merge(['Cancel'],  $backups),
          0
      );
      $question->setErrorMessage('Selection %s is invalid.');

      $selected_backup_file_info = $this->io()->askQuestion($question );

      if (is_string($selected_backup_file_info)) {
          $this->io()->writeln('<comment>Operation has been cancelled.</comment>');
      }
      else {
        /** @var \Drush\drush_drupal_manager\BackupFileInfo $selected_backup_file_info */
        // $selected_backup_file_info = $backups[$selected_option];
        $file_system = new Filesystem();
        $this->io()->writeln('You have just selected:');
        $this->io()->writeln($selected_backup_file_info);

        $backup_file_path = Path::join($backup_dir, $selected_backup_file_info->getBasename());

        $backup_file_path_tar = $this->gunzip($backup_file_path);
        $extract_dir = $this->untar($backup_file_path_tar, $site_full_path . '-extract');

        // $file_system->remove(Path::join($site_full_path, 'files'));
        // $file_system->remove(Path::join($site_full_path, 'private'));
  
        $old_site_path = $site_full_path . '-old';
        // Delete -odl suffix directory if existing.
        $file_system->remove($old_site_path);

        // Rename orginal site directory to add -old suffix.
        $file_system->rename($site_full_path, $old_site_path);
        // Rename extracted directory as site directory.
        $file_system->rename($extract_dir, $site_full_path);

        $settings = $this->getSettingsFromFile(Path::join($old_site_path, 'settings.php'));

        $drushrc_file_path = Path::join($site_full_path, 'drushrc.php');
        if ($file_system->exists($drushrc_file_path)) {
          $this->io()->writeln('Updating drushrc.php file ...');
          $this->updateDrushRC($drushrc_file_path, $settings);
        }

        $this->io()->writeln('Clearing database content ...');
        $this->clearDatabase($settings);

        $options = $this->dbOptions();
        $sql = SqlBase::create($options);

        $db_input = new InputStream();

        $sql = SqlBase::create($options);
        $process = $this->processManager()->shell($sql->connect(), null, $sql->getEnv());
        
        $db_dump_file_path = Path::join($site_full_path, static::DB_DUMB_FILE_NAME);
        $gz = gzopen($db_dump_file_path, 'rb');

        $process->setInput(stream_get_contents($gz));
        $process->mustRun($process->showRealtime());

        $file_system->remove($db_dump_file_path);


        // $finder = new Finder();
        // $finder->in($extract_dir);
        // // $finder->exclude('modules');
        // // $finder->notPath('config/local.config.php');
        // $finder->notPath(static::DB_DUMB_FILE_NAME);
        // $finder->notPath('settings.php');
        // $finder->notPath('local.settings.php');
        // $finder->notPath('drushrc.php');

        // $this->io()->writeln('Restoring files from the backup ... ');
        // $file_system->mirror($extract_dir, $site_full_path, $finder, ['override' => TRUE]);

        // Delete -odl suffix directory.
        $file_system->remove($old_site_path);
      }
    }
  }

  /**
   * Unzip a gzip file.
   */
  public function gunzip($gz_file_path) {
    $target_file_path = rtrim($gz_file_path, '.gz');

    $gz = gzopen($gz_file_path, 'rb');

    if (file_exists($target_file_path)) {
      unlink($target_file_path);
    }
    $dest = fopen($target_file_path, 'wb');

    stream_copy_to_stream($gz, $dest);
    gzclose($gz);
    fclose($dest);
    return $target_file_path;
  }

  public function untar($tar_file_path, $target_directory) {
    // $p = new \PharData($backup_file_path);
    // $p->decompress(); // creates /path/to/my.tar

    $file_system = new Filesystem();
    if (!$target_directory) {
      $target_directory = rtrim($tar_file_path, '.tar');
    }
    if ($file_system->exists($target_directory)) {
      $this->io()->writeln('Remove extration directory');
      $file_system->remove($target_directory);
    }

    // // unarchive from the tar
    $phar = new \PharData($tar_file_path);
    $phar->extractTo($target_directory);
    return $target_directory;
  }
}
