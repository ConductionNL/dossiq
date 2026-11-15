<?php

/**
 * Unit tests for RoutingNote: the notes routing leaves on a case say what
 * happened in a fixed shape, a refused handover as an error and an intake
 * split as information listing only each failure's destination and reason.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Routing;

use OCA\Dossiq\Service\Routing\RoutingNote;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Routing\RoutingNote
 */
class RoutingNoteTest extends TestCase {

	public function testARefusedHandoverIsAnError(): void {
		$this->assertSame(
			['kind' => 'handover-refused', 'level' => 'error', 'team' => 'zuid', 'reason' => 'team_full'],
			RoutingNote::handoverRefused('zuid', 'team_full')
		);
	}//end testARefusedHandoverIsAnError()

	public function testAnIntakeSplitKeepsOnlyDestinationAndReasonOfEachFailure(): void {
		$this->assertSame(
			[
				'kind' => 'intake-split',
				'level' => 'info',
				'submission' => 'sub-1',
				'siblings' => ['case-2', 'case-3'],
				'failed' => [['destination' => 'noord', 'reason' => 'no_case_type']],
			],
			RoutingNote::intakeSplit('sub-1', ['case-2', 'case-3'], [['destination' => 'noord', 'reason' => 'no_case_type', 'trace' => 'internal']])
		);
		$this->assertSame([], RoutingNote::intakeSplit('sub-1', [], [])['failed']);
	}//end testAnIntakeSplitKeepsOnlyDestinationAndReasonOfEachFailure()
}//end class
