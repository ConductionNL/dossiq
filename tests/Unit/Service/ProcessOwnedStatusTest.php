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
		$rules = $this->createMock(LifecycleCaseTypeRules::class);
		$rules->method('processOwnsStatus')->willReturn($owned);
		$rules->method('processOf')->willReturn($process);

		$store = $this->createMock(CaseStatusStore::class);
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
			$this->fail('a hand-set status must be refused where the process owns it');
		} catch (RefusedException $e) {
			$this->assertSame('process-owns-the-status', $e->getRule());
			$this->assertSame(RefusedException::STATUS_REFUSED, $e->getStatus());
			$this->assertStringContainsString('transition', $e->getSentence());
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
		$this->assertTrue($rule->allowsHandSet(caseTypeId: 'ct-1'));
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
			$this->fail('an unresolvable process must refuse the hand-set');
		} catch (RefusedException $e) {
			$this->assertSame('process-owned-status-unresolvable', $e->getRule());
			$this->assertSame(RefusedException::STATUS_INDETERMINATE, $e->getStatus());
		}
	}//end testAnUnresolvableProcessFailsClosed()

	/**
	 * The caseId form resolves the case's type and refuses on it.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testTheCaseIdFormResolvesTheCaseType(): void {
		$this->expectException(RefusedException::class);
		$this->expectExceptionMessage('process_owns_the_status');

		$this->rule(owned: true, process: 'wf-1')->requireHandSetAllowedOn(caseId: 'case-1');
	}//end testTheCaseIdFormResolvesTheCaseType()

	/**
	 * A case that cannot be read is not this rule's refusal to make.
	 *
	 * The write path behind it refuses an unreadable case with its own
	 * message; a second not-found from a rule about something else would tell
	 * the caller the wrong thing about why their request failed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/lifecycle-acts-on-the-case/specs/case-status-machinery/spec.md
	 */
	public function testAnUnreadableCaseIsLeftToTheWritePath(): void {
		$rules = $this->createMock(LifecycleCaseTypeRules::class);
		$rules->method('processOwnsStatus')->willReturn(true);
		$rules->method('processOf')->willReturn('wf-1');

		$store = $this->createMock(CaseStatusStore::class);
		$store->method('loadCase')->willReturn(null);

		$rule = new ProcessOwnedStatusRule(rules: $rules, store: $store);

		$rule->requireHandSetAllowedOn(caseId: 'missing');
		$this->addToAssertionCount(1);
	}//end testAnUnreadableCaseIsLeftToTheWritePath()
}//end class
