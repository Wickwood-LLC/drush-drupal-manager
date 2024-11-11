<?php

namespace Drush\drush_drupal_manager;

use Symfony\Component\Finder\SplFileInfo;
use Archive_Tar;
use Symfony\Component\Yaml\Yaml;

class BackupFileInfo {
  const BACKUP_METADATA_FILENAME = '.ddm-backup.info.yml';
  const DATETIME_FORMAT = 'Y-m-d-H-i-s';
  const BACKUP_EXTENSION = '.ddm.tar.gz';

  public $file;

  protected $site_name;

  protected $platform;

  /**
   * @var \DateTime
   */
  protected $date_time;

  protected $title;

  public function __construct(string $site_name, SplFileInfo $file) {
    $this->file = $file;
    $this->site_name = $site_name;

    $base_name = $file->getBasename();

    $matches = NULL;
    if (preg_match('/' . preg_quote($site_name) . '\-\-(.*?)\-\-(\d{4}\-\d{2}\-\d{2}\-\d{2}\-\d{2}\-\d{2})(\-UTC)?\-\-(.*)' . preg_quote(static::BACKUP_EXTENSION) . '/', $base_name, $matches)) {
      $this->platform = $matches[1];
      if (!empty($matches[3])) {
        $timezone = new \DateTimeZone('UTC');
      }
      else {
        $timezone = NULL;
      }
      $this->date_time = \DateTime::createFromFormat(self::DATETIME_FORMAT, $matches[2], $timezone);
      $this->title = $matches[4];

      $backup_tar = new Archive_Tar($this->file->getPathname());
      /** @var string $backup_metadata_string */
      $backup_metadata_string = $backup_tar->extractInString(static::BACKUP_METADATA_FILENAME);
      if ($backup_metadata_string) {
        $backup_meta_data = Yaml::parse($backup_metadata_string);

        if (!empty($backup_meta_data['title'])) {
          $this->title = $backup_meta_data['title'];
        }
      }

    }
    else {
      throw new \Exception('Not a DDM backup file.');
    }
  }

  public function getPlatform() {
    return $this->platform;
  }

  public function getBasename() {
    return $this->file->getBasename();
  }

  public function getName() {
    return $this->title;
  }

  public function __toString() {
    $file_size = static::humanFileSize($this->file->getSize());
    return "Backup File: {$this->getBasename()}\n\tTitle: <fg=yellow;options=bold>{$this->title}</>\n\tPlatform: {$this->platform}\n\tSize: {$file_size}\n\tDate and Time: {$this->date_time->format('Y-m-d H:i:s e')}";
  }

  public static function humanFileSize($size, $unit="") {
    if( (!$unit && $size >= 1<<30) || $unit == "GB")
      return number_format($size/(1<<30),2)."GB";
    if( (!$unit && $size >= 1<<20) || $unit == "MB")
      return number_format($size/(1<<20),2)."MB";
    if( (!$unit && $size >= 1<<10) || $unit == "KB")
      return number_format($size/(1<<10),2)."KB";
    return number_format($size)." bytes";
  }

  public static function getBackupFileName($site_name, $platform_name, $title, $timestamp_string, $db_only = FALSE) {
    // Remove anything which isn't a word, whitespace, number
    // or any of the following caracters -_~,;[]().
    // If you don't need to handle multi-byte characters
    // you can use preg_replace rather than mb_ereg_replace
    // Thanks @Łukasz Rysiak!
    $filename_safe_description = mb_ereg_replace("([^\w\s\d\-_~,;\[\]\(\).])", '', $title);
    // Remove any runs of periods (thanks falstro!)
    $filename_safe_description = mb_ereg_replace("([\.]{2,})", '', $filename_safe_description);
    // Replace spaces with hyphens
    $filename_safe_description = mb_ereg_replace("\s+", '-', $filename_safe_description);
    // Replace double hyphen with single as double hyphens have special purpose in backup file names.
    $filename_safe_description = mb_ereg_replace('\-{2,}', '-', $filename_safe_description);

    $backup_file_name = $site_name . '--' . $platform_name . '--' . $timestamp_string . '--' . $filename_safe_description;
    if ($db_only) {
      $backup_file_name .= '.sql';
    }
    else {
      $backup_file_name .= '.tar';
    }
    return $backup_file_name;
  }

  public static function validBackupFile(SplFileInfo $file) {
    return (bool) preg_match('/(.*?)\-\-(.*?)\-\-(\d{4}\-\d{2}\-\d{2}\-\d{2}\-\d{2}\-\d{2})\-\-(.*)' . preg_quote(static::BACKUP_EXTENSION) . '/', $file->getBasename());
  }

  public static function getFromFile(SplFileInfo $file) {
    if (preg_match('/(.*?)\-\-(.*?)\-\-(\d{4}\-\d{2}\-\d{2}\-\d{2}\-\d{2}\-\d{2})\-\-(.*)' . preg_quote(static::BACKUP_EXTENSION) . '/', $file->getBasename(), $matches)) {
      return new BackupFileInfo($matches[1], $file);
    }
  }

  public static function getTimestampString() {
    $datetime = new \DateTime();
    $datetime->setTimezone(new \DateTimeZone('UTC'));

    return $datetime->format(static::DATETIME_FORMAT) . '-UTC';
  }
}
