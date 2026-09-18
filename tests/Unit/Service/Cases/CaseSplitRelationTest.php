<?php

/**
 * A case type bounds what a split may divide, and the refusal says so.
 *
 * 🔴 ABSENT MEANS ALL THREE. Every case type on every install predates this
 * declaration, and a policy that read silence as "nothing may be divided"
 * would ship a split action that refuses every split on the day it arrives —
 * a feature that is present, clickable and useless, which is worse than one
 * that is not there.
 *
 * 🔴 THE REFUSAL NAMES THE RULE AND WHAT IS STILL POSSIBLE (ADR-050). A
 * handler told only what they may not do has to guess at the rest, and the
 * guess is usually "nothing", so the split goes unused on the case types where
 * it would have helped.
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

use OCA\Dossiq\Service\Cases\CaseSplitPolicy;
use PHPUnit\Framework\TestCase;

/**
 * The case type's bound on a split.
 *
 * @spec openspec/changes/splitting-a-case-and-its-incidents/specs/case-management/spec.md
 */
class CaseSplitRelationTest extends TestCase {
	private CaseSplitPolicy $policy;

	/**
	 * Set up.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->policy = new CaseSplitPolicy();
	}//end setUp()

	/**
	 * A case type that says nothing allows everything.
	 *
	 * @return void
	 */
	public function testACaseTypeThatSaysNothingAllowsEverything(): void {
		$this->assertSame(['documents', 'parties', 'tasks'], $this->policy->allowedFor(caseType: []));
		$this->assertSame(['documents', 'parties', 'tasks'], $this->policy->allowedFor(caseType: null));
		$this->assertSame('', $this->policy->whyRefused(selected: ['documents', 'parties'], caseType: []));
	}//end testACaseTypeThatSaysNothingAllowsEverything()

	/**
	 * A declaration is honoured, in a stable order.
	 *
	 * @return void
	 */
	public function testADeclarationIsHonoured(): void {
		$caseType = ['splittableParts' => ['tasks', 'parties']];

		$this->assertSame(['parties', 'tasks'], $this->policy->allowedFor(caseType: $caseType));
		$this->assertSame('', $this->policy->whyRefused(selected: ['tasks'], caseType: $caseType));
	}//end testADeclarationIsHonoured()

	/**
	 * A refused part is named, and so is what may still be divided.
	 *
	 * @return void
	 */
	public function testTheRefusalNamesTheRuleAndWhatIsStillPossible(): void {
		$refusal = $this->policy->whyRefused(
			selected: ['documents'],
			caseType: ['splittableParts' => ['parties', 'tasks']],
		);

		$this->assertStringContainsString('does not allow documents', $refusal);
		$this->assertStringContainsString('parties and tasks', $refusal);
	}//end testTheRefusalNamesTheRuleAndWhatIsStillPossible()

	/**
	 * A case type that forbids everything says that plainly.
	 *
	 * @return void
	 */
	public function testACaseTypeThatForbidsEverythingSaysSo(): void {
		$refusal = $this->policy->whyRefused(selected: ['documents'], caseType: ['splittableParts' => []]);

		$this->assertStringContainsString('no part of a case to be divided', $refusal);
	}//end testACaseTypeThatForbidsEverythingSaysSo()

	/**
	 * A part nobody has heard of is refused as such, rather than being quietly
	 * dropped and reported as a successful split of nothing.
	 *
	 * @return void
	 */
	public function testAnUnknownPartIsRefusedByName(): void {
		$refusal = $this->policy->whyRefused(selected: ['decisions'], caseType: []);

		$this->assertStringContainsString('"decisions" is none of those', $refusal);
	}//end testAnUnknownPartIsRefusedByName()

	/**
	 * A declaration naming something the vocabulary does not hold is ignored
	 * rather than widening what may be divided.
	 *
	 * @return void
	 */
	public function testAnUnknownPartInTheDeclarationWidensNothing(): void {
		$allowed = $this->policy->allowedFor(caseType: ['splittableParts' => ['documents', 'decisions']]);

		$this->assertSame(['documents'], $allowed);
	}//end testAnUnknownPartInTheDeclarationWidensNothing()
}//end class
