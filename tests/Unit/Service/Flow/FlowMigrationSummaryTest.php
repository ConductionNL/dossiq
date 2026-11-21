<?php

/**
 * The empty summary a flow migration answers with.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Flow
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
 * @spec openspec/changes/page-topology-cleanup/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Flow;

use OCA\Dossiq\Service\Flow\FlowMigrationSummary;
use PHPUnit\Framework\TestCase;

/**
 * Every count 0, no rows, and the note as given.
 *
 * @covers \OCA\Dossiq\Service\Flow\FlowMigrationSummary
 *
 * @spec openspec/changes/page-topology-cleanup/tasks.md
 */
class FlowMigrationSummaryTest extends TestCase {

	/**
	 * The summary carries the note and nothing else of substance, in a fixed key order.
	 *
	 * @return void
	 */
	public function testEmptySummaryCarriesTheNote(): void {
		$this->assertSame(
			expected: ['total' => 0, 'created' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0, 'rows' => [], 'note' => 'OpenRegister is not installed'],
			actual: FlowMigrationSummary::empty(note: 'OpenRegister is not installed')
		);
	}//end testEmptySummaryCarriesTheNote()

	/**
	 * A run about to start carries the empty note.
	 *
	 * @return void
	 */
	public function testEmptySummaryForARunAboutToStart(): void {
		$this->assertSame(expected: '', actual: FlowMigrationSummary::empty(note: '')['note']);
	}//end testEmptySummaryForARunAboutToStart()
}//end class
