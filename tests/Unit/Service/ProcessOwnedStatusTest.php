<?php

/**
 * A case type may give its status to the process, and then no hand-set lands.
 *
 * Three answers, not two, and the third is the one worth a test of its own: a
 * case type that declares process ownership and whose process cannot be
 * resolved refuses the hand-set rather than falling through to it (ADR-102).
 * Falling through would mean the strictest case types silently became the
 * loosest the moment their process went missing.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Lifecycle\LifecycleCaseTypeRules;
use OCA\Dossiq\Service\Lifecycle\ProcessOwnedStatusRule;
use OCA\Dossiq\Service\Transitions\CaseStatusStore;
use PHPUnit\Framework\TestCase;

/**
 * The hand-set refusal, and the one case type that still accepts one.
 *
 * @covers \OCA\Dossiq\Service\Lifecycle\ProcessOwnedStatusRule
 * @uses \OCA\Dossiq\Exception\RefusedException
 */
class ProcessOwnedStatusTest extends TestCase {

	/**
	 * The rule, against a case type declaring what the arguments say.
	 *
	 * @param bool $owned Whether the case type gives its status to the process.
	 * @param string $process The workflow definition it names.
	 *
	 * @return ProcessOwnedStatusRule The rule under test.
	 */
	private function rule(bool $owned, string $process): ProcessOwnedStatusRule {
		$rules = $this->createMock(originalClassName: LifecycleCaseTypeRules::class);
		$rules->method('processOwnsStatus')->willReturn($owned);
		$rules->method('processOf')->willReturn($process);

		$store = $this->createMock(originalClassName: CaseStatusStore::class);
		$store->method('loadCase')->willReturn(['id' => 'case-1', 'caseType' => 'ct-1']);

		return new ProcessOwnedStatusRule(rules: $rules, store: $store);
	}//end rule()

	/**
	 * A hand-set status is refused where the process owns it, naming the rule.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testAHandSetStatusIsRefusedWhereTheProcessOwnsIt(): void {
		try {
			$this->rule(owned: true, process: 'wf-1')->requireHandSetAllowed(caseTypeId: 'ct-1');
			$this->fail(message: 'a hand-set status must be refused where the process owns it');
		} catch (RefusedException $e) {
			$this->assertSame(expected: 'process-owns-the-status', actual: $e->getRule());
			$this->assertSame(expected: RefusedException::STATUS_REFUSED, actual: $e->getStatus());
			$this->assertStringContainsString(needle: 'transition', haystack: $e->getSentence());
		}
	}//end testAHandSetStatusIsRefusedWhereTheProcessOwnsIt()

	/**
	 * A case type that does not declare it keeps today's behaviour.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testACaseTypeWithoutTheDeclarationAcceptsAHandSet(): void {
		$rule = $this->rule(owned: false, process: '');

		$rule->requireHandSetAllowed(caseTypeId: 'ct-1');
		$this->assertTrue(condition: $rule->allowsHandSet(caseTypeId: 'ct-1'));
	}//end testACaseTypeWithoutTheDeclarationAcceptsAHandSet()

	/**
	 * An unresolvable process fails CLOSED, and says the answer is neither.
	 *
	 * 503 rather than 409: the rule could not be evaluated, and telling a
	 * handler "the process forbids this" when nobody knows what the process is
	 * would be a claim the app cannot support.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testAnUnresolvableProcessFailsClosed(): void {
		try {
			$this->rule(owned: true, process: '')->requireHandSetAllowed(caseTypeId: 'ct-1');
			$this->fail(message: 'an unresolvable process must refuse the hand-set');
		} catch (RefusedException $e) {
			$this->assertSame(expected: 'process-owned-status-unresolvable', actual: $e->getRule());
			$this->assertSame(expected: RefusedException::STATUS_INDETERMINATE, actual: $e->getStatus());
		}
	}//end testAnUnresolvableProcessFailsClosed()

}//end class
