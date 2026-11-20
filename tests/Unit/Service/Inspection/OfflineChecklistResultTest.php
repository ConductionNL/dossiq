<?php

/**
 * Offline checklist result tests: the leaf's keys moved onto the shared ones.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Inspection
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
 * @spec openspec/changes/inspection-checklists-onto-task/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Inspection;

use OCA\Dossiq\Service\Inspection\OfflineChecklistResult;
use PHPUnit\Framework\TestCase;

/**
 * Tests for {@see OfflineChecklistResult}.
 *
 * @covers \OCA\Dossiq\Service\Inspection\OfflineChecklistResult
 */
final class OfflineChecklistResultTest extends TestCase {

	/**
	 * Each answer moves onto itemId, value, photos and comment; the latest answer dates the run.
	 *
	 * @return void
	 */
	public function testTheLeafAnswersMoveOntoTheSharedKeys(): void {
		$result = (new OfflineChecklistResult())->normalised(
			object: [
				'inspectionRef' => 'fi-1',
				'items' => [
					[
						'questionId' => 'q-1',
						'answer' => 'yes',
						'evidenceRefs' => ['41'],
						'notes' => 'scheur',
						'answeredAt' => '2026-11-01T10:00:00Z',
						'gpsAtAnswer' => ['lat' => 52.1, 'lon' => 5.1],
					],
					['questionId' => 'q-2', 'answer' => 'no', 'notes' => '  ', 'answeredAt' => '2026-11-01T11:00:00Z'],
					'not an item',
				],
			]
		);

		$this->assertSame(
			expected: [
				['itemId' => 'q-1', 'value' => 'yes', 'photos' => ['41'], 'comment' => 'scheur', 'gpsAtAnswer' => ['lat' => 52.1, 'lon' => 5.1]],
				['itemId' => 'q-2', 'value' => 'no', 'photos' => []],
			],
			actual: $result['items']
		);
		$this->assertSame(expected: 'fi-1', actual: $result['inspection']);
		$this->assertSame(expected: '2026-11-01T11:00:00Z', actual: $result['completedAt']);
	}//end testTheLeafAnswersMoveOntoTheSharedKeys()

	/**
	 * A row that already names its inspection and completion keeps them; no items stay none.
	 *
	 * @return void
	 */
	public function testAnInspectionAndCompletionAlreadyGivenAreKept(): void {
		$result = (new OfflineChecklistResult())->normalised(
			object: [
				'inspectionRef' => 'fi-1',
				'inspection' => 'fi-2',
				'completedAt' => '2026-10-01T09:00:00Z',
			]
		);

		$this->assertSame(expected: [], actual: $result['items']);
		$this->assertSame(expected: 'fi-2', actual: $result['inspection']);
		$this->assertSame(expected: '2026-10-01T09:00:00Z', actual: $result['completedAt']);
	}//end testAnInspectionAndCompletionAlreadyGivenAreKept()
}//end class
