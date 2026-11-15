<?php

/**
 * FindsConfiguredCase — the stored case read from the configured register.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\FindsConfiguredCase;
use OCA\Dossiq\Service\Support\ReadsConfiguredSource;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The case and the configured source, with and without configuration.
 *
 * @covers \OCA\Dossiq\Service\Support\FindsConfiguredCase
 * @covers \OCA\Dossiq\Service\Support\ReadsConfiguredSource
 */
final class FindsConfiguredCaseTest extends TestCase {

	/**
	 * A class using both traits over settings answering the given values.
	 *
	 * @param object|null $objectService The object service.
	 * @param string      $register      The register.
	 *
	 * @return object
	 */
	private function subject(?object $objectService, string $register): object {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnMap([['register', '', $register], ['case_schema', '', 'case'], ['case_type_schema', '', 'caseType'], ['unknown_schema', '', '']]);

		return new class($settings) {
			use FindsConfiguredCase {
				find as public;
			}
			use ReadsConfiguredSource {
				configuredSource as public;
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
	 * A configured case is read from its register and schema.
	 *
	 * @return void
	 */
	public function testFindsTheCase(): void {
		$subject = $this->subject(new stdClass(), 'reg');

		self::assertSame(['register' => 'reg', 'schema' => 'case', 'id' => 'c-1'], $subject->find('c-1'));
		self::assertNull($subject->find(''));
		self::assertNull($this->subject(null, 'reg')->find('c-1'));
		self::assertNull($this->subject(new stdClass(), '')->find('c-1'));
	}//end testFindsTheCase()

	/**
	 * The source answers all three, or null when one is missing.
	 *
	 * @return void
	 */
	public function testConfiguredSource(): void {
		$objectService = new stdClass();

		self::assertSame([$objectService, 'reg', 'caseType'], $this->subject($objectService, 'reg')->configuredSource('case_type_schema'));
		self::assertNull($this->subject($objectService, 'reg')->configuredSource('unknown_schema'));
		self::assertNull($this->subject(null, 'reg')->configuredSource('case_schema'));
		self::assertNull($this->subject($objectService, '')->configuredSource('case_schema'));
	}//end testConfiguredSource()
}//end class
