<?php

/**
 * Repair-step tests for the CMMN case-plan drain.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Repair
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

namespace OCA\Dossiq\Tests\Unit\Repair;

use OCA\Dossiq\Repair\MigrateCmmnCasePlans;
use OCA\Dossiq\Service\CasePlanDrain\CasePlanMigrationService;
use OCP\Migration\IOutput;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Tests for {@see MigrateCmmnCasePlans}.
 *
 * @covers \OCA\Dossiq\Repair\MigrateCmmnCasePlans
 */
final class MigrateCmmnCasePlansTest extends TestCase {

	/**
	 * Each unmappable case is a warning, and the counts are one info line.
	 *
	 * @return void
	 */
	public function testUnmappableCasesAreWarnedAndCounted(): void {
		$migration = $this->createMock(CasePlanMigrationService::class);
		$migration->method('migrateAll')->with(false)->willReturn(
			[
				['caseId' => 'c-1', 'outcome' => 'migrated', 'reason' => 'verified'],
				['caseId' => 'c-2', 'outcome' => 'unmappable', 'reason' => 'orphan_item:x'],
			]
		);
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('c-2 kept its blob: orphan_item:x'));
		$output->expects($this->once())->method('info')->with($this->stringContains('1 migrated, 1 unmappable'));

		(new MigrateCmmnCasePlans($migration, $this->createMock(LoggerInterface::class)))->run($output);
	}//end testUnmappableCasesAreWarnedAndCounted()

	/**
	 * A drain that throws never fails the upgrade.
	 *
	 * @return void
	 */
	public function testAFailingDrainDoesNotStopTheUpgrade(): void {
		$migration = $this->createMock(CasePlanMigrationService::class);
		$migration->method('migrateAll')->willThrowException(new \RuntimeException('db gone'));
		$output = $this->createMock(IOutput::class);
		$output->expects($this->once())->method('warning')->with($this->stringContains('db gone'));

		(new MigrateCmmnCasePlans($migration, $this->createMock(LoggerInterface::class)))->run($output);
	}//end testAFailingDrainDoesNotStopTheUpgrade()
}//end class
