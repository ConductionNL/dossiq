<?php

/**
 * A status requires documents before a case enters it, from the case type's rules.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Transitions;

use OCA\Dossiq\Service\CaseType\CaseConditionEvaluator;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;
use OCA\Dossiq\Service\Transitions\GuardRegistry;
use OCA\Dossiq\Service\Transitions\RequiredDocumentGuard;
use OCA\Dossiq\Service\Transitions\StatusEntryRequirementsGuard;
use OCA\Dossiq\Service\Transitions\TransitionSpecReader;
use OCP\IL10N;
use PHPUnit\Framework\TestCase;

/**
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */
class StatusEntryRequirementsGuardTest extends TestCase {

	/**
	 * The guard over a case type requiring a welstandsadvies before status-besluit.
	 *
	 * @return StatusEntryRequirementsGuard The guard.
	 */
	private function guard(?bool $holds = true): StatusEntryRequirementsGuard {
		$caseTypes = $this->createMock(CaseTypeReader::class);
		$caseTypes->method('read')->willReturn(
			['entryRequirements' => [
				'status-besluit' => [['documentType' => 'welstandsadvies', 'rule' => 'r1']],
				'status-vergunning' => [['documentType' => 'constructieberekening', 'rule' => 'r2', 'condition' => ['>' => [['var' => 'json.bouwkosten'], 100000]]]],
			]]
		);
		$conditions = $this->createMock(CaseConditionEvaluator::class);
		$conditions->method('holds')->willReturn($holds);
		$l10n = $this->createMock(IL10N::class);
		$l10n->method('t')->willReturnCallback(static fn (string $text, array $p = []): string => vsprintf(str_replace('%s', '%s', $text), $p));

		return new StatusEntryRequirementsGuard(caseTypes: $caseTypes, documents: new RequiredDocumentGuard(l10n: $l10n), conditions: $conditions);
	}//end guard()

	/**
	 * Entering the status without the document is refused; with it, and into another status, it passes.
	 *
	 * @return void
	 */
	public function testAStatusRefusesEntryWithoutItsRequiredDocument(): void {
		$guard = $this->guard();
		$case = ['caseType' => 'type-1', 'documents' => []];

		$refused = $guard->evaluate(['toStatus' => 'status-besluit'], $case, 'pieter');
		$this->assertFalse($refused->passed);
		$this->assertSame('Required document missing: welstandsadvies', $refused->failureMessage);

		$case['documents'] = [['documentType' => 'welstandsadvies']];
		$this->assertTrue($guard->evaluate(['toStatus' => 'status-besluit'], $case, 'pieter')->passed);
		$this->assertTrue($guard->evaluate(['toStatus' => 'status-intake'], ['caseType' => 'type-1'], 'pieter')->passed);
	}//end testAStatusRefusesEntryWithoutItsRequiredDocument()

	/**
	 * The engine appends the guard with the status being entered, only when it is wired.
	 *
	 * @return void
	 */
	public function testTheEngineAppendsTheGuardWhenWired(): void {
		$reader = (new \ReflectionClass(TransitionSpecReader::class))->newInstanceWithoutConstructor();
		$transition = ['id' => 'besluiten', 'toStatus' => 'status-besluit'];

		$wired = $reader->guardsWithImplicit(transition: $transition, approvalsWired: false, entryGuardWired: true);
		$this->assertContains(['type' => GuardRegistry::STATUS_ENTRY_REQUIREMENTS, 'toStatus' => 'status-besluit'], $wired);

		$unwired = $reader->guardsWithImplicit(transition: $transition, approvalsWired: false);
		$this->assertNotContains(GuardRegistry::STATUS_ENTRY_REQUIREMENTS, array_column($unwired, 'type'));
	}//end testTheEngineAppendsTheGuardWhenWired()
	/**
	 * A conditional requirement applies while its condition holds, and when it
	 * cannot be evaluated; it does not when the condition is false.
	 *
	 * @return void
	 */
	public function testAConditionalRequirementAppliesOnlyWhileItHolds(): void {
		$case = ['caseType' => 'type-1', 'documents' => []];
		$into = ['toStatus' => 'status-vergunning'];

		$this->assertFalse($this->guard(holds: true)->evaluate($into, $case, 'pieter')->passed);
		$this->assertFalse($this->guard(holds: null)->evaluate($into, $case, 'pieter')->passed);
		$this->assertTrue($this->guard(holds: false)->evaluate($into, $case, 'pieter')->passed);
	}//end testAConditionalRequirementAppliesOnlyWhileItHolds()

	/**
	 * The evaluator reads the case under json, as a rule flow's filter does.
	 *
	 * @return void
	 */
	public function testTheEvaluatorReadsTheCaseAsTheFilterDoes(): void {
		$library = dirname(__DIR__, 5) . '/openregister/lib/Service/Flow/FlowExpression.php';
		if (is_file($library) === false) {
			$this->markTestSkipped('needs a sibling openregister checkout with its vendor');
		}

		require_once dirname(__DIR__, 5) . '/openregister/vendor/autoload.php';
		require_once $library;
		$evaluator = new CaseConditionEvaluator();
		$condition = ['>' => [['var' => 'json.bouwkosten'], 100000]];

		$this->assertTrue($evaluator->holds(condition: $condition, case: ['bouwkosten' => 150000]));
		$this->assertFalse($evaluator->holds(condition: $condition, case: ['bouwkosten' => 90000]));
	}//end testTheEvaluatorReadsTheCaseAsTheFilterDoes()
}//end class
