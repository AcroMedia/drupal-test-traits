<?php

namespace Acromedia\DrupalTestTraits;

use Drupal\Component\Utility\Crypt;
use Drupal\Core\Command\DbDumpCommand;
use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use Drupal\Core\Test\FunctionalTestSetupTrait;
use Drupal\user\Entity\User;
use Drush\TestTraits\DrushTestTrait;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Use for speedy test runs.
 *
 * @todo Add documentation about usage.
 */
trait ProfileTestTrait {
  use DrushTestTrait;
  use FunctionalTestSetupTrait;

  /**
   * Checks that the profile is the expected profile.
   *
   * @param string $profile
   *   The profile being used by the test.
   *
   * @throws \RuntimeException
   *   Thrown if the profile is not the expected profile.
   */
  abstract protected function checkProfile(string $profile): void;

  /**
   * Gets the path to the config sync directory to write to settings.php.
   *
   * @return string
   *   The path to the config sync directory to write to settings.php.
   */
  abstract protected function getConfigSyncPath(): string;

  /**
   * Gets the database dump path.
   *
   * @return string|NULL
   *   The database dump path. If this returns NULL then there is no database
   *   dump to be used and the trait will create one if ACRO_TEST_DB_PATH is
   *   set.
   */
  abstract public static function getDatabaseDumpPath(): ?string;

  /**
   * Additional settings to be written to settings.php.
   *
   * For example the implementation could local something like:
   * @code
   * $settings['settings']['encrypted_file_path'] = (object) [
   *   'value' => $this->privateFilesDirectory,
   *   'required' => TRUE,
   * ];
   * return $settings;
   * @endcode
   *
   * @return array
   *   The additional settings to set. See code example if method documentation
   *   for the expected structure.
   *
   * @see drupal_rewrite_settings()
   */
  abstract protected function getAdditionalSettings(): array;

  /**
   * React early to the site being install.
   *
   * This allows you to create things necessary for database updates.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   */
  abstract protected function preInitConfig(ContainerInterface $container): void;

  /**
   * React after configuration is initialized and after any database updates.
   *
   * This allows you to set configuration.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   */
  abstract protected function postInitConfig(ContainerInterface $container): void;

  /**
   * React after a site has been updated.
   *
   * This allows you to do any post deployment steps you'd do on live. For
   * example, updating translations.
   *
   * @param \Symfony\Component\DependencyInjection\ContainerInterface $container
   *   The container.
   */
  abstract protected function postUpdateStep(ContainerInterface $container): void;

  /**
   * Installs Telus EVS profile from db dump if available.
   */
  protected function doInstall() {
    $this->checkProfile($this->profile);

    // Determine where the database is coming from.
    $optimised_dump_path = $this->getOptimisedDumpPath();
    if (!$optimised_dump_path || !file_exists($optimised_dump_path)) {
      $dump_stored_in_codebase = call_user_func([$this, 'getDatabaseDumpPath']);
      if ($dump_stored_in_codebase === NULL) {
        return parent::doInstall();
      }
      $db_dump_path = "compress.zlib://" . $dump_stored_in_codebase;
    }
    else {
      $db_dump_path = $optimised_dump_path;
    }

    // Generate a hash salt.
    $settings['settings']['hash_salt'] = (object) [
      'value'    => Crypt::randomBytesBase64(55),
      'required' => TRUE,
    ];
    $settings['settings']['config_sync_directory'] = (object) [
      'value'    => $this->getConfigSyncPath(),
      'required' => TRUE,
    ];

    // Since the installer isn't run, add the database settings here too.
    $settings['databases']['default'] = (object) [
      'value' => Database::getConnectionInfo(),
      'required' => TRUE,
    ];
    $this->writeSettings($settings);
    if (!is_dir($this->tempFilesDirectory)) {
      mkdir($this->tempFilesDirectory);
    }
    require $db_dump_path;
  }

  /**
   * {@inheritdoc}
   */
  protected function prepareSettings() {
    parent::prepareSettings();
    $settings = $this->getAdditionalSettings();
    if (!empty($settings)) {
      $this->writeSettings($settings);
      // Since Drupal is bootstrapped already, install_begin_request() will not
      // bootstrap again. Hence, we have to reload the newly written custom
      // settings.php manually.
      Settings::initialize(DRUPAL_ROOT, $this->siteDirectory, $this->classLoader);
    }
  }

  /**
   * {@inheritdoc}
   */
  protected function initConfig(ContainerInterface $container) {
    require_once \Drupal::root() . '/core/includes/update.inc';

    // Allow site specific changes before running updates if necessary.
    $this->preInitConfig($container);

    // In a CI run skip these checks because we only need to do this once.
    if (!getenv('ACRO_TEST_SKIP_UPDATE_CHECK')) {
      // Run updates if necessary.
      drupal_load_updates();
      $has_updates = !empty(update_get_update_list()) || !empty(\Drupal::service('update.post_update_registry')->getPendingUpdateInformation());
      if ($has_updates) {
        $this->drush('updatedb', ['-y', '--no-cache-clear']);
        $this->rebuildAll();
      }
      $config_importer = $this->configImporter();
      if ($config_importer->hasUnprocessedConfigurationChanges()) {
        $config_importer->import();
        if (count($config_importer->getErrors())) {
          $errors = array_merge(['There were errors importing the configuration.'], $config_importer->getErrors());
          throw new \RuntimeException(implode(PHP_EOL, $errors));
        }
        $this->rebuildAll();
        $this->postUpdateStep();
      }
    }

    // Should we generate an optimised dump?
    $optimised_dump_path = $this->getOptimisedDumpPath();
    if ($optimised_dump_path && !file_exists($optimised_dump_path)) {
      // Create an optimised DB dump.
      $command = new DbDumpCommand();
      $command_tester = new CommandTester($command);
      $args = ['--schema-only' => 'cache.*,sessions,watchdog,ultimate_cron_log'];
      // Locally we use the default insert-count of 1000 so that data importing
      // works across more environments. On CI we don't need to be so concerned
      // about this and want the quickest loading possible. This option is added
      // by https://www.drupal.org/project/drupal/issues/3248679.
      if (getenv('CI')) {
        $args['--insert-count'] = 100000;
      }
      $command_tester->execute($args);
      file_put_contents($optimised_dump_path, $command_tester->getDisplay());
    }

    parent::initConfig($this->container);

    // Allow site specific changes after running updates if necessary.
    $this->postInitConfig($this->container );

    // Ensure the root user is valid.
    $password = $this->randomMachineName();
    $this->rootUser = User::load(1);
    $this->rootUser->setPassword($password);
    $this->rootUser->passRaw = $password;
    $this->rootUser->pass_raw = $password;
    $this->rootUser->save();
  }

  /**
   * Gets the optimised database dump path.
   *
   * @return string|null
   *   The optimised database dump path if ACRO_TEST_DB_PATH is set.
   */
  private function getOptimisedDumpPath(): ?string {
    $optimised_dump_path = getenv('ACRO_TEST_DB_PATH') ?: NULL;
    if ($optimised_dump_path && strpos($optimised_dump_path, 'COMMIT-HASH') !== FALSE) {
      $rev = @exec('git rev-parse --short HEAD') ?: 'unknown-hash';
      $optimised_dump_path = str_replace('COMMIT-HASH', $rev, $optimised_dump_path);
    }
    return $optimised_dump_path;
  }

}
