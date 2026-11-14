<?php

/**
 * FourEyesRule unit tests.
 *
 * The rule refuses a transition to whoever last performed a named earlier
 * act on the case. These cover the declaration, the performer lookup over
 * malformed and legacy records, and the refusal itself.
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

use OCA\Dossiq\Service\Transitions\FourEyesRule;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Transitions\FourEyesRule
 */
class FourEyesRuleTest extends TestCase {

	/**
	 * A declaration without an act, or not an object, declares no rule.
	 *
	 * @return void
	 */
	public function testADeclarationWithoutAnActDeclaresNoRule(): void {
		$rule = new FourEyesRule();

		$this->assertNull($rule->declaredFor(['notPerformedBy' => ['act' => '  ', 'askInstead' => 'Teamlead']]));
		$this->assertNull($rule->declaredFor(['notPerformedBy' => 'Voorbereiden']));
		$this->assertSame(
			['act' => 'Voorbereiden', 'askInstead' => 'Teamlead'],
			$rule->declaredFor(['notPerformedBy' => ['transitionLabel' => ' Voorbereiden ', 'askInstead' => ' Teamlead ']])
		);
	}//end testADeclarationWithoutAnActDeclaresNoRule()

	/**
	 * An empty act has no performer, whatever the records say.
	 *
	 * @return void
	 */
	public function testAnEmptyActHasNoPerformer(): void {
		$this->assertNull((new FourEyesRule())->performerOf([['transitionLabel' => '', 'actor' => 'jan']], '  '));
	}//end testAnEmptyActHasNoPerformer()

	/**
	 * Malformed and anonymous records are skipped; the last named performance wins, owner as fallback.
	 *
	 * @return void
	 */
	public function testTheLastNamedPerformanceWins(): void {
		$records = [
			'junk',
			['transitionLabel' => 'voorbereiden', 'actor' => 'jan', 'createdAt' => '2026-01-01'],
			['transitionLabel' => 'Andere stap', 'actor' => 'kees'],
			['transitionLabel' => 'Voorbereiden', '@self' => ['owner' => 'piet', 'created' => '2026-02-01']],
			['transitionLabel' => 'VOORBEREIDEN', 'actor' => '  '],
		];

		$this->assertSame(
			['actor' => 'piet', 'at' => '2026-02-01'],
			(new FourEyesRule())->performerOf($records, 'Voorbereiden')
		);
	}//end testTheLastNamedPerformanceWins()

	/**
	 * The performer is refused, someone else is not, and no user is never refused.
	 *
	 * @return void
	 */
	public function testThePerformerIsRefused(): void {
		$rule = new FourEyesRule();
		$transition = ['notPerformedBy' => ['act' => 'Voorbereiden', 'askInstead' => 'Teamlead']];
		$records = [['transitionLabel' => 'Voorbereiden', 'owner' => 'jan', 'createdAt' => '2026-03-01']];

		$this->assertSame(
			['act' => 'Voorbereiden', 'actor' => 'jan', 'at' => '2026-03-01', 'askInstead' => 'Teamlead'],
			$rule->refusalFor($transition, $records, 'jan')
		);
		$this->assertNull($rule->refusalFor($transition, $records, 'piet'));
		$this->assertNull($rule->refusalFor($transition, $records, ''));
		$this->assertNull($rule->refusalFor($transition, [], 'jan'));
		$this->assertNull($rule->refusalFor([], $records, 'jan'));
	}//end testThePerformerIsRefused()
}//end class
