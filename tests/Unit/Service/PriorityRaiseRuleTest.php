<?php

/**
 * Unit tests for the declared term rule — raise only, and never an engine.
 *
 * 🔴 THE FAILURE THIS GUARDS IS A RULE THAT LOWERS. A case somebody
 * deliberately escalated, dropped back down the queue because its term was
 * extended, is work that has been hidden rather than deprioritised, and nothing
 * says so. So the rule writes a FLOOR rather than a priority, and the tests
 * below drive a case through a raise and then through the events that would
 * plausibly undo one: an extension, a re-derivation, a lower threshold firing
 * afterwards.
 *
 * THE CLOCK IS FIXED, AND IS NOT dossiq's. dossiq owns no clock behind this
 * rule: OpenRegister's flow timers decide when a rung fires and hand the
 * threshold over. These tests therefore drive the thresholds directly — 14, 7,
 * 2, 0 — which is exactly the input the engine supplies, rather than moving a
 * date and hoping the right bucket comes out.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\CasePriorityService;
use OCA\Dossiq\Service\CaseTypeResolver;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class PriorityRaiseRuleTest extends TestCase {

	/**
	 * A service over no case types, so the instance default matrix answers.
	 *
	 * @return CasePriorityService The service.
	 */
	private function service(): CasePriorityService {
		$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);
		$resolver->method('effectiveCaseType')->willReturn([]);

		return new CasePriorityService(resolver: $resolver, logger: new NullLogger());
	}//end service()

	/**
	 * The declaration is readable, and names OpenRegister as the engine
	 * (REQ-PRI-04). A declaration that named no engine would be a rule dossiq
	 * had quietly implemented itself.
	 *
	 * @return void
	 */
	public function testTheDeclarationNamesOpenRegisterAsTheEngine(): void {
		$rule = $this->service()->termRaiseRule();

		self::assertNotSame(expected: [], actual: $rule, message: 'the declaration must be readable');
		self::assertSame(expected: 'openregister', actual: $rule['engine']);
		self::assertSame(expected: 'raise-only', actual: $rule['direction']);
		self::assertStringContainsString(needle: 'openregister', haystack: strtolower((string)$rule['engineNote']));
	}//end testTheDeclarationNamesOpenRegisterAsTheEngine()

	/**
	 * dossiq ships no rule evaluator behind this rule (REQ-PRI-04).
	 *
	 * The whole of dossiq's part is a lookup in the declared table, so the
	 * floor for a threshold has to be a value the FILE carries, not one the
	 * code decides. Read the declaration, change nothing in PHP, and the
	 * answer changes: that is what it means to have declared rather than coded
	 * the rule.
	 *
	 * @return void
	 */
	public function testTheFloorComesFromTheFileRatherThanFromTheCode(): void {
		$service = $this->service();
		$declared = [];
		foreach ((array)$service->termRaiseRule()['thresholds'] as $threshold) {
			$declared[(int)$threshold['daysToTerm']] = (string)$threshold['minimumPriority'];
		}

		self::assertNotSame(expected: [], actual: $declared);
		foreach ($declared as $days => $floor) {
			self::assertSame(expected: $floor, actual: $service->termRaiseFloor(daysToTerm: $days));
		}
	}//end testTheFloorComesFromTheFileRatherThanFromTheCode()

	/**
	 * A case inside two days of its term rises (REQ-PRI-04).
	 *
	 * @return void
	 */
	public function testACaseTwoDaysFromItsTermRises(): void {
		self::assertSame(expected: 'high', actual: $this->service()->termRaiseFloor(daysToTerm: 2));
	}//end testACaseTwoDaysFromItsTermRises()

	/**
	 * A case whose term has run out rises further.
	 *
	 * @return void
	 */
	public function testACaseWhoseTermHasRunOutRisesToUrgent(): void {
		self::assertSame(expected: 'urgent', actual: $this->service()->termRaiseFloor(daysToTerm: 0));
	}//end testACaseWhoseTermHasRunOutRisesToUrgent()

	/**
	 * A threshold the declaration does not name raises nothing.
	 *
	 * The engine fires four rungs. The declaration names two. The other two
	 * must leave the case exactly where it is rather than falling through to
	 * some default, which would make every case in the instance `high` a
	 * fortnight before its term.
	 *
	 * @return void
	 */
	public function testAThresholdTheDeclarationDoesNotNameRaisesNothing(): void {
		$service = $this->service();

		self::assertSame(expected: '', actual: $service->termRaiseFloor(daysToTerm: 14));
		self::assertSame(expected: '', actual: $service->termRaiseFloor(daysToTerm: 7));
		self::assertSame(expected: '', actual: $service->termRaiseFloor(daysToTerm: 99));
	}//end testAThresholdTheDeclarationDoesNotNameRaisesNothing()

	/**
	 * The rule raises and never lowers (REQ-PRI-04, D-5).
	 *
	 * @return void
	 */
	public function testTheRuleRaisesAndNeverLowers(): void {
		$service = $this->service();

		self::assertSame(expected: 'urgent', actual: $service->raise(current: 'normal', floor: 'urgent'));
		self::assertSame(expected: 'urgent', actual: $service->raise(current: 'urgent', floor: 'high'));
		self::assertSame(expected: 'urgent', actual: $service->raise(current: 'urgent', floor: 'low'));
		self::assertSame(expected: 'high', actual: $service->raise(current: 'high', floor: 'high'));
	}//end testTheRuleRaisesAndNeverLowers()

	/**
	 * A floor outside the vocabulary moves nothing.
	 *
	 * @return void
	 */
	public function testAFloorOutsideTheVocabularyMovesNothing(): void {
		self::assertSame(expected: 'normal', actual: $this->service()->raise(current: 'normal', floor: 'blocker'));
	}//end testAFloorOutsideTheVocabularyMovesNothing()

	/**
	 * A raised case reads its floor even though the matrix says lower
	 * (REQ-PRI-04), which is what makes the raise stick past the next save.
	 *
	 * @return void
	 */
	public function testARaisedCaseKeepsItsFloorThroughTheNextDerivation(): void {
		$resolved = $this->service()->resolve(
			case: ['impact' => 'medium', 'urgency' => 'medium', 'priorityFloor' => 'urgent']
		);

		self::assertSame(expected: 'urgent', actual: $resolved['priority']);
		self::assertSame(expected: 'normal', actual: $resolved['priorityDerived'], message: 'the matrix keeps answering underneath');
		self::assertSame(expected: 4, actual: $resolved['priorityOrder']);
	}//end testARaisedCaseKeepsItsFloorThroughTheNextDerivation()

	/**
	 * An extended term does not lower a raised priority (REQ-PRI-04).
	 *
	 * Extending the term is a save on the case with a new deadline. It does not
	 * touch the floor, so the derivation still lifts to it. Modelled here as
	 * the derivation running again over a case whose impact and urgency were
	 * ALSO relaxed, which is the strongest form of the claim: even a case that
	 * now derives `low` stays where the rule put it.
	 *
	 * @return void
	 */
	public function testAnExtendedTermDoesNotLowerARaisedPriority(): void {
		$resolved = $this->service()->resolve(
			case: [
				'impact' => 'low',
				'urgency' => 'low',
				'priorityFloor' => 'high',
				'deadline' => '2027-01-01',
			]
		);

		self::assertSame(expected: 'low', actual: $resolved['priorityDerived']);
		self::assertSame(expected: 'high', actual: $resolved['priority']);
	}//end testAnExtendedTermDoesNotLowerARaisedPriority()

	/**
	 * A human override still beats a floor a rule set. The rule raises the
	 * cases nobody has looked at; a person who has looked at one outranks it.
	 *
	 * @return void
	 */
	public function testAHumanOverrideStillBeatsTheFloor(): void {
		$resolved = $this->service()->resolve(
			case: [
				'impact' => 'medium',
				'urgency' => 'medium',
				'priorityFloor' => 'urgent',
				'priorityOverride' => 'low',
			]
		);

		self::assertSame(expected: 'low', actual: $resolved['priority']);
	}//end testAHumanOverrideStillBeatsTheFloor()

	/**
	 * Every floor the declaration names is a value the priority enum carries.
	 *
	 * A declaration naming a fifth word would put that word in `case.priority`
	 * and break every reader of the enum at once, and it would do so only for
	 * the cases that happened to reach that threshold.
	 *
	 * @return void
	 */
	public function testEveryDeclaredFloorIsInTheVocabulary(): void {
		foreach ((array)$this->service()->termRaiseRule()['thresholds'] as $threshold) {
			self::assertContains(
				needle: (string)$threshold['minimumPriority'],
				haystack: CasePriorityService::PRIORITY_VALUES
			);
		}
	}//end testEveryDeclaredFloorIsInTheVocabulary()

	/**
	 * The declared thresholds rise as the term approaches, never the other way.
	 * A declaration that set `urgent` at fourteen days and `high` at two would
	 * be a rule that lowers, written as data.
	 *
	 * @return void
	 */
	public function testTheDeclaredThresholdsRiseAsTheTermApproaches(): void {
		$service = $this->service();
		$thresholds = (array)$service->termRaiseRule()['thresholds'];

		usort(
			$thresholds,
			static fn (array $a, array $b): int => ((int)$b['daysToTerm'] <=> (int)$a['daysToTerm'])
		);

		$previous = 0;
		foreach ($thresholds as $threshold) {
			$order = $service->orderOf(priority: (string)$threshold['minimumPriority']);
			self::assertGreaterThan(
				expected: $previous,
				actual: $order,
				message: 'a nearer term must name a higher floor than the one before it'
			);
			$previous = $order;
		}
	}//end testTheDeclaredThresholdsRiseAsTheTermApproaches()
}//end class
