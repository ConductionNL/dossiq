<?php

/**
 * The open-case sweep: built from settings only when the case register and schema are configured, searching open cases and patching as the system.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\OpenCaseSweep;
use OCA\Dossiq\Service\SettingsService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for OpenCaseSweep.
 *
 * @covers \OCA\Dossiq\BackgroundJob\OpenCaseSweep
 */
class OpenCaseSweepTest extends TestCase {

	/**
	 * Settings answering the given object service and configuration.
	 *
	 * @param object|null           $objectService The object service.
	 * @param array<string, string> $config        Configuration by key.
	 *
	 * @return SettingsService The settings.
	 */
	private function settings(?object $objectService, array $config): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key) use ($config): string {
				return ($config[$key] ?? '');
			}
		);

		return $settings;
	}//end settings()

	/**
	 * The sweep the given settings build.
	 *
	 * @param object|null           $objectService The object service.
	 * @param array<string, string> $config        Configuration by key.
	 *
	 * @return OpenCaseSweep|null The sweep, or null.
	 */
	private function sweep(?object $objectService, array $config): ?OpenCaseSweep {
		return OpenCaseSweep::ofSettings(settingsService: $this->settings(objectService: $objectService, config: $config));
	}//end sweep()

	/**
	 * Without an object service, register or case schema there is no sweep.
	 *
	 * @return void
	 */
	public function testIncompleteSettingsHaveNoSweep(): void {
		$service = new SweepObjectService();

		$this->assertNull(actual: $this->sweep(objectService: null, config: ['register' => 'z', 'case_schema' => 'c']));
		$this->assertNull(actual: $this->sweep(objectService: $service, config: ['case_schema' => 'c']));
		$this->assertNull(actual: $this->sweep(objectService: $service, config: ['register' => 'z']));
	}//end testIncompleteSettingsHaveNoSweep()

	/**
	 * A configured sweep carries the object service, register and case schema.
	 *
	 * @return void
	 */
	public function testAConfiguredSweepCarriesItsTarget(): void {
		$service = new SweepObjectService();

		$sweep = $this->sweep(objectService: $service, config: ['register' => 'zaken', 'case_schema' => 'zaak']);

		$this->assertSame(expected: $service, actual: $sweep?->objectService);
		$this->assertSame(expected: 'zaken', actual: $sweep?->register);
		$this->assertSame(expected: 'zaak', actual: $sweep?->schema);
	}//end testAConfiguredSweepCarriesItsTarget()

	/**
	 * Open cases are searched as non-final, non-draft, up to the limit.
	 *
	 * @return void
	 */
	public function testOpenCasesAreTheNonFinalNonDrafts(): void {
		$service = new SweepObjectService(rows: [['id' => 'case-1']]);
		$sweep   = $this->sweep(objectService: $service, config: ['register' => 'zaken', 'case_schema' => 'zaak']);

		$this->assertSame(expected: [['id' => 'case-1']], actual: $sweep?->openCases(limit: 50));
		$this->assertSame(
			expected: [['searchObjectsBySlug', 'zaken', 'zaak', ['isFinalStatus' => 0, 'isDraft' => 0, '_limit' => 50]]],
			actual: $service->calls
		);
	}//end testOpenCasesAreTheNonFinalNonDrafts()

	/**
	 * A patch is a partial write made inside the system context.
	 *
	 * @return void
	 */
	public function testAPatchIsAPartialWriteAsTheSystem(): void {
		$service = new SweepObjectService();
		$sweep   = $this->sweep(objectService: $service, config: ['register' => 'zaken', 'case_schema' => 'zaak']);

		$sweep?->patchAsSystem(caseId: 'case-1', changes: ['status' => 'closed']);

		$this->assertSame(
			expected: [['runAsSystem'], ['patchObject', 'case-1', ['status' => 'closed'], 'zaken', 'zaak', true]],
			actual: $service->calls
		);
	}//end testAPatchIsAPartialWriteAsTheSystem()
}//end class

/**
 * An object service that records its calls, and whether a patch ran as the system.
 */
class SweepObjectService {

	/**
	 * The calls made, in order.
	 *
	 * @var list<array<int, mixed>>
	 */
	public array $calls = [];

	/**
	 * Whether a system operation is running.
	 *
	 * @var boolean
	 */
	private bool $asSystem = false;

	/**
	 * Script the rows searches answer.
	 *
	 * @param list<array<string, mixed>> $rows The rows.
	 */
	public function __construct(private readonly array $rows = []) {
	}//end __construct()

	/**
	 * Search by slugs.
	 *
	 * @param string               $register The register.
	 * @param string               $schema   The schema.
	 * @param array<string, mixed> $filters  The filters.
	 *
	 * @return list<array<string, mixed>> The rows.
	 */
	public function searchObjectsBySlug(string $register, string $schema, array $filters): array {
		$this->calls[] = ['searchObjectsBySlug', $register, $schema, $filters];

		return $this->rows;
	}//end searchObjectsBySlug()

	/**
	 * Run an operation as the system.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return mixed What the operation returns.
	 */
	public function runAsSystem(callable $operation): mixed {
		$this->calls[]  = ['runAsSystem'];
		$this->asSystem = true;
		$result         = $operation();
		$this->asSystem = false;

		return $result;
	}//end runAsSystem()

	/**
	 * Patch an object, recording whether it ran as the system.
	 *
	 * @param string               $objectId The object.
	 * @param array<string, mixed> $data     The changes.
	 * @param int|string           $register The register.
	 * @param int|string           $schema   The schema.
	 *
	 * @return array<string, mixed> The patched object.
	 */
	public function patchObject(string $objectId, array $data, int|string $register, int|string $schema): array {
		$this->calls[] = ['patchObject', $objectId, $data, $register, $schema, $this->asSystem];

		return ['id' => $objectId] + $data;
	}//end patchObject()
}//end class
