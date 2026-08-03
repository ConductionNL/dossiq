<?php

/**
 * EvaluateDecisionHandler Unit Tests
 *
 * End-to-end: a transition's `automaticActions[]` entry (decisionKey +
 * input/output mapping) actually invokes DecisionTableService::findByKey()
 * + DecisionEngine::evaluate() and writes the result onto the case via
 * ObjectService — proving the DMN capability is reachable from the
 * workflow engine and NOT an orphaned capability.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Transitions
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

namespace OCA\Procest\Tests\Unit\Service\Transitions;

use OCA\Procest\Service\Dmn\DecisionEngine;
use OCA\Procest\Service\Dmn\DecisionTableService;
use OCA\Procest\Service\SettingsService;
use OCA\Procest\Service\Transitions\EvaluateDecisionHandler;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Procest\Service\Transitions\EvaluateDecisionHandler
 */
class EvaluateDecisionHandlerTest extends TestCase
{

    /**
     * @return array<string, mixed>
     */
    private function subsidyEligibilityTable(): array
    {
        return [
            'key'       => 'subsidy-eligibility',
            'hitPolicy' => 'UNIQUE',
            'inputs'    => [
                ['name' => 'income', 'type' => 'number'],
                ['name' => 'householdSize', 'type' => 'number'],
            ],
            'outputs'   => [
                ['name' => 'eligible', 'type' => 'boolean'],
                ['name' => 'tier', 'type' => 'string'],
            ],
            'rules'     => [
                // Mutually exclusive on `income` so UNIQUE never sees more
                // than one match — a well-formed DMN UNIQUE table.
                ['id' => 'r1', 'inputEntries' => ['[0..25000]', '-'], 'outputEntries' => [true, 'gold']],
                ['id' => 'r2', 'inputEntries' => ['(25000..40000]', '>=4'], 'outputEntries' => [true, 'silver']],
                ['id' => 'r3', 'inputEntries' => ['> 40000', '-'], 'outputEntries' => [false, 'none']],
            ],
        ];
    }//end subsidyEligibilityTable()

    /**
     * @return void
     */
    public function testFailsWhenDecisionKeyMissing(): void
    {
        $handler = new EvaluateDecisionHandler(
            tableService: $this->createMock(DecisionTableService::class),
            engine: new DecisionEngine(),
            settingsService: $this->createMock(SettingsService::class),
            logger: new NullLogger(),
        );

        $result = $handler->handle(actionConfig: ['type' => 'evaluateDecision'], case: [], transitionContext: []);

        self::assertFalse($result->succeeded);
        self::assertSame('evaluate_decision_missing_key', $result->error);
    }//end testFailsWhenDecisionKeyMissing()

    /**
     * @return void
     */
    public function testFailsWhenDecisionNotFound(): void
    {
        $tableService = $this->createMock(DecisionTableService::class);
        $tableService->method('findByKey')->with('unknown-key')->willReturn(null);

        $handler = new EvaluateDecisionHandler(
            tableService: $tableService,
            engine: new DecisionEngine(),
            settingsService: $this->createMock(SettingsService::class),
            logger: new NullLogger(),
        );

        $result = $handler->handle(
            actionConfig: ['type' => 'evaluateDecision', 'decisionKey' => 'unknown-key'],
            case: [],
            transitionContext: [],
        );

        self::assertFalse($result->succeeded);
        self::assertSame('decision_not_found', $result->error);
    }//end testFailsWhenDecisionNotFound()

