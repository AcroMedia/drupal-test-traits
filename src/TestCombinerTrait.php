<?php

namespace Acromedia\DrupalTestTraits;

/**
 * A trait that runs all functions starting with doTest as a single test.
 */
trait TestCombinerTrait {

  /**
   * Runs all the methods in a test class that start with "doTest".
   *
   * This combines the tests so that use the same database and is more
   * performant. To run a single test use the TEST_FILTER environment variable.
   * For example:
   * @code
   *   TEST_FILTER=RedeemedPrimaryCode ./vendor/bin/phpunit -v web/modules/custom/telus_api/tests/src/Functional/Epp/Resource/FulfillmentUrlResourceTest.php --filter runAllTests
   * @endcode
   *
   * @test
   */
  public function runAllTests() {
    $run = FALSE;
    $test_filter = '/' . (getenv('TEST_FILTER') ?? '.*') . '/';
    foreach (get_class_methods($this) as $method) {
      if (stripos($method, 'doTest') === 0 && preg_match($test_filter, $method)) {
        $this->$method();
        $run = TRUE;
      }
    }
    if (!$run) {
      throw new \RuntimeException(sprintf('No tests run from %s as no methods begin with "doTest..." and match the pattern: %s', get_class($this), $test_filter));
    }
  }

}
