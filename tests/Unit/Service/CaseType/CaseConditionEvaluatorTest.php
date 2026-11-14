<?php

/**
 * Unit tests for CaseConditionEvaluator: a rule's condition over a case.
 *
 * 🔴 WHAT THESE GUARD IS A CONDITION ANSWERED WHEN IT COULD NOT BE ASKED.
 * Without OpenRegister's evaluator the answer is null, never false: false
 * reads as "the rule does not apply" and a caller would drop a required
 * document on every instance that runs without the flow engine. With it, the
 * case goes in under `json`, exactly as a rule flow's filter reads it, or a
 * condition authored as `json.bouwkosten` evaluates against nothing.
 *
 * Both tests run in a process of their own: the second declares a stand-in
 * for OpenRegister's expression class, and the first must not see it (nor a
 * real one another test loaded).
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\CaseType
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
 * @spec openspec/changes/case-type-conditional-rules/specs/case-types/spec.md#requirement-rules-are-evaluated-by-the-engines-that-own-the-actions
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\CaseType;

use OCA\Dossiq\Service\CaseType\CaseConditionEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for CaseConditionEvaluator.
 *
 * @covers \OCA\Dossiq\Service\CaseType\CaseConditionEvaluator
 */
class CaseConditionEvaluatorTest extends TestCase {

	/**
	 * OpenRegister's expression class, as the evaluator names it.
	 */
	private const EXPRESSIONS = 'OCA\OpenRegister\Service\Flow\FlowExpression';

	/**
	 * Without OpenRegister's evaluator the condition is unanswerable: null, not false.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testWithoutTheEvaluatorTheAnswerIsNull(): void {
		self::assertFalse(class_exists(self::EXPRESSIONS));

		self::assertNull((new CaseConditionEvaluator())->holds(condition: ['==' => [1, 1]], case: ['bouwkosten' => 1]));
	}//end testWithoutTheEvaluatorTheAnswerIsNull()

	/**
	 * With the evaluator, the case is handed over under `json`, and the answer is a bool.
	 *
	 * The stand-in records what it was asked and answers a truthy or falsy
	 * non-bool, so the test sees both the call shape and the cast.
	 *
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 *
	 * @return void
	 */
	public function testTheCaseIsReadUnderJsonAndTheAnswerIsABool(): void {
		// A stand-in for OpenRegister's FlowExpression, declared in this process only.
		eval(
			'namespace OCA\OpenRegister\Service\Flow;'
			. 'class FlowExpression {'
			. ' public static array $calls = [];'
			. ' public static function isTrue(array $logic, array $data): mixed {'
			. '  self::$calls[] = [$logic, $data];'
			. '  return ($data["json"]["bouwkosten"] ?? 0) > 100000 ? 1 : 0;'
			. ' }'
			. '}'
		);
		$expressions = self::EXPRESSIONS;
		$condition = ['>' => [['var' => 'json.bouwkosten'], 100000]];
		$evaluator = new CaseConditionEvaluator();

		self::assertTrue($evaluator->holds(condition: $condition, case: ['bouwkosten' => 150000]));
		self::assertFalse($evaluator->holds(condition: $condition, case: ['bouwkosten' => 90000]));
		self::assertSame(
			[
				[$condition, ['json' => ['bouwkosten' => 150000]]],
				[$condition, ['json' => ['bouwkosten' => 90000]]],
			],
			$expressions::$calls
		);
	}//end testTheCaseIsReadUnderJsonAndTheAnswerIsABool()
}//end class
