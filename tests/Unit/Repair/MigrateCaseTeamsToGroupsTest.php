<?php

/**
 * MigrateCaseTeamsToGroups: the upgrade runs the migration and names what is left.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MigrateCaseTeamsToGroups;
use OCA\Dossiq\Service\Team\CaseTeamMigration;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Repair\MigrateCaseTeamsToGroups
 */
class MigrateCaseTeamsToGroupsTest extends TestCase {

	/**
	 * The step applies, and warns with the command when cases are left.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-team-model/specs/role-routing-via-or-rbac/spec.md#requirement-existing-cases-move-to-the-group-their-role-names-req-team-03
	 */
	public function testItAppliesAndNamesTheCommandForWhatIsLeft(): void {
		$migration = $this->createMock(CaseTeamMigration::class);
		$migration->expects($this->once())->method('run')->with(true)->willReturn(
			[
				'ran' => true,
				'skippedBecause' => '',
				'converted' => 3,
				'alreadyGroup' => 1,
				'failed' => 0,
				'unmapped' => [
					['case' => 'c-1', 'title' => 'x', 'value' => 'uuid', 'reason' => CaseTeamMigration::REASON_NO_GROUP, 'roleName' => 'Team X', 'hints' => []],
				],
			]
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('3 cases moved'));
		$output->expects($this->once())->method('warning')->with($this->stringContains('occ dossiq:teams:migrate'));

		(new MigrateCaseTeamsToGroups(migration: $migration, logger: new NullLogger()))->run($output);
	}//end testItAppliesAndNamesTheCommandForWhatIsLeft()

	/**
	 * A migration that throws never fails the upgrade.
	 *
	 * @return void
	 */
	public function testAFailureIsAWarningNotABrokenUpgrade(): void {
		$migration = $this->createMock(CaseTeamMigration::class);
		$migration->method('run')->willThrowException(new RuntimeException('store down'));

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('store down'));

		(new MigrateCaseTeamsToGroups(migration: $migration, logger: new NullLogger()))->run($output);
	}//end testAFailureIsAWarningNotABrokenUpgrade()
	/**
	 * A migration that could not run says why, and warns about nothing.
	 *
	 * @return void
	 */
	public function testASkippedRunSaysWhy(): void {
		$migration = $this->createMock(CaseTeamMigration::class);
		$migration->method('run')->willReturn(
			['ran' => false, 'skippedBecause' => 'OpenRegister is not available', 'converted' => 0, 'alreadyGroup' => 0, 'failed' => 0, 'unmapped' => []]
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info')->with($this->stringContains('OpenRegister is not available'));
		$output->expects($this->never())->method('warning');

		$step = new MigrateCaseTeamsToGroups(migration: $migration, logger: new NullLogger());
		self::assertNotSame('', $step->getName());
		$step->run($output);
	}//end testASkippedRunSaysWhy()

	/**
	 * Nothing left over means no warning.
	 *
	 * @return void
	 */
	public function testNothingLeftMeansNoWarning(): void {
		$migration = $this->createMock(CaseTeamMigration::class);
		$migration->method('run')->willReturn(
			['ran' => true, 'skippedBecause' => '', 'converted' => 1, 'alreadyGroup' => 0, 'failed' => 0, 'unmapped' => []]
		);

		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('info');
		$output->expects($this->never())->method('warning');

		(new MigrateCaseTeamsToGroups(migration: $migration, logger: new NullLogger()))->run($output);
	}//end testNothingLeftMeansNoWarning()
}//end class
