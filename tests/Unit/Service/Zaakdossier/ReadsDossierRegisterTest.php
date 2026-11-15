<?php

/**
 * ReadsDossierRegister — the register and a configured schema, or an exception.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\Custody\DossierRegister;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\ReadsDossierRegister;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/**
 * The trait, through a minimal host class.
 *
 * @covers \OCA\Dossiq\Service\Zaakdossier\ReadsDossierRegister
 */
final class ReadsDossierRegisterTest extends TestCase {

	/**
	 * A configured schema is answered, an unset one throws, and the register comes from the dossier register.
	 *
	 * @return void
	 */
	public function testLookups(): void {
		$objectService = new \stdClass();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(static fn (string $key): string => ['register' => 'reg', 'document_schema' => 'doc'][$key] ?? '');
		$host = new class($settings) {
			use ReadsDossierRegister;

			/**
			 * The dossier register.
			 *
			 * @var DossierRegister
			 */
			private readonly DossierRegister $dossierRegister;

			/**
			 * Constructor.
			 *
			 * @param SettingsService $settingsService The settings.
			 */
			public function __construct(private readonly SettingsService $settingsService) {
				$this->dossierRegister = new DossierRegister(settingsService: $settingsService);
			}//end __construct()

			/**
			 * Expose the lookups.
			 *
			 * @param string $key The schema key, or '' for the register.
			 *
			 * @return mixed
			 */
			public function read(string $key): mixed {
				if ($key === '') {
					return $this->requireRegister();
				}

				return $this->schema(key: $key);
			}//end read()
		};

		self::assertSame([$objectService, 'reg'], $host->read(''));
		self::assertSame('doc', $host->read('document_schema'));
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('Dossier schema other_schema not configured');
		$host->read('other_schema');
	}//end testLookups()
}//end class
