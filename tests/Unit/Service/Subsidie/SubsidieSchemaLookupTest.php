<?php

/**
 * SubsidieSchemaLookup Unit Tests.
 *
 * Pins where a subsidie service's records live: the ObjectService and the
 * configured register and schema, and the two refusals when OpenRegister is
 * absent or the register or schema is not configured.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Subsidie
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Subsidie;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Subsidie\SubsidieSchemaLookup;
use OCP\AppFramework\OCS\OCSBadRequestException;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * Tests for SubsidieSchemaLookup.
 */
class SubsidieSchemaLookupTest extends TestCase {
	/**
	 * The settings double, answering the given ObjectService and config values.
	 *
	 * @param object|null           $objectService The ObjectService, or null when OpenRegister is absent.
	 * @param array<string, string> $config        Config values by key.
	 *
	 * @return SettingsService
	 */
	private function settings(?object $objectService, array $config): SettingsService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($config[$key] ?? '')
		);

		return $settings;
	}//end settings()

	/**
	 * A configured schema answers the ObjectService, the register and the schema.
	 *
	 * @return void
	 */
	public function testResolvesTheConfiguredRegisterAndSchema(): void {
		$objectService = new stdClass();
		$lookup = new SubsidieSchemaLookup($this->settings($objectService, ['register' => 'r-1', 'bewijsstuk_schema' => 's-1']));

		self::assertSame([$objectService, 'r-1', 's-1'], $lookup->resolve('bewijsstuk_schema', 'Bewijsstuk-schema is niet geconfigureerd'));
	}//end testResolvesTheConfiguredRegisterAndSchema()

	/**
	 * Without OpenRegister the lookup refuses before reading any config.
	 *
	 * @return void
	 */
	public function testRefusesWithoutOpenRegister(): void {
		$lookup = new SubsidieSchemaLookup($this->settings(null, ['register' => 'r-1', 'bewijsstuk_schema' => 's-1']));

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('OpenRegister is niet beschikbaar');
		$lookup->resolve('bewijsstuk_schema', 'Bewijsstuk-schema is niet geconfigureerd');
	}//end testRefusesWithoutOpenRegister()

	/**
	 * A missing register or schema refuses with the caller's own message.
	 *
	 * @return void
	 */
	public function testRefusesAnUnconfiguredSchemaWithTheCallersMessage(): void {
		foreach ([['register' => 'r-1'], ['bewijsstuk_schema' => 's-1']] as $config) {
			$lookup = new SubsidieSchemaLookup($this->settings(new stdClass(), $config));
			try {
				$lookup->resolve('bewijsstuk_schema', 'Bewijsstuk-schema is niet geconfigureerd');
				self::fail('An unconfigured schema must be refused.');
			} catch (OCSBadRequestException $e) {
				self::assertSame('Bewijsstuk-schema is niet geconfigureerd', $e->getMessage());
			}
		}
	}//end testRefusesAnUnconfiguredSchemaWithTheCallersMessage()
}//end class
