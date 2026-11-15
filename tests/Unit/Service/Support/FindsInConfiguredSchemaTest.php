<?php

/**
 * FindsInConfiguredSchema — one object of a configured schema.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\FindsInConfiguredSchema;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The object, and null without OpenRegister, configuration or an id.
 *
 * @covers \OCA\Dossiq\Service\Support\FindsInConfiguredSchema
 */
final class FindsInConfiguredSchemaTest extends TestCase {

	/**
	 * A class using the trait over settings answering the given values.
	 *
	 * @param object|null $objectService The object service.
	 * @param string      $schema        The schema under `x_schema`.
	 *
	 * @return object
	 */
	private function subject(?object $objectService, string $schema): object {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnMap([['register', '', 'reg'], ['x_schema', '', $schema]]);

		return new class($settings) {
			use FindsInConfiguredSchema {
				findInConfiguredSchema as public;
			}

			/**
			 * Constructor.
			 *
			 * @param SettingsService $settingsService The settings.
			 */
			public function __construct(private readonly SettingsService $settingsService) {
			}

			/**
			 * The canned read.
			 *
			 * @param object $objectService Unused.
			 * @param string $register      The register.
			 * @param string $schema        The schema.
			 * @param string $id            The id.
			 *
			 * @return array<string, string>
			 */
			private function findObjectAsArray(object $objectService, string $register, string $schema, string $id): array {
				return ['register' => $register, 'schema' => $schema, 'id' => $id];
			}
		};
	}//end subject()

	/**
	 * The object is read from the configured schema; anything missing answers null.
	 *
	 * @return void
	 */
	public function testFinds(): void {
		self::assertSame(['register' => 'reg', 'schema' => 'sch', 'id' => 'o1'], $this->subject(new stdClass(), 'sch')->findInConfiguredSchema('x_schema', 'o1'));
		self::assertNull($this->subject(new stdClass(), 'sch')->findInConfiguredSchema('x_schema', ''));
		self::assertNull($this->subject(new stdClass(), '')->findInConfiguredSchema('x_schema', 'o1'));
		self::assertNull($this->subject(null, 'sch')->findInConfiguredSchema('x_schema', 'o1'));
	}//end testFinds()
}//end class
