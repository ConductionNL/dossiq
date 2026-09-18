<?php

/**
 * A split divides a case; it does not hand both halves the same file.
 *
 * 🔴 THE ASSERTION THAT MATTERS IS THAT THE ITEM LEAVES. A plan that copied
 * the chosen documents onto the new case would satisfy any test asking "does
 * the new case have them", and it would produce exactly the state this change
 * exists to end: two cases each claiming the same document, with a handler
 * cleaning up by hand or not at all. So the move is asserted as a REPOINT of
 * the item's own `case`, and the items not chosen are asserted to stay.
 *
 * 🔴 AND THAT IT LEAVES A TRACE. A move with no reference behind it is
 * indistinguishable from a deletion to anyone opening the original file a year
 * later.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Cases
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Cases;

use OCA\Dossiq\Service\Cases\CaseSplitPlan;
use OCA\Dossiq\Service\Cases\CaseSplitPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The plan a split performs.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class CaseSplitServiceTest extends TestCase {
	private CaseSplitPlan $plan;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->plan = new CaseSplitPlan();
	}//end setUp()

	/**
	 * One selection: two of four documents and one of two parties.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The selection.
	 */
	private function selection(): array {
		return [
			'documents' => [
				['id' => 'doc-1', 'title' => 'Aanvraagformulier'],
				['id' => 'doc-2', 'title' => 'Foto gevel'],
			],
			'parties' => [
				['id' => 'role-9', 'name' => 'Gemachtigde'],
			],
		];
	}//end selection()

	/**
	 * The chosen items are repointed at the new case, and nothing else is
	 * touched.
	 *
	 * @return void
	 */
	public function testTheChosenItemsMoveRatherThanBeingCopied(): void {
		$plan = $this->plan->forSelection(sourceId: 'case-1', newId: 'case-2', chosen: $this->selection());

		$this->assertCount(3, $plan['moves']);
		foreach ($plan['moves'] as $move) {
			// The item's OWN case reference changes. A plan that wrote the id
			// onto the new case instead would leave the original claiming it.
			$this->assertSame(['case' => 'case-2'], $move['changes']);
		}

		$this->assertSame(['doc-1', 'doc-2', 'role-9'], array_column($plan['moves'], 'id'));
	}//end testTheChosenItemsMoveRatherThanBeingCopied()

	/**
	 * What was not chosen is not in the plan at all.
	 *
	 * @return void
	 */
	public function testWhatWasNotChosenStaysWhereItIs(): void {
		$plan = $this->plan->forSelection(sourceId: 'case-1', newId: 'case-2', chosen: $this->selection());

		$this->assertNotContains('doc-3', array_column($plan['moves'], 'id'));
		$this->assertNotContains('role-4', array_column($plan['moves'], 'id'));
	}//end testWhatWasNotChosenStaysWhereItIs()

	/**
	 * Every move leaves a reference naming where the item went.
	 *
	 * @return void
	 */
	public function testEveryMoveLeavesAReferenceBehind(): void {
		$plan = $this->plan->forSelection(sourceId: 'case-1', newId: 'case-2', chosen: $this->selection());

		$this->assertCount(count($plan['moves']), $plan['references']);
		$this->assertSame('Aanvraagformulier', $plan['references'][0]['label']);
		$this->assertSame('case-2', $plan['references'][0]['movedTo']);
		// An item with no title is still traceable by its id rather than by an
		// empty line in the file.
		$unnamed = $this->plan->forSelection('case-1', 'case-2', ['tasks' => [['id' => 'task-7']]]);
		$this->assertSame('task-7', $unnamed['references'][0]['label']);
	}//end testEveryMoveLeavesAReferenceBehind()

	/**
	 * The relation is the one the register already speaks.
	 *
	 * @return void
	 */
	public function testTheRelationIsTheOneTheRegisterAlreadySpeaks(): void {
		$plan = $this->plan->forSelection(sourceId: 'case-1', newId: 'case-2', chosen: $this->selection());

		$this->assertSame(['from' => 'case-1', 'to' => 'case-2', 'nature' => 'vervolg'], $plan['relation']);
		// And it is a nature CaseRelationService accepts, not a new word only
		// the split understands.
		$this->assertContains(CaseSplitPlan::RELATION, ['vervolg', 'subject', 'bijdrage', 'samenhang']);
	}//end testTheRelationIsTheOneTheRegisterAlreadySpeaks()

	/**
	 * An item with no id is skipped rather than repointing something unnamed.
	 *
	 * @return void
	 */
	public function testAnItemWithNoIdIsSkipped(): void {
		$plan = $this->plan->forSelection('case-1', 'case-2', ['documents' => [['title' => 'Naamloos'], ['id' => '']]]);

		$this->assertSame([], $plan['moves']);
	}//end testAnItemWithNoIdIsSkipped()

	/**
	 * A row that belongs to another case is refused, not moved.
	 *
	 * The selection arrives from a client. A plan that repointed any id handed
	 * to it would let a handler move a document off somebody else's case by
	 * editing one field in the request, which is a write to a record they may
	 * never have been allowed to read.
	 *
	 * @return void
	 */
	public function testARowBelongingToAnotherCaseIsRefusedByName(): void {
		$plan = $this->plan->forSelection('case-1', 'case-2', [
			'documents' => [
				['id' => 'doc-1', 'case' => 'case-1'],
				['id' => 'doc-stolen', 'case' => 'case-77'],
			],
		]);

		$this->assertSame(['doc-1'], array_column($plan['moves'], 'id'));
		// Named rather than skipped in silence: a selection that half happened
		// with no word about the rest is the state nobody can reconstruct.
		$this->assertSame(['doc-stolen'], array_column($plan['refused'], 'id'));
		$this->assertSame('case-77', $plan['refused'][0]['case']);
	}//end testARowBelongingToAnotherCaseIsRefusedByName()

	/**
	 * A row that names no case at all is still moved: the caller read it off
	 * this case's own collection, and refusing it would make the ordinary
	 * split impossible for every payload that does not echo the parent.
	 *
	 * @return void
	 */
	public function testARowThatNamesNoCaseIsStillMoved(): void {
		$plan = $this->plan->forSelection('case-1', 'case-2', ['tasks' => [['id' => 'task-7']]]);

		$this->assertSame(['task-7'], array_column($plan['moves'], 'id'));
		$this->assertSame([], $plan['refused']);
	}//end testARowThatNamesNoCaseIsStillMoved()

	/**
	 * The original's history gets one line, not twenty.
	 *
	 * @return void
	 */
	public function testTheOriginalGetsOneLineSayingWhatLeft(): void {
		$plan = $this->plan->forSelection('case-1', 'case-2', $this->selection());

		$note = $this->plan->noteFor(references: $plan['references'], newNumber: 'ZAAK-99');

		$this->assertStringContainsString('ZAAK-99', $note);
		$this->assertStringContainsString('2 documents', $note);
		$this->assertStringContainsString('1 parties', $note);
	}//end testTheOriginalGetsOneLineSayingWhatLeft()

	/**
	 * A split that moved nothing writes no note about nothing.
	 *
	 * @return void
	 */
	public function testASplitThatMovedNothingSaysNothing(): void {
		$this->assertSame('', $this->plan->noteFor(references: [], newNumber: 'ZAAK-99'));
	}//end testASplitThatMovedNothingSaysNothing()

	/**
	 * Only the three declared parts are ever planned.
	 *
	 * @return void
	 */
	public function testAPartNobodyDeclaredIsNotPlanned(): void {
		$plan = $this->plan->forSelection('case-1', 'case-2', ['decisions' => [['id' => 'besluit-1']]]);

		$this->assertSame([], $plan['moves']);
		$this->assertSame(['documents', 'parties', 'tasks'], CaseSplitPolicy::PARTS);
	}//end testAPartNobodyDeclaredIsNotPlanned()
}//end class
