<?php

/**
 * SentryEvaluator pure sentry-firing tests.
 *
 * Covers AND-within-a-sentry (onPart+ifPart both required), OR-across-a-
 * criteria-array, both onPart shapes (planItem transition / caseFileItem
 * event), and the ifPart operator vocabulary.
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
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-003
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service\Cmmn;

use OCA\Procest\Service\Cmmn\SentryEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Service\Cmmn\SentryEvaluator
 */
final class SentryEvaluatorTest extends TestCase
{

    /**
     * A sentry with only an onPart (planItem transition) fires once that
     * plan item reaches the target state, and not before.
     *
     * @return void
     */
    public function testOnPartPlanItemTransitionFires(): void
    {
        $sentry  = ['onPart' => ['planItem' => 'taskA', 'standardEvent' => 'complete']];
        $context = ['planItemStates' => ['taskA' => 'active'], 'caseFile' => [], 'touchedKeys' => [], 'changedKeys' => []];
        self::assertFalse(SentryEvaluator::fires(sentry: $sentry, context: $context));

        $context['planItemStates']['taskA'] = 'completed';
        self::assertTrue(SentryEvaluator::fires(sentry: $sentry, context: $context));
    }//end testOnPartPlanItemTransitionFires()

    /**
     * A sentry combining onPart (caseFileEvent) and ifPart requires BOTH.
     *
     * @return void
     */
    public function testMultiPartSentryRequiresBothOnPartAndIfPart(): void
    {
        $sentry = [
            'onPart' => ['caseFileItem' => 'urgent', 'caseFileEvent' => 'set'],
            'ifPart'  => ['field' => 'urgent', 'operator' => 'eq', 'value' => true],
        ];

        // onPart fires (touched) but ifPart fails (value false).
        $context = ['planItemStates' => [], 'caseFile' => ['urgent' => false], 'touchedKeys' => ['urgent'], 'changedKeys' => ['urgent']];
        self::assertFalse(SentryEvaluator::fires(sentry: $sentry, context: $context));

        // Both satisfied.
        $context['caseFile']['urgent'] = true;
        self::assertTrue(SentryEvaluator::fires(sentry: $sentry, context: $context));

        // ifPart satisfied but onPart not touched this call.
        $context['touchedKeys'] = [];
        self::assertFalse(SentryEvaluator::fires(sentry: $sentry, context: $context));
    }//end testMultiPartSentryRequiresBothOnPartAndIfPart()

    /**
     * `anyFires()` ORs across an array of sentries.
     *
     * @return void
     */
    public function testMultipleSentriesAreOred(): void
    {
        $sentries = [
            ['onPart' => ['planItem' => 'a', 'standardEvent' => 'complete']],
            ['onPart' => ['planItem' => 'b', 'standardEvent' => 'complete']],
        ];

        $context = ['planItemStates' => ['a' => 'active', 'b' => 'active'], 'caseFile' => [], 'touchedKeys' => [], 'changedKeys' => []];
        self::assertFalse(SentryEvaluator::anyFires(sentries: $sentries, context: $context));

        $context['planItemStates']['b'] = 'completed';
        self::assertTrue(SentryEvaluator::anyFires(sentries: $sentries, context: $context));
    }//end testMultipleSentriesAreOred()

    /**
     * An empty sentries array never fires via anyFires() — callers decide
     * "empty = trivially satisfied" themselves.
     *
     * @return void
     */
    public function testEmptySentryArrayNeverFires(): void
    {
        $context = ['planItemStates' => [], 'caseFile' => [], 'touchedKeys' => [], 'changedKeys' => []];
        self::assertFalse(SentryEvaluator::anyFires(sentries: [], context: $context));
    }//end testEmptySentryArrayNeverFires()

