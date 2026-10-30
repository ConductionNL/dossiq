<?php

/**
 * The shipped configuration screen finds what a package installed.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * @version GIT: <git-id>
 * @link https://conduction.nl
 * @spec openspec/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Starter\ShippedConfigurationService;
use OCA\Dossiq\Service\Starter\ShippedFingerprint;
use OCA\Dossiq\Service\Starter\ShippedSets;
use OCA\Dossiq\Service\Starter\StarterStore;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * The package installer stamps a row with its schema's id (165); the screen
 * asks by slug (caseType). On :8080 every installed package therefore read
 * "none of them came from a starter set" (r31 DqBeheerGeavanceerd).
 */
class ShippedLedgerSchemaSlugTest extends TestCase {

	/**
	 * The register behind the store.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * The service under test.
	 *
	 * @var ShippedConfigurationService
	 */
	private ShippedConfigurationService $shipped;

	/**
	 * Build the service over a store whose case type schema is id 165.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->register = new InMemoryRegister();
		$settings = $this->getMockBuilder(SettingsService::class)
			->disableOriginalConstructor()
			->onlyMethods(['getObjectService', 'getConfigValue'])
			->getMock();
		$settings->method('getObjectService')->willReturn($this->register);
		$settings->method('getConfigValue')->willReturnCallback(
						static fn (string $key, string $default = ''): string => [
				'register' => 'dossiq',
				'case_type_schema' => '165',
				'shipped_origin_schema' => 'shippedOrigin',
			][$key] ?? ''
		);
		$store = new StarterStore(settings: $settings, logger: new NullLogger());
		$this->shipped = new ShippedConfigurationService(
			store: $store,
			sets: new ShippedSets(),
			fingerprint: new ShippedFingerprint(),
			logger: new NullLogger(),
		);
	}//end setUp()

	/**
	 * A row stamped under the schema id is listed when the screen asks by slug.
	 *
	 * @return void
	 */
	public function testAPackageRowStampedByIdIsListedBySlug(): void {
		$caseType = ['id' => 'ct-vth', 'title' => 'Omgevingsvergunning regulier'];
		$this->register->seed(schema: '165', uuid: 'ct-vth', row: $caseType);

		self::assertTrue(
			condition: $this->shipped->stamp(set: 'vth', targetSchema: '165', targetObject: 'ct-vth', object: $caseType, version: '1.4')
		);

		$overview = $this->shipped->overview(targetSchema: 'caseType', objectsKey: 'case_type_schema');

		self::assertCount(expectedCount: 1, haystack: $overview);
		self::assertSame(expected: 'ct-vth', actual: $overview[0]['targetObject']);
		self::assertSame(expected: 'vth', actual: $overview[0]['set']);
		self::assertSame(expected: '1.4', actual: $overview[0]['setVersion']);
		self::assertSame(expected: ShippedConfigurationService::STATE_SHIPPED, actual: $overview[0]['state']);
	}//end testAPackageRowStampedByIdIsListedBySlug()

	/**
	 * A ledger row recorded under the id before this fix is still listed.
	 *
	 * @return void
	 */
	public function testALegacyRowRecordedByIdIsStillListed(): void {
		$caseType = ['id' => 'ct-woo', 'title' => 'Woo-verzoek'];
		$this->register->seed(schema: '165', uuid: 'ct-woo', row: $caseType);
		$this->register->seed(
			schema: 'shippedOrigin',
			uuid: 'ledger-1',
			row: [
				'set' => 'woo-verzoek',
				'setVersion' => '2.1',
				'targetSchema' => '165',
				'targetObject' => 'ct-woo',
				'fingerprint' => (new ShippedFingerprint())->hashOf(object: $caseType),
			]
		);

		$overview = $this->shipped->overview(targetSchema: 'caseType', objectsKey: 'case_type_schema');

		self::assertCount(expectedCount: 1, haystack: $overview);
		self::assertSame(expected: 'woo-verzoek', actual: $overview[0]['set']);
	}//end testALegacyRowRecordedByIdIsStillListed()
}//end class