    /**
     * End-to-end: transition action config -> handler -> real DecisionEngine
     * -> case field written via ObjectService::saveObject, proving the
     * workflow hook is real and reachable, not orphaned.
     *
     * @return void
     */
    public function testEvaluatesDecisionAndWritesOutputsOntoCase(): void
    {
        $tableService = $this->createMock(DecisionTableService::class);
        $tableService->method('findByKey')->with('subsidy-eligibility')->willReturn($this->subsidyEligibilityTable());

        $recorded      = null;
        $objectService = new class($recorded) {
            /** @var mixed */
            public $recorded;

            public function __construct(&$recorded)
            {
                $this->recorded = &$recorded;
            }

            public function saveObject(array $object, string $register, string $schema): array
            {
                $this->recorded = $object;
                return $object;
            }
        };

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($objectService);
        $settings->method('getConfigValue')->willReturnCallback(
            function (string $key): string {
                return [
                    'register'    => 'reg-1',
                    'case_schema' => 'case-schema',
                ][$key] ?? '';
            }
        );

        $handler = new EvaluateDecisionHandler(
            tableService: $tableService,
            engine: new DecisionEngine(),
            settingsService: $settings,
            logger: new NullLogger(),
        );

        $case = [
            'id'             => 'case-1',
            'declaredIncome' => 20000,
            'householdSize'  => 2,
        ];

        $result = $handler->handle(
            actionConfig: [
                'type'          => 'evaluateDecision',
                'decisionKey'   => 'subsidy-eligibility',
                'inputMapping'  => ['income' => 'declaredIncome', 'householdSize' => 'householdSize'],
                'outputMapping' => ['eligible' => 'subsidyEligible', 'tier' => 'subsidyTier'],
            ],
            case: $case,
            transitionContext: ['toStatus' => 'beoordeeld'],
        );

        self::assertTrue($result->succeeded);
        self::assertSame(['eligible' => true, 'tier' => 'gold'], $result->data['outputs']);

        // The persisted case (via ObjectService::saveObject) carries the
        // decision's outputs under the configured outputMapping fields.
        self::assertSame(true, $recorded['subsidyEligible']);
        self::assertSame('gold', $recorded['subsidyTier']);
        // Original case fields are preserved.
        self::assertSame('case-1', $recorded['id']);
        self::assertSame(20000, $recorded['declaredIncome']);
    }//end testEvaluatesDecisionAndWritesOutputsOntoCase()

    /**
     * @return void
     */
    public function testSameNameDefaultMappingWhenNoMappingConfigured(): void
    {
        $tableService = $this->createMock(DecisionTableService::class);
        $tableService->method('findByKey')->willReturn($this->subsidyEligibilityTable());

        $recorded      = null;
        $objectService = new class($recorded) {
            /** @var mixed */
            public $recorded;

            public function __construct(&$recorded)
            {
                $this->recorded = &$recorded;
            }

            public function saveObject(array $object, string $register, string $schema): array
            {
                $this->recorded = $object;
                return $object;
            }
        };

        $settings = $this->createMock(SettingsService::class);
        $settings->method('getObjectService')->willReturn($objectService);
        $settings->method('getConfigValue')->willReturnCallback(
            function (string $key): string {
                return [
                    'register'    => 'reg-1',
                    'case_schema' => 'case-schema',
                ][$key] ?? '';
            }
        );

        $handler = new EvaluateDecisionHandler(
            tableService: $tableService,
            engine: new DecisionEngine(),
            settingsService: $settings,
            logger: new NullLogger(),
        );

        // No inputMapping/outputMapping: same-name default — the case must
        // carry fields literally named `income`/`householdSize`.
        $case = ['income' => 10000, 'householdSize' => 1];

        $result = $handler->handle(
            actionConfig: ['type' => 'evaluateDecision', 'decisionKey' => 'subsidy-eligibility'],
            case: $case,
            transitionContext: [],
        );

        self::assertTrue($result->succeeded);
        self::assertSame(true, $recorded['eligible']);
        self::assertSame('gold', $recorded['tier']);
    }//end testSameNameDefaultMappingWhenNoMappingConfigured()

    /**
     * A decision-evaluation failure (e.g. no rule matched) surfaces as a
     * failed ActionResult and MUST NOT write anything onto the case —
     * mirrors REQ-STE-5-002 (side-effect failure never rolls back the
     * status change, but also never silently half-writes a case).
     *
     * @return void
     */
    public function testEvaluationFailureDoesNotWriteCase(): void
    {
        $table = $this->subsidyEligibilityTable();
        // Remove the catch-all so an out-of-range income yields no match.
        unset($table['rules'][2]);

        $tableService = $this->createMock(DecisionTableService::class);
        $tableService->method('findByKey')->willReturn($table);

        $settings = $this->createMock(SettingsService::class);
        // getObjectService() must never be consulted — no write should be attempted.
        $settings->expects(self::never())->method('getObjectService');

        $handler = new EvaluateDecisionHandler(
            tableService: $tableService,
            engine: new DecisionEngine(),
            settingsService: $settings,
            logger: new NullLogger(),
        );

        $result = $handler->handle(
            actionConfig: ['type' => 'evaluateDecision', 'decisionKey' => 'subsidy-eligibility'],
            case: ['income' => 999999, 'householdSize' => 1],
            transitionContext: [],
        );

        self::assertFalse($result->succeeded);
        self::assertSame('no_rule_matched', $result->error);
    }//end testEvaluationFailureDoesNotWriteCase()
}//end class
