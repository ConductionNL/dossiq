<?php

/**
 * Migrate-tenants command test
 *
 * The command is the only part of this subsystem an operator actually runs,
 * and it decides two things nothing else does: what the run PRINTS, and what
 * it EXITS with. A migration that refuses a tenant and then exits 0 tells a
 * script the run succeeded, and the refusal scrolls past in a log nobody
 * reads.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Command
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/tenancy-onto-openregister-organisation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Command;

use OCA\Dossiq\Command\MigrateTenantsCommand;
use OCA\Dossiq\Service\SatelliteOrphanScanner;
use OCA\Dossiq\Service\TenantMigrationService;
use OCA\Dossiq\Tests\Support\MakesTenantMigration;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * @covers \OCA\Dossiq\Command\MigrateTenantsCommand
 * @uses \OCA\Dossiq\Service\TenantMigrationService
 * @uses \OCA\Dossiq\Service\Archival\ReadsConfiguredRows
 */
class MigrateTenantsCommandTest extends TestCase {
	use MakesTenantMigration;

	/**
	 * A migration summary with the given fields over the empty default.
	 *
	 * @param array<string, mixed> $over Fields to override.
	 *
	 * @return array<string, mixed> The summary.
	 */
	private function summary(array $over = []): array {
		return array_merge(
			[
				'migrated' => 0,
				'repaired' => 0,
				'skipped' => 0,
				'refused' => 0,
				'failed' => 0,
				'total' => 0,
				'mappings' => [],
				'collisions' => [],
			],
			$over
		);
	}

	/**
	 * An orphan report with the given fields over the empty default.
	 *
	 * @param array<string, mixed> $over Fields to override.
	 *
	 * @return array<string, mixed> The report.
	 */
	private function report(array $over = []): array {
		return array_merge(
			['scanned' => 0, 'orphans' => 0, 'templates' => 0, 'bySchema' => [], 'rows' => []],
			$over
		);
	}

	/**
	 * Run the command over a canned summary and report.
	 *
	 * @param array<string, mixed>|null $summary The migration summary, or null to throw.
	 * @param array<string, mixed>      $report  The orphan report.
	 *
	 * @return array{0: int, 1: string} The exit code and everything printed.
	 */
	private function runCommand(?array $summary, array $report = []): array {
		$migration = $this->createMock(TenantMigrationService::class);
		if ($summary === null) {
			$migration->method('migrate')->willThrowException(new RuntimeException('the store is down'));
		} else {
			$migration->method('migrate')->willReturn($summary);
		}

		$scanner = $this->createMock(SatelliteOrphanScanner::class);
		$scanner->method('reportOrphans')->willReturn($this->report($report));

		$command = new MigrateTenantsCommand($migration, $scanner);
		$output = new BufferedOutput();
		$code = $command->run(new ArrayInput([]), $output);

		return [$code, $output->fetch()];
	}

	/**
	 * A clean run exits 0 and prints every counter.
	 *
	 * The counters are the summary an operator reads, and `repaired` and
	 * `refused` are the two this change added. A counter that is computed and
	 * never printed is a measurement nobody receives.
	 *
	 * @return void
	 */
	public function testACleanRunExitsZeroAndPrintsEveryCounter(): void {
		[$code, $text] = $this->runCommand(
			$this->summary([
				'total' => 2,
				'migrated' => 1,
				'repaired' => 1,
				'mappings' => [['tenant' => 't-1', 'organisation' => 'org-1']],
			])
		);

		$this->assertSame(Command::SUCCESS, $code);
		foreach (['total', 'migrated', 'repaired', 'skipped', 'refused', 'failed'] as $counter) {
			$this->assertStringContainsString($counter, $text, "the summary must print {$counter}");
		}

		$this->assertStringContainsString('t-1 -> org-1', $text);
	}

	/**
	 * 🔴 A refused tenant FAILS the run.
	 *
	 * The whole reason the refusal exists is that acting on the report does
	 * the damage, not the migration. An operator who scripts
	 * `occ dossiq:migrate-tenants && rewrite-tenant-refs` must have the second
	 * command not run. Exiting 0 here would hand them a report containing a
	 * collision and tell them it was fine.
	 *
	 * @return void
	 */
	public function testARefusedTenantFailsTheRunAndNamesTheOrganisationInTheWay(): void {
		[$code, $text] = $this->runCommand(
			$this->summary([
				'total' => 1,
				'refused' => 1,
				'collisions' => [['tenant' => 't-1', 'slug' => 'gemeente-baarn', 'heldBy' => 'org-else']],
			])
		);

		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('REFUSED', $text);
		$this->assertStringContainsString('t-1', $text);
		$this->assertStringContainsString('gemeente-baarn', $text);
		$this->assertStringContainsString('org-else', $text, 'the operator needs to know WHICH organisation is in the way');
	}

	/**
	 * A failed row fails the run.
	 *
	 * @return void
	 */
	public function testAFailedRowFailsTheRun(): void {
		[$code] = $this->runCommand($this->summary(['total' => 1, 'failed' => 1]));

		$this->assertSame(Command::FAILURE, $code);
	}

