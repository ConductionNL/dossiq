<?php

/**
 * The tenant migration runs as a repair step for one release and reports what is left.
 *
 * Built on the real repair step and TenantMigrationService, with only the two
 * OpenRegister seams doubled.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MigrateTenantsToOrganisations;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\MakesTenantMigration;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Repair\MigrateTenantsToOrganisations
 *
 * @uses \OCA\Dossiq\Service\TenantMigrationService
 */
class MigrateTenantsToOrganisationsTest extends TestCase {
	use MakesTenantMigration;

	/**
	 * What the step wrote to its output: info and warning lines.
	 *
	 * @var array{info: array<int, string>, warning: array<int, string>}
	 */
	private array $said = ['info' => [], 'warning' => []];

	/**
	 * Run the real step over the given tenants.
	 *
	 * @param array<int, array<string, mixed>> $tenants The legacy tenant rows.
	 *
	 * @return void
	 */
	private function runStep(array $tenants): void {
		$output = $this->createMock(IOutput::class);
		$output->method('info')->willReturnCallback(function (string $line): void {
			$this->said['info'][] = $line;
		});
		$output->method('warning')->willReturnCallback(function (string $line): void {
			$this->said['warning'][] = $line;
		});

		(new MigrateTenantsToOrganisations(
			migration: $this->realMigration(tenants: $tenants),
			settingsService: $this->createMock(SettingsService::class),
			logger: $this->recordingLogger(),
		))->run($output);
	}

	/**
	 * The step migrates and reports what is left (REQ-TOO-001).
	 *
	 * @return void
	 */
	public function testTheRepairMigratesAndReportsWhatIsLeft(): void {
		$this->startOrganisations(existing: [$this->storedOrganisation(uuid: 'org-else', slug: 'gemeente-eemnes')]);

		$this->runStep(tenants: [
			['id' => 't-1', 'slug' => 'gemeente-baarn', 'status' => 'active', 'displayName' => 'Gemeente Baarn'],
			['id' => 't-2', 'slug' => 'gemeente-eemnes', 'status' => 'active', 'displayName' => 'Gemeente Eemnes'],
		]);

		$this->assertSame(['t-1'], array_map(static fn ($o): string => (string) $o->getUuid(), $this->organisations->inserted));
		$this->assertStringContainsString('unmigrated = 1', implode("\n", $this->said['info']));
		$this->assertCount(1, $this->said['warning'], 'a count above zero is a warning in the repair output');
		$this->assertContains(1, array_map(static fn (array $line): int => (int) ($line[1]['unmigrated'] ?? -1), $this->migrationWarnings), 'and in the log, at warning level');
	}

	/**
	 * A refused collision is counted as unmigrated and never mapped (REQ-TOO-001).
	 *
	 * @return void
	 */
	public function testARefusedCollisionIsCountedAsUnmigrated(): void {
		$this->startOrganisations(existing: [$this->storedOrganisation(uuid: 'org-else', slug: 'gemeente-baarn')]);

		$this->runStep(tenants: [['id' => 't-3', 'slug' => 'gemeente-baarn', 'status' => 'active', 'displayName' => 'Gemeente Baarn']]);

		$this->assertSame([], $this->organisations->inserted, 'a refused collision is never mapped');
		$this->assertStringContainsString('refused 1', implode("\n", $this->said['info']));
		$this->assertStringContainsString('unmigrated = 1', implode("\n", $this->said['info']));
	}

	/**
	 * A second run migrates nothing and reports zero (REQ-TOO-001).
	 *
	 * @return void
	 */
	public function testASecondRunMigratesNothingAndReportsZero(): void {
		$this->startOrganisations(existing: []);
		$tenants = [['id' => 't-1', 'slug' => 'gemeente-baarn', 'status' => 'active', 'displayName' => 'Gemeente Baarn']];

		$this->runStep(tenants: $tenants);
		$this->said = ['info' => [], 'warning' => []];
		$this->runStep(tenants: $tenants);

		$this->assertCount(1, $this->organisations->inserted, 'the second run must insert nothing');
		$this->assertStringContainsString('migrated 0', implode("\n", $this->said['info']));
		$this->assertStringContainsString('unmigrated = 0', implode("\n", $this->said['info']));
		$this->assertSame([], $this->said['warning']);
	}

	/**
	 * The step is registered under post-migration in appinfo/info.xml.
	 *
	 * @return void
	 */
	public function testTheMigrationStepIsRegistered(): void {
		$info = simplexml_load_file(dirname(__DIR__, 3).'/appinfo/info.xml');
		$this->assertNotFalse($info);
		$steps = array_map('strval', iterator_to_array($info->{'repair-steps'}->{'post-migration'}->step, false));

		$this->assertContains(MigrateTenantsToOrganisations::class, $steps);
	}
}
