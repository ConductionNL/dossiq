<?php

/**
 * Unit tests for CaseDeclarations.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Status\StatusDeclarations;
use OCA\Dossiq\Service\Transitions\CaseDeclarations;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use OCA\Dossiq\Service\Transitions\OfferedTransitions;
use OCA\Dossiq\Service\Transitions\StatusChecklist;
use OCA\Dossiq\Service\Transitions\TransitionDeclarations;
use PHPUnit\Framework\TestCase;

/**
 * What a case declares about its status and its moves, read through one class.
 *
 * @covers \OCA\Dossiq\Service\Transitions\CaseDeclarations
 * @uses \OCA\Dossiq\Exception\RefusedException
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class CaseDeclarationsTest extends TestCase {
	private const CASE = ['id' => 'case-1', 'status' => 'st-open'];

	/**
	 * What the four readers were asked, in order.
	 *
	 * @var array<int, array>
	 */
	private array $asked = [];

	/**
	 * Build the reader over four doubles that record what they are asked.
	 *
	 * @param array<int, string> $withheld What the move waits on.
	 * @param array|null $fourEyes The four-eyes refusal, or null.
	 *
	 * @return CaseDeclarations
	 */
	private function declarations(array $withheld = [], ?array $fourEyes = null): CaseDeclarations {
		$record = function (string $name, mixed $answer): \Closure {
			return function (...$arguments) use ($name, $answer): mixed {
				$this->asked[] = [$name, $arguments];
				return $answer;
			};
		};

		$statuses = $this->createMock(StatusDeclarations::class);
		$statuses->method('panelFor')->willReturnCallback($record('panelFor', ['waitingOn' => 'us']));
		$statuses->method('applyStatusChange')->willReturnCallback($record('applyStatusChange', ['stamped' => true]));
		$statuses->method('retime')->willReturnCallback($record('retime', 'timer-1'));

		$moves = $this->createMock(TransitionDeclarations::class);
		$moves->method('withheldReasons')->willReturnCallback($record('withheldReasons', $withheld));
		$moves->method('fourEyesRefusal')->willReturnCallback($record('fourEyesRefusal', $fourEyes));
		$moves->method('refusalSentence')->willReturnCallback($record('refusalSentence', 'Somebody else has to take this move.'));

		$offered = $this->createMock(OfferedTransitions::class);
		$offered->method('from')->willReturnCallback($record('from', ['transitions' => [['id' => 't1']], 'withheld' => []]));

		$checklist = $this->createMock(StatusChecklist::class);
		$checklist->method('actionsFor')->willReturnCallback($record('actionsFor', [['type' => 'createTask']]));

		$store = $this->createMock(CaseStatusStore::class);
		$store->method('lookupStatusName')->willReturnCallback(static fn (string $statusTypeId): string => 'name of '.$statusTypeId);
		$store->method('lookupStatusColour')->willReturn('#123456');
		$store->method('lookupStatusDescription')->willReturn('What it means.');
		$store->expects($this->never())->method('saveCase');

		return new CaseDeclarations(statuses: $statuses, moves: $moves, offered: $offered, checklist: $checklist, store: $store);
	}//end declarations()

	/**
	 * Every question goes to the reader that owns it, with what it was given, and its answer comes back.
	 *
	 * @return void
	 */
	public function testEveryQuestionGoesToItsReader(): void {
		$declarations = $this->declarations();

		$this->assertSame(['waitingOn' => 'us'], $declarations->panel(case: self::CASE));
		$this->assertSame(
			['statusName' => 'name of st-open', 'statusColour' => '#123456', 'statusDescription' => 'What it means.'],
			$declarations->statusFacts(statusTypeId: 'st-open')
		);
		$this->assertSame(
			['transitions' => [['id' => 't1']], 'withheld' => []],
			$declarations->offered(transitions: [['id' => 't1']], case: self::CASE, currentId: 'st-open', userId: 'jan')
		);
		$this->assertSame(['stamped' => true], $declarations->stamp(case: self::CASE, toStatus: 'st-closed'));
		$declarations->retime(caseId: 'case-1', toStatus: 'st-closed');
		$this->assertSame([['type' => 'createTask']], $declarations->checklistActions(statusTypeId: 'st-closed', case: self::CASE, actor: 'jan'));

		$this->assertSame(
			[
				['panelFor', [self::CASE, null]],
				['from', [[['id' => 't1']], self::CASE, 'st-open', 'jan']],
				['applyStatusChange', [self::CASE, 'st-closed', null]],
				['retime', ['case-1', 'st-closed']],
				['actionsFor', ['st-closed', self::CASE, 'jan']],
			],
			$this->asked
		);
	}//end testEveryQuestionGoesToItsReader()

	/**
	 * A move nothing withholds is open: both declarations are asked, nothing is thrown.
	 *
	 * @return void
	 */
	public function testAnUnwithheldMoveIsOpen(): void {
		$this->declarations()->assertOpen(transition: ['id' => 't1'], case: self::CASE, caseId: 'case-1', userId: 'jan');

		$this->assertSame(['withheldReasons', 'fourEyesRefusal'], array_column($this->asked, 0));
	}//end testAnUnwithheldMoveIsOpen()

	/**
	 * An open dependency refuses first and names the first thing waited on; four eyes is not asked.
	 *
	 * @return void
	 */
	public function testAnOpenDependencyRefusesBeforeFourEyes(): void {
		try {
			$this->declarations(withheld: ['the advice', 'the fee'], fourEyes: ['reason' => 'same-person'])
				->assertOpen(transition: ['id' => 't1'], case: self::CASE, caseId: 'case-1', userId: 'jan');
			$this->fail('an open dependency must refuse');
		} catch (RefusedException $refused) {
			$this->assertSame('transition-dependency-open', $refused->getRule());
			$this->assertSame('This move is waiting on the advice.', $refused->getSentence());
			$this->assertSame(RefusedException::STATUS_REFUSED, $refused->getStatus());
		}

		$this->assertSame(['withheldReasons'], array_column($this->asked, 0));
	}//end testAnOpenDependencyRefusesBeforeFourEyes()

	/**
	 * Four eyes refuses with the sentence its declaration gives, as forbidden.
	 *
	 * @return void
	 */
	public function testFourEyesRefusesWithItsOwnSentence(): void {
		try {
			$this->declarations(fourEyes: ['reason' => 'same-person'])
				->assertOpen(transition: ['id' => 't1'], case: self::CASE, caseId: 'case-1', userId: 'jan');
			$this->fail('four eyes must refuse');
		} catch (RefusedException $refused) {
			$this->assertSame('transition-four-eyes', $refused->getRule());
			$this->assertSame('Somebody else has to take this move.', $refused->getSentence());
			$this->assertSame(RefusedException::STATUS_FORBIDDEN, $refused->getStatus());
		}

		$this->assertSame([['id' => 't1'], 'case-1', 'jan'], $this->asked[1][1]);
	}//end testFourEyesRefusesWithItsOwnSentence()
}//end class
