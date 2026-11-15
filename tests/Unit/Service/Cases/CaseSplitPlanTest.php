<?php

/**
 * The plan a case split repoints, records and refuses.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
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

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\CaseSplitPlan;
use PHPUnit\Framework\TestCase;

/**
 * Which picked items move, which are refused, and what the original keeps.
 *
 * @covers \OCA\Dossiq\Service\Cases\CaseSplitPlan
 * @uses   \OCA\Dossiq\Service\Cases\CaseSplitPolicy
 *
 * @spec openspec/specs/case-management/spec.md
 */
class CaseSplitPlanTest extends TestCase {

	/**
	 * Items on this case move to the new case and leave a labelled reference behind.
	 *
	 * @return void
	 */
	public function testItemsOnThisCaseMoveAndLeaveAReference(): void {
		$plan = (new CaseSplitPlan())->forSelection('src', 'new', [
			'documents' => [['id' => ' doc-1 ', 'case' => 'src', 'title' => ' Aanvraag.pdf ']],
			'parties' => [['id' => 'role-1', 'name' => 'Aanvrager']],
			'tasks' => [['id' => 'task-1']],
		]);

		self::assertSame(
			[
				['part' => 'documents', 'id' => 'doc-1', 'changes' => ['case' => 'new']],
				['part' => 'parties', 'id' => 'role-1', 'changes' => ['case' => 'new']],
				['part' => 'tasks', 'id' => 'task-1', 'changes' => ['case' => 'new']],
			],
			$plan['moves']
		);
		self::assertSame(
			[
				['part' => 'documents', 'id' => 'doc-1', 'label' => 'Aanvraag.pdf', 'movedTo' => 'new'],
				['part' => 'parties', 'id' => 'role-1', 'label' => 'Aanvrager', 'movedTo' => 'new'],
				['part' => 'tasks', 'id' => 'task-1', 'label' => 'task-1', 'movedTo' => 'new'],
			],
			$plan['references']
		);
		self::assertSame([], $plan['refused']);
		self::assertSame(['from' => 'src', 'to' => 'new', 'nature' => CaseSplitPlan::RELATION], $plan['relation']);
		self::assertSame('vervolg', CaseSplitPlan::RELATION);
	}//end testItemsOnThisCaseMoveAndLeaveAReference()

	/**
	 * An item whose own case names another case is refused by name, not moved.
	 *
	 * @return void
	 */
	public function testAnItemOfAnotherCaseIsRefused(): void {
		$plan = (new CaseSplitPlan())->forSelection('src', 'new', [
			'documents' => [
				['id' => 'doc-mine', 'case' => 'src'],
				['id' => 'doc-theirs', 'case' => ' other-case '],
			],
		]);

		self::assertSame(['doc-mine'], array_column($plan['moves'], 'id'));
		self::assertSame(['doc-mine'], array_column($plan['references'], 'id'));
		self::assertSame([['part' => 'documents', 'id' => 'doc-theirs', 'case' => 'other-case']], $plan['refused']);
	}//end testAnItemOfAnotherCaseIsRefused()

	/**
	 * Non-array items, items without an id and parts the policy does not know are skipped silently.
	 *
	 * @return void
	 */
	public function testMalformedItemsAndUnknownPartsAreSkipped(): void {
		$plan = (new CaseSplitPlan())->forSelection('src', 'new', [
			'documents' => ['doc-as-string', 7, ['id' => '   '], ['title' => 'no id'], ['id' => 'doc-ok']],
			'notes' => [['id' => 'note-1']],
		]);

		self::assertSame([['part' => 'documents', 'id' => 'doc-ok', 'changes' => ['case' => 'new']]], $plan['moves']);
		self::assertCount(1, $plan['references']);
		self::assertSame([], $plan['refused']);
	}//end testMalformedItemsAndUnknownPartsAreSkipped()

	/**
	 * An empty selection plans nothing but still names the relation.
	 *
	 * @return void
	 */
	public function testAnEmptySelectionPlansNothing(): void {
		$plan = (new CaseSplitPlan())->forSelection('src', 'new', []);

		self::assertSame([], $plan['moves']);
		self::assertSame([], $plan['references']);
		self::assertSame([], $plan['refused']);
		self::assertSame('src', $plan['relation']['from']);
	}//end testAnEmptySelectionPlansNothing()

	/**
	 * The note counts what left the case per part and names the case it went to.
	 *
	 * @return void
	 */
	public function testTheNoteCountsWhatLeftPerPart(): void {
		$references = [
			['part' => 'documents', 'id' => 'd1'],
			['part' => 'parties', 'id' => 'p1'],
			['part' => 'documents', 'id' => 'd2'],
		];

		self::assertSame('Split off to Z-2026-0042: 2 documents, 1 parties.', (new CaseSplitPlan())->noteFor($references, 'Z-2026-0042'));
	}//end testTheNoteCountsWhatLeftPerPart()

	/**
	 * Without a number the note says "the new case"; with nothing moved there is no note.
	 *
	 * @return void
	 */
	public function testTheNoteWithoutANumberAndWithNothingMoved(): void {
		$plan = new CaseSplitPlan();

		self::assertSame('Split off to the new case: 1 tasks.', $plan->noteFor([['part' => 'tasks']], ''));
		self::assertSame('', $plan->noteFor([], 'Z-1'));
	}//end testTheNoteWithoutANumberAndWithNothingMoved()
}//end class
