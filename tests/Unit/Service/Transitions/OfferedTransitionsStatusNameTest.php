<?php

/**
 * An offered move names the status it leads to.
 *
 * The lifecycle menu labels a move by where it goes ("Naar Besluit", board
 * DqVolgendeStap). The move's own label is what an administrator wrote, and on
 * every shipped template it IS the status name, so the menu cannot tell the
 * two apart without the name beside it.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Transitions
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/what-a-transition-declares/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\Status\StatusDeclarations;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\OfferedTransitions;
use OCA\Dossiq\Service\Transitions\TransitionDeclarations;
use OCA\Dossiq\Service\Transitions\TransitionSpecReader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\OfferedTransitions
 */
class OfferedTransitionsStatusNameTest extends TestCase {

	/**
	 * The offered move carries the destination status's name.
	 *
	 * @return void
	 */
	public function testAnOfferedMoveNamesTheStatusItLeadsTo(): void {
		$guards = $this->createMock(originalClassName: GuardRegistry::class);
		$guards->method('evaluateAll')->willReturn([]);
		$spec = $this->createMock(originalClassName: TransitionSpecReader::class);
		$spec->method('guardsWithImplicit')->willReturn([]);
		$spec->method('isRoleHidden')->willReturn(false);
		$statuses = $this->createMock(originalClassName: StatusDeclarations::class);
		$statuses->method('isDerivedStatus')->willReturn(false);
		$statuses->method('nameOf')->willReturnMap([['st-3', 'Besluit']]);
		$moves = $this->createMock(originalClassName: TransitionDeclarations::class);
		$moves->method('withheldReasons')->willReturn([]);
		$moves->method('explanationOf')->willReturn('');
		$moves->method('closingChoice')->willReturn([]);

		$offer = new OfferedTransitions(guardRegistry: $guards, specReader: $spec, statuses: $statuses, moves: $moves);
		$result = $offer->from(
			transitions: [['id' => 't-1', 'label' => 'Besluit', 'fromStatus' => 'st-2', 'toStatus' => 'st-3']],
			case: ['caseType' => 'ct-1'],
			currentId: 'st-2',
			userId: 'pieter',
		);

		$this->assertSame(expected: 'Besluit', actual: $result['transitions'][0]['toStatusName']);
	}//end testAnOfferedMoveNamesTheStatusItLeadsTo()
}//end class
