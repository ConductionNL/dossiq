<?php

/**
 * TransitionMatrixBuilder Unit Tests
 *
 * Direct tests for the from->to transition matrix and its rework-loop
 * detection. These exercise the builder as the unit under test rather than
 * through ProcessMiningService, so the coverage they produce is attributed to
 * this class instead of being discarded as a `@uses` collaborator.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service\ProcessMining
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/process-mining-bottlenecks/tasks.md#T04
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\ProcessMining;

use OCA\Procest\Service\ProcessMining\TransitionMatrixBuilder;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Service\ProcessMining\TransitionMatrixBuilder
 */
class TransitionMatrixBuilderTest extends TestCase
{

    private TransitionMatrixBuilder $builder;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        $this->builder = new TransitionMatrixBuilder();

    }//end setUp()

    /**
     * A case with fewer than two records has no transitions at all.
     *
     * @return void
     */
    public function testFewerThanTwoRecordsYieldsNoTransitions(): void
    {
        self::assertSame([], $this->builder->computeCaseTransitions([]));
        self::assertSame(
            [],
            $this->builder->computeCaseTransitions([['statusType' => 'intake']])
        );

    }//end testFewerThanTwoRecordsYieldsNoTransitions()

    /**
     * Consecutive records become from->to pairs, and a first visit is not
     * rework.
     *
     * @return void
     */
    public function testLinearHistoryProducesNonReworkTransitions(): void
    {
        $transitions = $this->builder->computeCaseTransitions(
            [
                ['statusType' => 'intake'],
                ['statusType' => 'review'],
                ['statusType' => 'done'],
            ]
        );

        self::assertCount(2, $transitions);
        self::assertSame('intake', $transitions[0]['from']);
        self::assertSame('review', $transitions[0]['to']);
        self::assertFalse($transitions[0]['isRework']);
        self::assertSame('review', $transitions[1]['from']);
        self::assertSame('done', $transitions[1]['to']);
        self::assertFalse($transitions[1]['isRework']);

    }//end testLinearHistoryProducesNonReworkTransitions()

    /**
     * Returning to a status the case had already left is a rework loop.
     *
     * @return void
     */
    public function testRevisitingAnEarlierStatusIsFlaggedAsRework(): void
    {
        $transitions = $this->builder->computeCaseTransitions(
            [
                ['statusType' => 'intake'],
                ['statusType' => 'review'],
                ['statusType' => 'intake'],
            ]
        );

        self::assertCount(2, $transitions);
        self::assertFalse($transitions[0]['isRework']);
        // review -> intake revisits the status the case started in.
        self::assertTrue($transitions[1]['isRework']);

    }//end testRevisitingAnEarlierStatusIsFlaggedAsRework()

    /**
     * A record with a missing or empty statusType cannot form a transition.
     *
     * @return void
     */
    public function testRecordsWithoutAStatusTypeAreSkipped(): void
    {
        $transitions = $this->builder->computeCaseTransitions(
            [
                ['statusType' => 'intake'],
                ['statusType' => ''],
                ['statusType' => 'review'],
            ]
        );

        // intake->'' and ''->review are both unusable.
        self::assertSame([], $transitions);

    }//end testRecordsWithoutAStatusTypeAreSkipped()

    /**
     * Identical from->to pairs across cases aggregate into one matrix row
     * carrying the summed count.
     *
     * @return void
     */
    public function testIdenticalTransitionsAggregateAcrossCases(): void
    {
        $result = $this->builder->computeTransitionMatrix(
            [
                'case-1' => [
                    ['statusType' => 'intake'],
                    ['statusType' => 'review'],
                ],
                'case-2' => [
                    ['statusType' => 'intake'],
                    ['statusType' => 'review'],
                ],
            ],
            []
        );

        self::assertCount(1, $result['matrix']);
        self::assertSame(2, $result['matrix'][0]['count']);
        self::assertSame(2, $result['totalCount']);
        self::assertSame(0, $result['matrix'][0]['reworkCount']);
        self::assertSame(0.0, $result['reworkPercent']);

    }//end testIdenticalTransitionsAggregateAcrossCases()

    /**
     * The rework percentage is rework transitions over all transitions.
     *
     * @return void
     */
    public function testReworkPercentIsShareOfAllTransitions(): void
    {
        $result = $this->builder->computeTransitionMatrix(
            [
                'case-1' => [
                    ['statusType' => 'intake'],
                    ['statusType' => 'review'],
                    ['statusType' => 'intake'],
                    ['statusType' => 'review'],
                ],
            ],
            []
        );

        // intake->review (new), review->intake (rework), intake->review (rework).
        self::assertSame(3, $result['totalCount']);
        self::assertEqualsWithDelta(66.7, $result['reworkPercent'], 0.05);

    }//end testReworkPercentIsShareOfAllTransitions()

    /**
     * An empty history divides by nothing and reports a zero rate rather
     * than throwing.
     *
     * @return void
     */
    public function testEmptyInputReportsZeroTotalsAndNoDivisionByZero(): void
    {
        $result = $this->builder->computeTransitionMatrix([], []);

        self::assertSame([], $result['matrix']);
        self::assertSame(0, $result['totalCount']);
        self::assertSame(0.0, $result['reworkPercent']);

    }//end testEmptyInputReportsZeroTotalsAndNoDivisionByZero()

    /**
     * Matrix rows are ordered by descending count, so the busiest path is
     * first.
     *
     * @return void
     */
    public function testMatrixIsSortedByDescendingCount(): void
    {
        $result = $this->builder->computeTransitionMatrix(
            [
                'case-1' => [
                    ['statusType' => 'a'],
                    ['statusType' => 'b'],
                ],
                'case-2' => [
                    ['statusType' => 'c'],
                    ['statusType' => 'd'],
                ],
                'case-3' => [
                    ['statusType' => 'c'],
                    ['statusType' => 'd'],
                ],
            ],
            []
        );

        self::assertCount(2, $result['matrix']);
        self::assertSame('c', $result['matrix'][0]['from']);
        self::assertSame(2, $result['matrix'][0]['count']);
        self::assertSame(1, $result['matrix'][1]['count']);

    }//end testMatrixIsSortedByDescendingCount()

    /**
     * A status id resolves to its `name`, falling back to `title`, and to the
     * raw id when the index has neither or does not know the id.
     *
     * @return void
     */
    public function testStatusLabelsResolveThroughTheStatusTypeIndex(): void
    {
        $result = $this->builder->computeTransitionMatrix(
            [
                'case-1' => [
                    ['statusType' => 'has-name'],
                    ['statusType' => 'has-title'],
                ],
                'case-2' => [
                    ['statusType' => 'blank-label'],
                    ['statusType' => 'unknown-id'],
                ],
            ],
            [
                'has-name'    => ['name' => 'Intake'],
                'has-title'   => ['title' => 'Under Review'],
                'blank-label' => ['name' => ''],
            ]
        );

        $labels = [];
        foreach ($result['matrix'] as $row) {
            $labels[$row['from']] = $row['fromName'];
            $labels[$row['to']]   = $row['toName'];
        }

        self::assertSame('Intake', $labels['has-name']);
        self::assertSame('Under Review', $labels['has-title']);
        // An empty name falls through to the raw id, as does an id the index
        // has never heard of.
        self::assertSame('blank-label', $labels['blank-label']);
        self::assertSame('unknown-id', $labels['unknown-id']);

    }//end testStatusLabelsResolveThroughTheStatusTypeIndex()

}//end class
