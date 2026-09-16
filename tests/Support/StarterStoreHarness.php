<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * A real StarterStore over a real in-memory register.
 *
 * 🔑 NOT A MOCK OF THE STORE. Every service in this change writes a row and
 * reads it back, sometimes through a second service, and several of the tests
 * exist precisely to say that what came out is what went in. A per-call
 * `willReturn` on StarterStore cannot express that: it answers the same row
 * whatever was written, so a save that dropped a field would pass. This builds
 * the real class over {@see InMemoryRegister}, so the slug resolution, the
 * filter handling and the `@self` unwrapping are the production ones.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Starter\StarterStore;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Builds a StarterStore whose rows live in an array.
 */
final class StarterStoreHarness {

	/**
	 * The rows every store built here reads and writes.
	 *
	 * @var InMemoryRegister
	 */
	public InMemoryRegister $register;

	/**
	 * The store under test.
	 *
	 * @var StarterStore
	 */
	public StarterStore $store;

	/**
	 * Build the harness.
	 *
	 * @param TestCase          $test        The test, for building the mock.
	 * @param array<int,string> $unconfigured Config keys that answer '' , so a
	 *                                        test can say what an unconfigured
	 *                                        schema does.
	 */
	public function __construct(TestCase $test, array $unconfigured = []) {
		$this->register = new InMemoryRegister();

		$settings = $test->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService', 'getConfigValue'])
			->getMock();

		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = '') use ($unconfigured): string {
				if (in_array($key, $unconfigured, true) === true) {
					return '';
				}

				if ($key === 'register') {
					return 'dossiq';
				}

				// The schema slug a config key names, derived rather than
				// listed: `case_type_schema` is `caseType`. Listing them would
				// mean a test that passes because the fixture forgot one.
				$slug = preg_replace('/_schema$/', '', $key);
				$slug = (string)$slug;

				return lcfirst(str_replace(' ', '', ucwords(str_replace('_', ' ', $slug))));
			}
		);

		$this->store = new StarterStore($settings, new NullLogger());
	}//end __construct()

	/**
	 * Seed one row.
	 *
	 * @param string               $schema The schema slug.
	 * @param string               $uuid   The row's uuid.
	 * @param array<string, mixed> $row    The row.
	 *
	 * @return void
	 */
	public function seed(string $schema, string $uuid, array $row): void {
		$this->register->seed(schema: $schema, uuid: $uuid, row: $row);
	}//end seed()
}//end class
