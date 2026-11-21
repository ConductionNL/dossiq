<?php

/**
 * Unit tests for PartlyPublicRows.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Assessment
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Assessment;

use OCA\Dossiq\Service\Assessment\PartlyPublicRows;
use PHPUnit\Framework\TestCase;

/**
 * Only the case's own partly public rows count; those without a redacted copy are named.
 *
 * @covers \OCA\Dossiq\Service\Assessment\PartlyPublicRows
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class PartlyPublicRowsTest extends TestCase {

	/**
	 * Rows of every kind the search may answer.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows(): array {
		return [
			5 => ['caseRef' => 'case-1', 'classification' => 'deels_openbaar', 'documentRef' => 'doc-1'],
			6 => ['caseRef' => 'case-1', 'classification' => 'deels_openbaar', 'documentRef' => 'doc-2', 'redactedDocumentRef' => 'red-2'],
			7 => ['caseRef' => 'case-1', 'classification' => 'openbaar', 'documentRef' => 'doc-3'],
			8 => ['caseRef' => 'case-2', 'classification' => 'deels_openbaar', 'documentRef' => 'doc-4'],
			9 => ['caseRef' => 'case-1', 'classification' => 'deels_openbaar'],
			10 => [],
		];
	}//end rows()

	/**
	 * The case's partly public rows come back as a list, in the order read.
	 *
	 * @return void
	 */
	public function testTheRowsAreTheCasesPartlyPublicOnes(): void {
		$rows = (new PartlyPublicRows())->rowsOf(rows: $this->rows(), caseId: 'case-1');

		self::assertSame(expected: [0, 1, 2], actual: array_keys($rows));
		self::assertSame(expected: ['doc-1', 'doc-2'], actual: array_column($rows, 'documentRef'));
		self::assertSame(expected: [], actual: (new PartlyPublicRows())->rowsOf(rows: $this->rows(), caseId: 'case-3'));
	}//end testTheRowsAreTheCasesPartlyPublicOnes()

	/**
	 * The tally counts them and names each one with no redacted copy, a missing reference as ''.
	 *
	 * @return void
	 */
	public function testTheTallyNamesTheOnesWithoutACopy(): void {
		self::assertSame(
			expected: ['partlyPublic' => 3, 'waiting' => ['doc-1', '']],
			actual: (new PartlyPublicRows())->tally(rows: $this->rows(), caseId: 'case-1')
		);
		self::assertSame(expected: ['partlyPublic' => 0, 'waiting' => []], actual: (new PartlyPublicRows())->tally(rows: [], caseId: 'case-1'));
	}//end testTheTallyNamesTheOnesWithoutACopy()
}//end class
