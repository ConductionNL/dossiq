<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Pause
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Pause;

use OCA\Dossiq\Service\DeadlinePauseService;
use OCA\Dossiq\Service\Pause\PauseReason;
use OCA\Dossiq\Service\Pause\PauseReasonReader;
use OCA\Dossiq\Service\TermijnService;
use PHPUnit\Framework\TestCase;

/**
 * suspend-picks-a-pause-reason 4.1: a pause reason can carry the number of
 * days the applicant is usually given, which the Opschorten form fills in.
 *
 * @covers \OCA\Dossiq\Service\Pause\PauseReason
 * @covers \OCA\Dossiq\Service\DeadlinePauseService
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */
class PauseReasonDefaultDaysTest extends TestCase {

	/**
	 * @return void
	 */
	public function testAReasonKeepsAPositiveWholeNumberOfDefaultDays(): void {
		$this->assertSame(expected: 28, actual: PauseReason::normalise(row: ['key' => 'k', 'name' => 'N', 'defaultDays' => 28])['defaultDays']);
		$this->assertSame(expected: 14, actual: PauseReason::normalise(row: ['key' => 'k', 'name' => 'N', 'defaultDays' => '14'])['defaultDays']);
	}

	/**
	 * Nothing, zero or nonsense reads as no default, so the form keeps what was typed.
	 *
	 * @return void
	 */
	public function testNoDefaultReadsAsNull(): void {
		foreach ([[], ['defaultDays' => 0], ['defaultDays' => -3], ['defaultDays' => 'soon']] as $extra) {
			$this->assertNull(actual: PauseReason::normalise(row: ['key' => 'k', 'name' => 'N'] + $extra)['defaultDays']);
		}
	}

	/**
	 * The picker is offered the default with the reason, and only when there is one.
	 *
	 * @return void
	 */
	public function testThePickerIsOfferedTheDefault(): void {
		$reader = $this->createMock(originalClassName: PauseReasonReader::class);
		$reader->method('forCase')->willReturn([
			PauseReason::normalise(row: ['key' => 'machtiging', 'name' => 'Machtiging', 'legalBasis' => 'Awb 4:5', 'defaultDays' => 28]),
			PauseReason::normalise(row: ['key' => 'advies', 'name' => 'Advies', 'legalBasis' => 'Awb 4:15']),
		]);
		$service = new DeadlinePauseService(termService: $this->createMock(originalClassName: TermijnService::class), reasons: $reader);

		$this->assertSame(
			expected: [
				['key' => 'machtiging', 'name' => 'Machtiging', 'legalBasis' => 'Awb 4:5', 'defaultDays' => 28],
				['key' => 'advies', 'name' => 'Advies', 'legalBasis' => 'Awb 4:15'],
			],
			actual: $service->offeredReasons(caseId: 'case-1')
		);
	}
}//end class
