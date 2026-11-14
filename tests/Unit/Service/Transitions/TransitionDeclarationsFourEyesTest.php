<?php

/**
 * TransitionDeclarations four-eyes tests
 *
 * Verifies that the four-eyes refusal is only computed for a transition that
 * declares the rule, that the case's status records are read only then, and
 * that the rule's verdict over those records is passed through unchanged.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
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
 * @spec openspec/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Transitions\CaseResultWriter;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\FourEyesRule;
use OCA\Dossiq\Service\Transitions\TransitionDeclarations;
use OCA\Dossiq\Service\Transitions\TransitionPreconditions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\TransitionDeclarations
 */
class TransitionDeclarationsFourEyesTest extends TestCase {

	/**
	 * A transition that declares no four-eyes rule is open, and no records are read.
	 *
	 * @return void
	 */
	public function testAnUndeclaredRuleIsOpenWithoutReadingRecords(): void {
		$transition = ['id' => 't-1', 'toStatus' => 's-2'];

		$fourEyes = $this->createMock(FourEyesRule::class);
		$fourEyes->expects($this->once())->method('declaredFor')->with($transition)->willReturn(null);
		$fourEyes->expects($this->never())->method('refusalFor');

		$store = $this->createMock(CaseStatusStore::class);
		$store->expects($this->never())->method('findStatusRecords');

		$declarations = new TransitionDeclarations(
			$this->createMock(TransitionPreconditions::class),
			$fourEyes,
			$this->createMock(CaseResultWriter::class),
			$store,
		);

		self::assertNull($declarations->fourEyesRefusal(transition: $transition, caseId: 'case-1', userId: 'jan'));
	}//end testAnUndeclaredRuleIsOpenWithoutReadingRecords()

	/**
	 * A declared rule is judged over the case's own status records.
	 *
	 * @return void
	 */
	public function testADeclaredRuleIsJudgedOverTheCasesRecords(): void {
		$transition = ['id' => 't-2', 'notPerformedBy' => ['act' => 'proposal']];
		$records = [['statusType' => 's-1', 'performedBy' => 'jan']];
		$refusal = ['act' => 'proposal', 'actor' => 'jan', 'at' => '2026-03-04', 'askInstead' => ''];

		$fourEyes = $this->createMock(FourEyesRule::class);
		$fourEyes->method('declaredFor')->willReturn(['act' => 'proposal']);
		$fourEyes->expects($this->once())
			->method('refusalFor')
			->with($transition, $records, 'jan')
			->willReturn($refusal);

		$store = $this->createMock(CaseStatusStore::class);
		$store->expects($this->once())->method('findStatusRecords')->with('case-1')->willReturn($records);

		$declarations = new TransitionDeclarations(
			$this->createMock(TransitionPreconditions::class),
			$fourEyes,
			$this->createMock(CaseResultWriter::class),
			$store,
		);

		self::assertSame($refusal, $declarations->fourEyesRefusal(transition: $transition, caseId: 'case-1', userId: 'jan'));
	}//end testADeclaredRuleIsJudgedOverTheCasesRecords()

	/**
	 * Unreadable records are judged as no records, not skipped.
	 *
	 * @return void
	 */
	public function testUnreadableRecordsAreJudgedAsNone(): void {
		$transition = ['id' => 't-3', 'notPerformedBy' => ['act' => 'proposal']];

		$fourEyes = $this->createMock(FourEyesRule::class);
		$fourEyes->method('declaredFor')->willReturn(['act' => 'proposal']);
		$fourEyes->expects($this->once())
			->method('refusalFor')
			->with($transition, [], 'piet')
			->willReturn(null);

		$store = $this->createMock(CaseStatusStore::class);
		$store->method('findStatusRecords')->willReturn(null);

		$declarations = new TransitionDeclarations(
			$this->createMock(TransitionPreconditions::class),
			$fourEyes,
			$this->createMock(CaseResultWriter::class),
			$store,
		);

		self::assertNull($declarations->fourEyesRefusal(transition: $transition, caseId: 'case-2', userId: 'piet'));
	}//end testUnreadableRecordsAreJudgedAsNone()
}//end class