    /**
     * `caseFileEvent: changed` only fires when the value actually differed,
     * unlike `set` which fires on any touch.
     *
     * @return void
     */
    public function testCaseFileChangedVersusSet(): void
    {
        $setSentry     = ['onPart' => ['caseFileItem' => 'note', 'caseFileEvent' => 'set']];
        $changedSentry = ['onPart' => ['caseFileItem' => 'note', 'caseFileEvent' => 'changed']];

        $context = ['planItemStates' => [], 'caseFile' => ['note' => 'x'], 'touchedKeys' => ['note'], 'changedKeys' => []];
        self::assertTrue(SentryEvaluator::fires(sentry: $setSentry, context: $context));
        self::assertFalse(SentryEvaluator::fires(sentry: $changedSentry, context: $context));

        $context['changedKeys'] = ['note'];
        self::assertTrue(SentryEvaluator::fires(sentry: $changedSentry, context: $context));
    }//end testCaseFileChangedVersusSet()

    /**
     * The ifPart operator vocabulary.
     *
     * @return void
     */
    public function testIfPartOperators(): void
    {
        $context = static fn (mixed $value): array => ['planItemStates' => [], 'caseFile' => ['f' => $value], 'touchedKeys' => [], 'changedKeys' => []];

        self::assertTrue(SentryEvaluator::fires(['ifPart' => ['field' => 'f', 'operator' => 'eq', 'value' => 5]], $context(5)));
        self::assertTrue(SentryEvaluator::fires(['ifPart' => ['field' => 'f', 'operator' => 'neq', 'value' => 5]], $context(6)));
        self::assertTrue(SentryEvaluator::fires(['ifPart' => ['field' => 'f', 'operator' => 'gt', 'value' => 5]], $context(6)));
        self::assertTrue(SentryEvaluator::fires(['ifPart' => ['field' => 'f', 'operator' => 'gte', 'value' => 5]], $context(5)));
        self::assertTrue(SentryEvaluator::fires(['ifPart' => ['field' => 'f', 'operator' => 'lt', 'value' => 5]], $context(4)));
        self::assertTrue(SentryEvaluator::fires(['ifPart' => ['field' => 'f', 'operator' => 'lte', 'value' => 5]], $context(5)));
        self::assertTrue(SentryEvaluator::fires(['ifPart' => ['field' => 'f', 'operator' => 'in', 'value' => [1, 2, 3]]], $context(2)));
        self::assertTrue(SentryEvaluator::fires(['ifPart' => ['field' => 'f', 'operator' => 'notIn', 'value' => [1, 2, 3]]], $context(9)));
        self::assertTrue(SentryEvaluator::fires(['ifPart' => ['field' => 'f', 'operator' => 'truthy']], $context(true)));
        self::assertTrue(SentryEvaluator::fires(['ifPart' => ['field' => 'f', 'operator' => 'falsy']], $context(false)));
        self::assertFalse(SentryEvaluator::fires(['ifPart' => ['field' => 'f', 'operator' => 'gt', 'value' => 5]], $context(4)));
    }//end testIfPartOperators()

    /**
     * A malformed ifPart (no field) never fires — fail closed.
     *
     * @return void
     */
    public function testMalformedIfPartFailsClosed(): void
    {
        $context = ['planItemStates' => [], 'caseFile' => [], 'touchedKeys' => [], 'changedKeys' => []];
        self::assertFalse(SentryEvaluator::fires(['ifPart' => ['operator' => 'eq', 'value' => 1]], $context));
    }//end testMalformedIfPartFailsClosed()

    /**
     * A sentry with neither onPart nor ifPart is vacuously true (present
     * but empty — the engine's model-load validation is expected to reject
     * a genuinely empty sentry at a higher layer; this unit only asserts the
     * pure evaluation contract).
     *
     * @return void
     */
    public function testSentryWithNeitherPartIsVacuouslyTrue(): void
    {
        $context = ['planItemStates' => [], 'caseFile' => [], 'touchedKeys' => [], 'changedKeys' => []];
        self::assertTrue(SentryEvaluator::fires([], $context));
    }//end testSentryWithNeitherPartIsVacuouslyTrue()
}//end class
