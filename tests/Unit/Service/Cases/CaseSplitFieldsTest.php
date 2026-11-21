<?php

/**
 * Unit tests for CaseSplitFields.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
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

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\CaseSplitFields;
use OCA\Dossiq\Service\Cases\CaseSplitPlan;
use PHPUnit\Framework\TestCase;

/**
 * The second case's fields, its relation back, and the moved items the original records.
 *
 * @covers \OCA\Dossiq\Service\Cases\CaseSplitFields
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class CaseSplitFieldsTest extends TestCase {

	/**
	 * A given title is trimmed and kept; the handling fields are copied, impact and urgency default to medium.
	 *
	 * @return void
	 */
	public function testTheSecondCaseFields(): void {
		$fields = new CaseSplitFields();

		$this->assertSame(
			expected: [
				'title' => 'De tweede klacht',
				'caseType' => 'ct-1',
				'assignedGroup' => '',
				'assignee' => 'ann',
				'impact' => 'medium',
				'urgency' => 'low',
				'splitFrom' => 'case-1',
			],
			actual: $fields->secondCase(
				source: ['title' => 'Overlast', 'caseType' => 'ct-1', 'assignee' => 'ann', 'urgency' => 'low'],
				title: ' De tweede klacht ',
				sourceId: 'case-1',
			)
		);
		$this->assertSame(
			expected: 'Split from ',
			actual: $fields->secondCase(source: [], title: '', sourceId: 'case-1')['title']
		);
	}//end testTheSecondCaseFields()

	/**
	 * The relation back is one typed relation, JSON-encoded.
	 *
	 * @return void
	 */
	public function testTheRelationBackIsEncoded(): void {
		$this->assertSame(
			expected: [['caseId' => 'case-1', 'aardRelatie' => CaseSplitPlan::RELATION, 'toelichting' => 'Split from this case by jan']],
			actual: json_decode((new CaseSplitFields())->relationBack(sourceId: 'case-1', actor: 'jan'), true)
		);
	}//end testTheRelationBackIsEncoded()

	/**
	 * Earlier moved items come first and keep their stamp; junk is dropped; each new reference is stamped now.
	 *
	 * @return void
	 */
	public function testTheMovedItemsAppendStampedReferences(): void {
		$moved = (new CaseSplitFields())->movedItems(
			case: ['splitMovedItems' => ['x' => ['id' => 'old-1', 'movedAt' => '2026-01-01T00:00:00+00:00'], 'junk']],
			references: [['id' => 'doc-1', 'movedTo' => 'case-2']],
		);

		$this->assertCount(expectedCount: 2, haystack: $moved);
		$this->assertSame(expected: ['id' => 'old-1', 'movedAt' => '2026-01-01T00:00:00+00:00'], actual: $moved[0]);
		$this->assertSame(expected: 'doc-1', actual: $moved[1]['id']);
		$this->assertSame(expected: 'case-2', actual: $moved[1]['movedTo']);
		$this->assertMatchesRegularExpression(pattern: '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$/', string: $moved[1]['movedAt']);

		$this->assertSame(expected: [], actual: (new CaseSplitFields())->movedItems(case: ['splitMovedItems' => 'text'], references: []));
	}//end testTheMovedItemsAppendStampedReferences()

}//end class
