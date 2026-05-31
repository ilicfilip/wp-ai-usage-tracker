<?php
/**
 * Shared base test case: installs the plugin schema and resets tables.
 *
 * @package WP_AI_Rate_Limiter
 */

/**
 * Base class for the plugin's integration tests.
 *
 * The schema is installed once in tests/bootstrap.php (outside any per-test
 * transaction). Row-level isolation between tests is provided by the
 * WP_UnitTestCase transaction that wraps each test and is rolled back in
 * tearDown — so every test sees empty plugin tables and a clean options table
 * without any explicit truncation here.
 */
abstract class AIUT_TestCase extends WP_UnitTestCase {
}
