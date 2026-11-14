<?php

/**
 * The tally a team migration run reports.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Team
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Team;

use OCA\Dossiq\Service\Team\TeamMigrationReport;
use PHPUnit\Framework\TestCase;

/**
 * Counts per kind, the unmapped rows in order, and the report shape.
 *
 * @covers \OCA\Dossiq\Service\Team\TeamMigrationReport
 */
class TeamMigrationReportTest extends TestCase {

	public function testAnEmptyRunReportsZeroes(): void {
		$this->assertSame(
			['ran' => true, 'skippedBecause' => '', 'converted' => 0, 'alreadyGroup' => 0, 'failed' => 0, 'unmapped' => []],
			(new TeamMigrationReport())->toArray()
		);
	}

	public function testEachKindIsCountedAndUnmappedRowsAreKeptInOrder(): void {
		$report = new TeamMigrationReport();
		$report->count('converted');
		$report->count('converted');
		$report->count('alreadyGroup');
		$report->count('failed');
		$report->count('converted');
		$report->addUnmapped(['caseId' => 'c-1', 'reason' => 'unknown-team']);
		$report->addUnmapped(['caseId' => 'c-2', 'reason' => 'role-has-no-group']);

		$this->assertSame(
			[
				'ran' => true,
				'skippedBecause' => '',
				'converted' => 3,
				'alreadyGroup' => 1,
				'failed' => 1,
				'unmapped' => [['caseId' => 'c-1', 'reason' => 'unknown-team'], ['caseId' => 'c-2', 'reason' => 'role-has-no-group']],
			],
			$report->toArray()
		);
	}
}
