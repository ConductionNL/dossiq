<?php

/**
 * DecisionEngine Unit Tests
 *
 * Hit-policy behaviour (UNIQUE no-match/single-match/ambiguous-match,
 * FIRST declaration-order precedence, COLLECT aggregation incl. zero-match
 * empty arrays, PRIORITY/ANY rejected), plus unknown_input/missing_input/
 * type_mismatch input validation.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Dmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/dmn-decision-tables/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Dmn;

use OCA\Procest\Service\Dmn\DecisionEngine;
use OCA\Procest\Service\Dmn\DecisionEvaluationException;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Service\Dmn\DecisionEngine
 */
class DecisionEngineTest extends TestCase
{

    private DecisionEngine $engine;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->engine = new DecisionEngine();
    }//end setUp()

    /**
     * @return array<string, mixed>
     */
    private function eligibilityTable(string $hitPolicy): array
    {
        return [
            'hitPolicy' => $hitPolicy,
            'inputs'    => [
                ['name' => 'income', 'type' => 'number'],
                ['name' => 'householdSize', 'type' => 'number'],
            ],
            'outputs'   => [
                ['name' => 'eligible', 'type' => 'boolean'],
                ['name' => 'tier', 'type' => 'string'],
            ],
            'rules'     => [
                [
                    'id'            => 'r1',
                    'inputEntries'  => ['[0..25000]', '-'],
                    'outputEntries' => [true, 'gold'],
                ],
                [
                    'id'            => 'r2',
                    'inputEntries'  => ['(25000..40000]', '>=4'],
                    'outputEntries' => [true, 'silver'],
                ],
                [
                    'id'            => 'r3',
                    'inputEntries'  => ['-', '-'],
                    'outputEntries' => [false, 'none'],
                ],
            ],
        ];
    }//end eligibilityTable()

    /**
     * A UNIQUE-safe fixture: rules partition the `income` range with NO
     * overlap (unlike {@see eligibilityTable()}, which deliberately has an
     * overlapping catch-all for FIRST/COLLECT tests), so exactly one rule
     * matches any income >= 0.
     *
     * @return array<string, mixed>
     */
    private function partitionedIncomeTable(): array
    {
        return [
            'hitPolicy' => 'UNIQUE',
            'inputs'    => [['name' => 'income', 'type' => 'number']],
            'outputs'   => [['name' => 'eligible', 'type' => 'boolean'], ['name' => 'tier', 'type' => 'string']],
            'rules'     => [
                ['id' => 'r1', 'inputEntries' => ['[0..25000]'], 'outputEntries' => [true, 'gold']],
                ['id' => 'r2', 'inputEntries' => ['(25000..40000]'], 'outputEntries' => [true, 'silver']],
                ['id' => 'r3', 'inputEntries' => ['> 40000'], 'outputEntries' => [false, 'none']],
            ],
        ];
    }//end partitionedIncomeTable()

    // ------------------------------------------------------------------
    // UNIQUE
    // ------------------------------------------------------------------

    /**
     * @return void
     */
    public function testUniqueSingleMatchReturnsOutputs(): void
    {
        $result = $this->engine->evaluate(
            decisionTable: $this->partitionedIncomeTable(),
            inputs: ['income' => 10000],
        );

        self::assertSame(['eligible' => true, 'tier' => 'gold'], $result['outputs']);
        self::assertSame(['r1'], $result['matchedRuleIds']);
        self::assertSame('UNIQUE', $result['hitPolicy']);
    }//end testUniqueSingleMatchReturnsOutputs()

    /**
     * @return void
     */
    public function testUniqueNoMatchThrows(): void
    {
        // Negative income falls outside every partitioned range.
        $this->expectException(DecisionEvaluationException::class);
        try {
            $this->engine->evaluate(decisionTable: $this->partitionedIncomeTable(), inputs: ['income' => -100]);
        } catch (DecisionEvaluationException $e) {
            self::assertSame('no_rule_matched', $e->getErrorCode());
            throw $e;
        }
    }//end testUniqueNoMatchThrows()

    /**
     * @return void
     */
    public function testUniqueAmbiguousMatchThrows(): void
    {
        // Both rules match income=20000 (r1: [0..25000], overlapping r2 catch-all).
        $table = [
            'hitPolicy' => 'UNIQUE',
            'inputs'    => [['name' => 'income', 'type' => 'number']],
            'outputs'   => [['name' => 'eligible', 'type' => 'boolean']],
            'rules'     => [
                ['id' => 'r1', 'inputEntries' => ['[0..25000]'], 'outputEntries' => [true]],
                ['id' => 'r2', 'inputEntries' => ['-'], 'outputEntries' => [false]],
            ],
        ];

        $this->expectException(DecisionEvaluationException::class);
        try {
            $this->engine->evaluate(decisionTable: $table, inputs: ['income' => 20000]);
        } catch (DecisionEvaluationException $e) {
            self::assertSame('hit_policy_violation', $e->getErrorCode());
            self::assertSame(['r1', 'r2'], $e->getDetails()['matchedRuleIds']);
            throw $e;
        }
    }//end testUniqueAmbiguousMatchThrows()

    // ------------------------------------------------------------------
    // FIRST
    // ------------------------------------------------------------------

    /**
     * @return void
     */
    public function testFirstReturnsEarliestMatch(): void
    {
        // Both r1 and r3 (catch-all) match income=10000; FIRST picks r1.
        $result = $this->engine->evaluate(
            decisionTable: $this->eligibilityTable(hitPolicy: 'FIRST'),
            inputs: ['income' => 10000, 'householdSize' => 1],
        );

        self::assertSame(['r1'], $result['matchedRuleIds']);
        self::assertSame('gold', $result['outputs']['tier']);
    }//end testFirstReturnsEarliestMatch()

    /**
     * @return void
     */
    public function testFirstNoMatchThrows(): void
    {
        $table = $this->eligibilityTable(hitPolicy: 'FIRST');
        unset($table['rules'][2]);

        $this->expectException(DecisionEvaluationException::class);
        try {
            $this->engine->evaluate(decisionTable: $table, inputs: ['income' => 999999, 'householdSize' => 1]);
        } catch (DecisionEvaluationException $e) {
            self::assertSame('no_rule_matched', $e->getErrorCode());
            throw $e;
        }
    }//end testFirstNoMatchThrows()

    // ------------------------------------------------------------------
    // COLLECT
    // ------------------------------------------------------------------

    /**
     * @return void
     */
    public function testCollectAggregatesAllMatches(): void
    {
        // income=10000 matches r1 AND r3 (catch-all).
        $result = $this->engine->evaluate(
            decisionTable: $this->eligibilityTable(hitPolicy: 'COLLECT'),
            inputs: ['income' => 10000, 'householdSize' => 1],
        );

        self::assertSame([true, false], $result['outputs']['eligible']);
        self::assertSame(['gold', 'none'], $result['outputs']['tier']);
        self::assertSame(['r1', 'r3'], $result['matchedRuleIds']);
    }//end testCollectAggregatesAllMatches()

    /**
     * @return void
     */
    public function testCollectWithNoMatchReturnsEmptyArraysNotError(): void
    {
        $table = $this->eligibilityTable(hitPolicy: 'COLLECT');
        unset($table['rules'][2]);

        $result = $this->engine->evaluate(decisionTable: $table, inputs: ['income' => 999999, 'householdSize' => 1]);

        self::assertSame([], $result['outputs']['eligible']);
        self::assertSame([], $result['outputs']['tier']);
        self::assertSame([], $result['matchedRuleIds']);
    }//end testCollectWithNoMatchReturnsEmptyArraysNotError()

    // ------------------------------------------------------------------
    // PRIORITY / ANY — documented as not implemented
    // ------------------------------------------------------------------

    /**
     * @return void
     */
    public function testPriorityHitPolicyIsRejected(): void
    {
        $table = $this->eligibilityTable(hitPolicy: 'PRIORITY');

        $this->expectException(DecisionEvaluationException::class);
        try {
            $this->engine->evaluate(decisionTable: $table, inputs: ['income' => 10000, 'householdSize' => 1]);
        } catch (DecisionEvaluationException $e) {
            self::assertSame('hit_policy_not_implemented', $e->getErrorCode());
            throw $e;
        }
    }//end testPriorityHitPolicyIsRejected()

    /**
     * @return void
     */
    public function testAnyHitPolicyIsRejected(): void
    {
        $table = $this->eligibilityTable(hitPolicy: 'ANY');

        $this->expectException(DecisionEvaluationException::class);
        try {
            $this->engine->evaluate(decisionTable: $table, inputs: ['income' => 10000, 'householdSize' => 1]);
        } catch (DecisionEvaluationException $e) {
            self::assertSame('hit_policy_not_implemented', $e->getErrorCode());
            throw $e;
        }
    }//end testAnyHitPolicyIsRejected()

    // ------------------------------------------------------------------
    // Input validation
    // ------------------------------------------------------------------

    /**
     * @return void
     */
    public function testUnknownInputThrows(): void
    {
        $this->expectException(DecisionEvaluationException::class);
        try {
            $this->engine->evaluate(
                decisionTable: $this->eligibilityTable(hitPolicy: 'UNIQUE'),
                inputs: ['income' => 10000, 'houshold' => 2],
            );
        } catch (DecisionEvaluationException $e) {
            self::assertSame('unknown_input', $e->getErrorCode());
            self::assertSame('houshold', $e->getDetails()['key']);
            throw $e;
        }
    }//end testUnknownInputThrows()

    /**
     * @return void
     */
    public function testMissingInputThrows(): void
    {
        $this->expectException(DecisionEvaluationException::class);
        try {
            $this->engine->evaluate(
                decisionTable: $this->eligibilityTable(hitPolicy: 'UNIQUE'),
                inputs: ['income' => 10000],
            );
        } catch (DecisionEvaluationException $e) {
            self::assertSame('missing_input', $e->getErrorCode());
            self::assertSame('householdSize', $e->getDetails()['name']);
            throw $e;
        }
    }//end testMissingInputThrows()

    /**
     * @return void
     */
    public function testTypeMismatchThrowsBeforeEvaluatingRules(): void
    {
        $this->expectException(DecisionEvaluationException::class);
        try {
            $this->engine->evaluate(
                decisionTable: $this->eligibilityTable(hitPolicy: 'UNIQUE'),
                inputs: ['income' => 'not-a-number', 'householdSize' => 2],
            );
        } catch (DecisionEvaluationException $e) {
            self::assertSame('type_mismatch', $e->getErrorCode());
            throw $e;
        }
    }//end testTypeMismatchThrowsBeforeEvaluatingRules()
}//end class
