<?php

/**
 * Live Case Retention Clocks Test
 *
 * Pins that the clocks are worked out over the LIVE case read, and that a case
 * that is not live answers null without the clocks being asked.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller\Support;

use OCA\Dossiq\Controller\Support\LiveCaseRetentionClocks;
use OCA\Dossiq\Kernel\Contracts\CaseRecycleServiceInterface;
use OCA\Dossiq\Kernel\Contracts\RetentionClocksInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

/**
 * Tests for LiveCaseRetentionClocks.
 */
#[CoversClass(LiveCaseRetentionClocks::class)]
final class LiveCaseRetentionClocksTest extends TestCase {
	/**
	 * The clocks are worked out over the payload of the live case with that id.
	 *
	 * @return void
	 */
	public function testTheClocksAreThoseOfTheLiveCase(): void {
		$case    = ['id' => 'case-9', 'identifier' => 'ZAAK-2026-0009'];
		$answer  = ['lawfulPurpose' => ['date' => '2024-01-31'], 'archive' => ['date' => '2034-01-31'], 'disagree' => true];
		$recycle = $this->createMock(originalClassName: CaseRecycleServiceInterface::class);
		$recycle->expects($this->once())->method('liveCase')->with('case-9')->willReturn($case);
		$clocks = $this->createMock(originalClassName: RetentionClocksInterface::class);
		$clocks->expects($this->once())->method('clocksFor')->with($case)->willReturn($answer);

		$read = new LiveCaseRetentionClocks(recycle: $recycle, clocks: $clocks);

		self::assertSame(expected: $answer, actual: $read->forCase(caseId: 'case-9'));
	}//end testTheClocksAreThoseOfTheLiveCase()

	/**
	 * A case that is not live answers null, and the clocks are not asked.
	 *
	 * @return void
	 */
	public function testACaseThatIsNotLiveAnswersNull(): void {
		$recycle = $this->createMock(originalClassName: CaseRecycleServiceInterface::class);
		$recycle->method('liveCase')->willReturn(null);
		$clocks = $this->createMock(originalClassName: RetentionClocksInterface::class);
		$clocks->expects($this->never())->method('clocksFor');

		self::assertNull(actual: (new LiveCaseRetentionClocks(recycle: $recycle, clocks: $clocks))->forCase(caseId: 'case-9'));
	}//end testACaseThatIsNotLiveAnswersNull()
}//end class