	/**
	 * An orphan is reported and does NOT fail the run.
	 *
	 * A row pointing at a tenant that does not exist is something an operator
	 * has to look at, not a reason to refuse a migration that wrote the right
	 * rows. Failing on it would make every run of an instance with historic
	 * fixture data look broken, and an operator would start passing over the
	 * exit code, which is where a real refusal then goes unnoticed.
	 *
	 * @return void
	 */
	public function testAnOrphanIsReportedButDoesNotFailTheRun(): void {
		[$code, $text] = $this->runCommand(
			$this->summary(['total' => 1, 'migrated' => 1]),
			[
				'scanned' => 4,
				'orphans' => 1,
				'templates' => 3,
				'rows' => [['schema' => 'tenantQuota', 'row' => 'q-1', 'tenantRef' => 'ghost']],
			]
		);

		$this->assertSame(Command::SUCCESS, $code, 'an orphan is a thing to look at, not a failure');
		$this->assertStringContainsString('ORPHAN', $text);
		$this->assertStringContainsString('tenantQuota', $text);
		$this->assertStringContainsString('ghost', $text);
		$this->assertStringContainsString('Reported only', $text);
	}

	/**
	 * The shipped tier templates are named as templates, not as orphans.
	 *
	 * Twelve of them exist on every install. Printed as orphans they would
	 * open every report with twelve false alarms, which is how a real orphan
	 * goes unread.
	 *
	 * @return void
	 */
	public function testTheShippedTemplatesArePrintedApartFromOrphans(): void {
		[$code, $text] = $this->runCommand(
			$this->summary(),
			['scanned' => 12, 'orphans' => 0, 'templates' => 12]
		);

		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('templates = 12', $text);
		$this->assertStringContainsString('not orphans', $text);
		$this->assertStringNotContainsString('ORPHAN ', $text);
	}

	/**
	 * An orphan with an empty tenantRef prints as something readable.
	 *
	 * The empty string is the most likely wrong value and the least legible
	 * one: printed raw it reads as a truncated line rather than as a finding.
	 *
	 * @return void
	 */
	public function testAnOrphanWithNoTenantRefPrintsReadably(): void {
		[, $text] = $this->runCommand(
			$this->summary(),
			['scanned' => 1, 'orphans' => 1, 'rows' => [['schema' => 'tenantUser', 'row' => 'u-1', 'tenantRef' => '']]]
		);

		$this->assertStringContainsString('(empty)', $text);
	}

	/**
	 * A migration that throws fails the run and says what went wrong.
	 *
	 * @return void
	 */
	public function testAThrowingMigrationFailsTheRunAndReportsTheReason(): void {
		[$code, $text] = $this->runCommand(null);

		$this->assertSame(Command::FAILURE, $code);
		$this->assertStringContainsString('the store is down', $text);
	}

	/**
	 * The dry run reads everything and writes nothing (REQ-TOO-001).
	 *
	 * Driven through execute(), with TenantMigrationService real and only the
	 * two OpenRegister seams doubled. One tenant is new, and one already has
	 * an Organisation the June mapping left archived, which a real run would
	 * repair.
	 *
	 * @return void
	 */
	public function testTheDryRunWritesNothing(): void {
		$archived = $this->storedOrganisation(uuid: 't-2', slug: 'gemeente-eemnes', status: 'archived');
		$this->startOrganisations(existing: [$archived]);
		$migration = $this->realMigration(tenants: [
			['id' => 't-1', 'slug' => 'gemeente-baarn', 'status' => 'active', 'displayName' => 'Gemeente Baarn'],
			['id' => 't-2', 'slug' => 'gemeente-eemnes', 'status' => 'terminated', 'displayName' => 'Gemeente Eemnes'],
		]);

		[$code, $text] = $this->runRealCommand(migration: $migration, arguments: ['--dry-run' => true]);

		$this->assertSame([], $this->organisations->inserted, 'a dry run must insert no Organisation');
		$this->assertSame([], $this->organisations->updated, 'a dry run must update no Organisation');
		$this->assertSame('archived', $archived->getStatus(), 'a dry run must not even change the entity in memory');
		$this->assertSame(Command::SUCCESS, $code);
		$this->assertStringContainsString('nothing was written', $text);
		$this->assertStringContainsString('t-1 -> t-1', $text, 'the dry run must print the mapping a real run would make');
		$this->assertStringContainsString('repaired = 1', $text);
		$this->assertStringContainsString('unmigrated = 1', $text, 't-1 has no Organisation yet');
	}

	/**
	 * The dry run reports the collisions a real run would refuse (REQ-TOO-001).
	 *
	 * @return void
	 */
	public function testTheDryRunReportsTheCollisionsTheRealRunWouldRefuse(): void {
		$this->startOrganisations(existing: [$this->storedOrganisation(uuid: 'org-else', slug: 'gemeente-baarn')]);
		$migration = $this->realMigration(tenants: [
			['id' => 't-3', 'slug' => 'gemeente-baarn', 'status' => 'active', 'displayName' => 'Gemeente Baarn'],
		]);

		[$code, $text] = $this->runRealCommand(migration: $migration, arguments: ['--dry-run' => true]);

		$this->assertSame(Command::FAILURE, $code, 'a dry run exits as the real run would');
		$this->assertStringContainsString('REFUSED t-3', $text);
		$this->assertStringContainsString('org-else', $text);
		$this->assertStringContainsString('unmigrated = 1', $text);
		$this->assertSame([], $this->organisations->inserted);
	}

	/**
	 * Run the command over the real migration.
	 *
	 * @param TenantMigrationService $migration The migration.
	 * @param array<string, mixed>   $arguments The command line.
	 *
	 * @return array{0: int, 1: string} Exit code and output.
	 */
	private function runRealCommand(TenantMigrationService $migration, array $arguments): array {
		$scanner = $this->createMock(SatelliteOrphanScanner::class);
		$scanner->method('reportOrphans')->willReturn($this->report());

		$output = new BufferedOutput();
		$code = (new MigrateTenantsCommand($migration, $scanner))->run(new ArrayInput($arguments), $output);

		return [$code, $output->fetch()];
	}
}
