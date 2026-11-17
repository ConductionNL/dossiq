<?php

/**
 * ResolvesSchemaStore — the object service, register and schema of a key.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\ResolvesSchemaStore;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The store, and null when OpenRegister or configuration is missing.
 *
 * @covers \OCA\Dossiq\Service\Support\ResolvesSchemaStore
 */
final class ResolvesSchemaStoreTest extends TestCase {

	/**
	 * The store as settings answering the given values resolve it.
	 *
	 * @param object|null $objectService The object service.
	 * @param string      $register      The register.
	 * @param string      $schema        The schema under `some_schema`.
	 *
	 * @return array<string, mixed>|null
	 */
	private function storeWith(?object $objectService, string $register, string $schema): ?array {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnMap([['register', '', $register], ['some_schema', '', $schema]]);
		if ($objectService === null) {
			$settings->expects(self::never())->method('getConfigValue');
		}

		$subject = new class($settings) {
			use ResolvesSchemaStore {
				schemaStore as public;
			}

			/**
			 * Constructor.
			 *
			 * @param SettingsService $settingsService The settings.
			 */
			public function __construct(private readonly SettingsService $settingsService) {
			}
		};

		return $subject->schemaStore('some_schema');
	}//end storeWith()

	/**
	 * All three configured answer the store; anything missing answers null.
	 *
	 * @return void
	 */
	public function testStore(): void {
		$objectService = new stdClass();

		self::assertSame(['objectService' => $objectService, 'register' => 'reg', 'schema' => 'sch'], $this->storeWith($objectService, 'reg', 'sch'));
		self::assertNull($this->storeWith(null, 'reg', 'sch'));
		self::assertNull($this->storeWith($objectService, '', 'sch'));
		self::assertNull($this->storeWith($objectService, 'reg', ''));
	}//end testStore()
}//end class
