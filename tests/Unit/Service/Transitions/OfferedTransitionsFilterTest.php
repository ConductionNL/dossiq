<?php

/**
 * OfferedTransitions drops malformed and role-hidden transitions.
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

use OCA\Dossiq\Service\Status\StatusDeclarations;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\OfferedTransitions;
use OCA\Dossiq\Service\Transitions\TransitionDeclarations;
use OCA\Dossiq\Service\Transitions\TransitionSpecReader;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\OfferedTransitions
 */
class OfferedTransitionsFilterTest extends TestCase {

	/**
	 * A non-array transition is skipped, a role-hidden one is neither offered nor withheld.
	 *
	 * @return void
	 */
	public function testMalformedAndRoleHiddenTransitionsAreDropped(): void {
		$hiddenEval = [['type' => 'role', 'passed' => false, 'details' => ['silent' => true]]];
		$openEval = [['type' => 'role', 'passed' => true]];

		$guards = $this->createMock(GuardRegistry::class);
		$guards->expects($this->exactly(2))
			->method('evaluateAll')
			->willReturnCallback(
				static fn (array $guards): array => ($guards === [['type' => 'role', 'id' => 't-hidden']] ? $hiddenEval : $openEval)
			);
		$guards->method('knows')->willReturn(false);

		$spec = $this->createMock(TransitionSpecReader::class);
		$spec->method('guardsWithImplicit')->willReturnCallback(
			static fn (array $transition): array => [['type' => 'role', 'id' => (string)$transition['id']]]
		);
		$spec->method('isRoleHidden')->willReturnCallback(static fn (array $evalResults): bool => $evalResults === $hiddenEval);

		$statuses = $this->createMock(StatusDeclarations::class);
		$statuses->method('isDerivedStatus')->willReturn(false);
		$statuses->method('nameOf')->willReturn('Besluit');

		$moves = $this->createMock(TransitionDeclarations::class);
		$moves->expects($this->once())->method('withheldReasons')->willReturn([]);
		$moves->method('explanationOf')->willReturn('');
		$moves->method('closingChoice')->willReturn([]);

		$offer = new OfferedTransitions(guardRegistry: $guards, specReader: $spec, statuses: $statuses, moves: $moves);
		$result = $offer->from(
			transitions: [
				'not-a-transition',
				['id' => 't-hidden', 'label' => 'Verborgen', 'fromStatus' => 'st-2', 'toStatus' => 'st-3'],
				['id' => 't-open', 'label' => 'Besluit', 'fromStatus' => 'st-2', 'toStatus' => 'st-3'],
			],
			case: ['caseType' => 'ct-1'],
			currentId: 'st-2',
			userId: 'pieter',
		);

		$this->assertSame([], $result['withheld']);
		$this->assertCount(1, $result['transitions']);
		$this->assertSame('t-open', $result['transitions'][0]['id']);
		$this->assertTrue($result['transitions'][0]['guardsPassed']);
	}//end testMalformedAndRoleHiddenTransitionsAreDropped()
}//end class
