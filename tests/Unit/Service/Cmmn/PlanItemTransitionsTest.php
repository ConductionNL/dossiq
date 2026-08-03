<?php

/**
 * PlanItemTransitions legal-transition-table tests.
 *
 * Exhaustively exercises every legal transition per `design.md` §3 for each
 * plan-item type, and asserts that anything not in the table — including
 * same-state "transitions" and any move out of a terminal state — throws
 * `IllegalPlanItemTransitionException` rather than silently no-opping.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\Cmmn
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-002
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Cmmn;

use OCA\Procest\Service\Cmmn\IllegalPlanItemTransitionException;
use OCA\Procest\Service\Cmmn\PlanItemTransitions;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Service\Cmmn\PlanItemTransitions
 */
final class PlanItemTransitionsTest extends TestCase
{

    /**
     * The transition table under test.
     *
     * @var PlanItemTransitions
     */
    private PlanItemTransitions $table;

    /**
     * Build the (stateless) transition table for each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->table = new PlanItemTransitions();
    }//end setUp()

    /**
     * Every legal transition for stage/humanTask succeeds.
     *
     * @return void
     */
    public function testLegalStageAndHumanTaskTransitionsSucceed(): void
    {
        $legal = [
            ['available', 'enabled'],
            ['available', 'disabled'],
            ['available', 'terminated'],
            ['enabled', 'active'],
            ['enabled', 'terminated'],
            ['enabled', 'disabled'],
            ['active', 'completed'],
            ['active', 'terminated'],
        ];

        foreach ([PlanItemTransitions::TYPE_STAGE, PlanItemTransitions::TYPE_HUMAN_TASK] as $type) {
            foreach ($legal as [$from, $to]) {
                self::assertTrue(
                    $this->table->isLegal(itemType: $type, fromState: $from, toState: $to),
                    "expected {$type} {$from}->{$to} to be legal",
                );
                // Must not throw.
                $this->table->assertLegal(itemId: 'x', itemType: $type, fromState: $from, toState: $to);
            }
        }

        self::assertTrue(true);
    }//end testLegalStageAndHumanTaskTransitionsSucceed()

    /**
     * Every legal milestone transition succeeds and no enabled/active state exists.
     *
     * @return void
     */
    public function testLegalMilestoneTransitionsSucceed(): void
    {
        $this->table->assertLegal(itemId: 'm1', itemType: PlanItemTransitions::TYPE_MILESTONE, fromState: 'available', toState: 'completed');
        $this->table->assertLegal(itemId: 'm1', itemType: PlanItemTransitions::TYPE_MILESTONE, fromState: 'available', toState: 'terminated');

        self::assertFalse($this->table->isLegal(itemType: PlanItemTransitions::TYPE_MILESTONE, fromState: 'available', toState: 'enabled'));
        self::assertFalse($this->table->isLegal(itemType: PlanItemTransitions::TYPE_MILESTONE, fromState: 'available', toState: 'active'));
        self::assertFalse($this->table->isLegal(itemType: PlanItemTransitions::TYPE_MILESTONE, fromState: 'enabled', toState: 'completed'));
    }//end testLegalMilestoneTransitionsSucceed()

    /**
     * Any transition out of a terminal state is illegal, for every type.
     *
     * @return void
     */
    public function testTransitionsOutOfTerminalStatesAreIllegal(): void
    {
        $terminals = ['completed', 'terminated', 'disabled'];
        $targets   = ['available', 'enabled', 'active', 'completed', 'terminated', 'disabled'];

        foreach ([PlanItemTransitions::TYPE_STAGE, PlanItemTransitions::TYPE_HUMAN_TASK, PlanItemTransitions::TYPE_MILESTONE] as $type) {
            foreach ($terminals as $from) {
                foreach ($targets as $to) {
                    self::assertFalse(
                        $this->table->isLegal(itemType: $type, fromState: $from, toState: $to),
                        "expected {$type} {$from}->{$to} to be illegal (terminal source)",
                    );
                }
            }
        }
    }//end testTransitionsOutOfTerminalStatesAreIllegal()

    /**
     * A same-state "transition" is illegal — the engine never no-ops.
     *
     * @return void
     */
    public function testSameStateTransitionIsIllegal(): void
    {
        $this->expectException(IllegalPlanItemTransitionException::class);
        $this->table->assertLegal(itemId: 't1', itemType: PlanItemTransitions::TYPE_HUMAN_TASK, fromState: 'enabled', toState: 'enabled');
    }//end testSameStateTransitionIsIllegal()

    /**
     * An illegal transition throws with the item context attached.
     *
     * @return void
     */
    public function testIllegalTransitionCarriesContext(): void
    {
        try {
            $this->table->assertLegal(itemId: 'task-1', itemType: PlanItemTransitions::TYPE_HUMAN_TASK, fromState: 'completed', toState: 'active');
            self::fail('expected IllegalPlanItemTransitionException');
        } catch (IllegalPlanItemTransitionException $e) {
            self::assertSame('task-1', $e->getItemId());
            self::assertSame(PlanItemTransitions::TYPE_HUMAN_TASK, $e->getItemType());
            self::assertSame('completed', $e->getFromState());
            self::assertSame('active', $e->getToState());
        }
    }//end testIllegalTransitionCarriesContext()

    /**
     * `initialState()` and `isTerminal()` behave as documented.
     *
     * @return void
     */
    public function testInitialStateAndTerminalHelpers(): void
    {
        self::assertSame('available', $this->table->initialState());
        self::assertTrue($this->table->isTerminal(state: 'completed'));
        self::assertTrue($this->table->isTerminal(state: 'terminated'));
        self::assertTrue($this->table->isTerminal(state: 'disabled'));
        self::assertFalse($this->table->isTerminal(state: 'available'));
        self::assertFalse($this->table->isTerminal(state: 'enabled'));
        self::assertFalse($this->table->isTerminal(state: 'active'));
    }//end testInitialStateAndTerminalHelpers()
}//end class
