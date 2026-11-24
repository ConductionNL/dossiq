<?php

/**
 * Unit tests for where an ending lands the case.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Lifecycle
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Lifecycle;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Lifecycle\EndingLanding;
use OCA\Dossiq\Service\Lifecycle\StatusRows;
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\StatusTypeLookup;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Lifecycle\EndingLanding
 * @uses   \OCA\Dossiq\Service\Lifecycle\StatusRows
 * @uses   \OCA\Dossiq\Exception\RefusedException
 * @uses   \OCA\Dossiq\Service\Support\LanguageMapText
 * @uses   \OCA\Dossiq\Service\Support\RowList
 */
class EndingLandingTest extends TestCase {

	/**
	 * The landing over a case type with the given status rows.
	 *
	 * @param array<int, array<string, mixed>> $rows The case type's status rows.
	 *
	 * @return EndingLanding The landing.
	 */
	private function landing(array $rows): EndingLanding {
		$results = $this->createMock(originalClassName: CaseResultWriter::class);
		$results->method('resolveClosingResult')->willReturn(value: 'result-1');
		$statuses = $this->createMock(originalClassName: StatusTypeLookup::class);
		$statuses->method('rowsOf')->willReturn(value: $rows);

		return new EndingLanding(results: $results, statuses: $statuses, rows: new StatusRows(text: new LanguageMapText()));
	}//end landing()

	/**
	 * An unnamed ending lands on the lowest-ordered terminal status and names the phases it skipped.
	 *
	 * @return void
	 */
	public function testAnEarlyCloseLandsOnTheFirstTerminalStatusAndNamesTheSkippedPhases(): void {
		$landing = $this->landing(
			rows: [
				['id' => 's-1', 'order' => 1, 'name' => 'Ontvangen'],
				['id' => 's-2', 'order' => 2, 'name' => 'Advies'],
				['id' => 's-3', 'order' => 3, 'name' => 'Besluit'],
				['id' => 's-end', 'order' => 9, 'isFinal' => true, 'name' => 'Afgehandeld'],
			]
		)->resolve(
			case: ['caseType' => 'ct-1', 'status' => 's-1'],
			request: ['caseId' => 'case-1', 'toStatus' => '', 'resultTypeId' => 'rt-1']
		);

		self::assertSame(expected: 's-1', actual: $landing['from']);
		self::assertSame(expected: 's-end', actual: $landing['status']);
		self::assertSame(expected: 'result-1', actual: $landing['result']);
		self::assertSame(expected: ['Advies', 'Besluit'], actual: $landing['skippedPhases']);
	}//end testAnEarlyCloseLandsOnTheFirstTerminalStatusAndNamesTheSkippedPhases()

	/**
	 * A case type without a terminal status refuses the ending by its rule.
	 *
	 * @return void
	 */
	public function testACaseTypeWithoutATerminalStatusRefuses(): void {
		$this->expectException(exception: RefusedException::class);

		$this->landing(rows: [['id' => 's-1', 'order' => 1]])->resolve(
			case: ['caseType' => 'ct-1', 'status' => 's-1'],
			request: ['caseId' => 'case-1', 'toStatus' => '', 'resultTypeId' => '']
		);
	}//end testACaseTypeWithoutATerminalStatusRefuses()
}//end class
