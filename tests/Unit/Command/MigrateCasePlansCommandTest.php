<?php

/**
 * Command tests for `occ dossiq:cmmn:migrate-case-plans`.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Command
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
 *
 * @spec openspec/changes/retire-cmmn-caseplanstate/specs/retire-cmmn-caseplanstate/spec.md#requirement-req-rcmn-002-in-flight-cases-are-drained-losslessly
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Command;

use OCA\Dossiq\Command\MigrateCasePlansCommand;
use OCA\Dossiq\Service\CasePlanDrain\CasePlanMigrationService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Tests for {@see MigrateCasePlansCommand}.
 *
 * @covers \OCA\Dossiq\Command\MigrateCasePlansCommand
 */
final class MigrateCasePlansCommandTest extends TestCase {

	/**
	 * Under --strict an unmappable case makes the command exit non-zero.
	 *
	 * @return void
	 */
	public function testStrictExitsNonZeroOnAnUnmappableCase(): void {
		$migration = $this->createMock(CasePlanMigrationService::class);
		$migration->method('migrateAll')->willReturn([self::report(outcome: 'migrated'), self::report(outcome: 'unmappable', caseId: 'c-2')]);

		$output = new BufferedOutput();
		$exit = (new MigrateCasePlansCommand($migration))->run(new ArrayInput(['--strict' => true]), $output);

		$this->assertSame(1, $exit);
		$this->assertStringContainsString('unmappable c-2', $output->fetch());
	}//end testStrictExitsNonZeroOnAnUnmappableCase()

	/**
	 * Without --strict the same report exits 0, as the repair step does.
	 *
	 * @return void
	 */
	public function testWithoutStrictTheReportExitsZero(): void {
		$migration = $this->createMock(CasePlanMigrationService::class);
		$migration->method('migrateAll')->willReturn([self::report(outcome: 'unmappable')]);

		$this->assertSame(0, (new MigrateCasePlansCommand($migration))->run(new ArrayInput([]), new BufferedOutput()));
	}//end testWithoutStrictTheReportExitsZero()

	/**
	 * Named cases are migrated one by one, and --dry-run is passed through.
	 *
	 * @return void
	 */
	public function testNamedCasesAreMigratedOneByOneWithTheDryRunFlag(): void {
		$migration = $this->createMock(CasePlanMigrationService::class);
		$migration->expects($this->never())->method('migrateAll');
		$migration->expects($this->exactly(2))->method('migrateCaseById')
			->with($this->anything(), true)
			->willReturn(self::report(outcome: 'would_migrate'));

		$exit = (new MigrateCasePlansCommand($migration))->run(new ArrayInput(['case' => ['a', 'b'], '--dry-run' => true]), new BufferedOutput());

		$this->assertSame(0, $exit);
	}//end testNamedCasesAreMigratedOneByOneWithTheDryRunFlag()

	/**
	 * One report line.
	 *
	 * @param string $outcome The outcome.
	 * @param string $caseId  The case.
	 *
	 * @return array<string, mixed> The report.
	 */
	private static function report(string $outcome, string $caseId = 'c-1'): array {
		return ['caseId' => $caseId, 'outcome' => $outcome, 'reason' => 'r', 'created' => 0, 'existing' => 0];
	}//end report()
}//end class
