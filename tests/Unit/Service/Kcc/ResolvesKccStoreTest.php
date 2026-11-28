<?php

/**
 * ResolvesKccStore — the object service, register and schema of a KCC store.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Kcc;

use OCA\Dossiq\Service\Kcc\ResolvesKccStore;
use OCA\Dossiq\Service\SettingsService;
use OCP\AppFramework\OCS\OCSBadRequestException;
use PHPUnit\Framework\TestCase;

/**
 * A configured store answers its three parts; a missing part refuses.
 *
 * @covers \OCA\Dossiq\Service\Kcc\ResolvesKccStore
 */
final class ResolvesKccStoreTest extends TestCase {

	/**
	 * A class exposing the trait's step over settings answering the given values.
	 *
	 * @param object|null $objectService The object service, or null.
	 * @param string      $schema        The configured schema.
	 *
	 * @return object
	 */
	private function subject(?object $objectService, string $schema): object {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnMap([['register', '', 'reg'], ['callback_request_schema', '', $schema]]);

		return new class($settings) {
			use ResolvesKccStore {
				kccStore as public;
			}

			/**
			 * Hold the settings.
			 *
			 * @param SettingsService $settingsService The settings.
			 */
			public function __construct(private readonly SettingsService $settingsService) {
			}//end __construct()
		};
	}//end subject()

	/**
	 * A configured store answers the service, the register and the schema.
	 *
	 * @return void
	 */
	public function testConfiguredStore(): void {
		$service = new \stdClass();
		$store = $this->subject(objectService: $service, schema: 'cb')->kccStore('callback_request_schema', 'unset');

		self::assertSame(expected: [$service, 'reg', 'cb'], actual: $store);
	}//end testConfiguredStore()

	/**
	 * Without OpenRegister the refusal says so.
	 *
	 * @return void
	 */
	public function testMissingObjectServiceRefuses(): void {
		$this->expectException(exception: OCSBadRequestException::class);
		$this->expectExceptionMessage(message: 'OpenRegister is not available');
		$this->subject(objectService: null, schema: 'cb')->kccStore('callback_request_schema', 'unset');
	}//end testMissingObjectServiceRefuses()

	/**
	 * An unset schema refuses with the caller's sentence.
	 *
	 * @return void
	 */
	public function testUnsetSchemaRefusesWithTheSentence(): void {
		$this->expectException(exception: OCSBadRequestException::class);
		$this->expectExceptionMessage(message: 'KCC callback schema is not configured');
		$this->subject(objectService: new \stdClass(), schema: '')->kccStore('callback_request_schema', 'KCC callback schema is not configured');
	}//end testUnsetSchemaRefusesWithTheSentence()
}//end class
