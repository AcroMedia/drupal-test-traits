# Drupal test traits

Provides useful traits for Drupal tests.

## Installation
`composer require --dev acromedia/drupal-test-traits`

## Provided traits
### Database Dump test trait
This trait can speed up testing by installing Drupal using database dump instead of running a regular profile install.

### Tear down error checking trait
Improved error checking for tests especially JavascriptTestBase tests.

### Test combiner trait
Run multitple tests against the same Drupal install. Beware of side effects!
___
Not to be confused with [https://gitlab.com/weitzman/drupal-test-traits](https://gitlab.com/weitzman/drupal-test-traits)
