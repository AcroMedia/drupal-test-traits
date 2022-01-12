<?php

namespace Acromedia\DrupalTestTraits;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\dblog\Controller\DbLogController;
use Drupal\FunctionalJavascriptTests\WebDriverTestBase;
use PHPUnit\Runner\BaseTestRunner;

/**
 * Adds additional testing on test tear down.
 *
 * Any errors in the watchdog table will be reported and if it is a javascript
 * test that has failed a screenshot will be taken.
 */
trait TearDownErrorCheckingTrait {

  /**
   * {@inheritdoc}
   */
  protected function tearDown() : void {
    if ($this->mink && $this instanceof WebDriverTestBase) {
      $status = $this->getStatus();
      if ($status === BaseTestRunner::STATUS_ERROR || $status === BaseTestRunner::STATUS_WARNING || $status === BaseTestRunner::STATUS_FAILURE) {
        // Ensure we capture a screenshot at point of failure.
        $image_filename = $this->htmlOutputClassName . '-ERROR-' . $this->htmlOutputCounter . '-' . $this->htmlOutputTestId . '.jpg';
        $this->getSession()->resizeWindow(1024, 2048);
        $this->createScreenshot($this->htmlOutputDirectory . '/' . $image_filename);
        $this->getSession()->resizeWindow(1024, 768);
        file_put_contents($this->htmlOutputCounterStorage, $this->htmlOutputCounter++);
        $uri = $this->htmlOutputBaseUrl . '/sites/simpletest/browser_output/' . $image_filename;
        file_put_contents($this->htmlOutputFile, $uri . "\n", FILE_APPEND);
      }
    }
    /** @var \Drupal\Core\Database\Query\SelectInterface $query */
    $query = \Drupal::database()->select('watchdog', 'w')
      ->fields('w', ['message', 'variables']);
    $group = $query->orConditionGroup()
      ->condition('severity', 4, '<')
      ->condition('type', 'php');
    $query->condition($group);
    $query->groupBy('w.message');
    $query->groupBy('w.variables');

    $controller = DbLogController::create($this->container);

    // Build a regex so we can exclude errors.
    $regex = '/(^' . implode(')|(^', array_map(function ($message) {
        return preg_quote($message, '/');
      }, $this->getIgnoredErrors())) . ')/';

    // Check that there are no warnings in the log after installation.
    if ($query->countQuery()->execute()->fetchField()) {
      // Output all errors for modules tested.
      foreach ($query->execute()->fetchAll() as $row) {
        $message = Unicode::truncate(Html::decodeEntities(strip_tags($controller->formatMessage($row))), 256, TRUE, TRUE);
        if (!preg_match($regex, $message)) {
          $errors[] = $message;
        }
      }
      if (isset($errors)) {
        throw new \Exception("Errors found in watchdog table. If they are expected add them to \\Drupal\\Tests\\telus_evs\\Traits\\ProfileTestTrait::getIgnoredErrors()\n\n" . print_r($errors, TRUE));
      }
    }

    parent::tearDown();
  }

  /**
   * Returns an array of ignored errors.
   *
   * @return string[]
   *   A list of error log messages to ignore that are used in a regex. The
   *   regex tests if the error message starts with the string. The string will
   *   be passed to preg_quote() so any regex control characters will be quoted.
   */
  abstract protected function getIgnoredErrors() : array;

}