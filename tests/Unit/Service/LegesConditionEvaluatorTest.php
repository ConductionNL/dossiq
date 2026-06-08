<?php

/**
 * LegesConditionEvaluator Unit Tests
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-003
 * @spec openspec/changes/leges-heffingen/specs.md#req-leges-004
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\LegesConditionEvaluator;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for LegesConditionEvaluator.
 *
 * @covers \OCA\Procest\Service\LegesConditionEvaluator
 */
class LegesConditionEvaluatorTest extends TestCase
{

    /**
     * The evaluator under test.
     *
     * @var LegesConditionEvaluator
     */
    private LegesConditionEvaluator $evaluator;

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->evaluator = new LegesConditionEvaluator();
    }//end setUp()

    /**
     * An empty condition object matches everything.
     *
     * @return void
     */
    public function testEmptyConditionMatches(): void
    {
        $this->assertTrue($this->evaluator->evaluate(condities: [], caseData: [], context: []));
    }//end testEmptyConditionMatches()

    /**
     * Age minimum is satisfied when the resolved age meets the floor.
     *
     * @return void
     */
    public function testLeeftijdMinSatisfied(): void
    {
        $this->assertTrue(
            $this->evaluator->evaluate(
                condities: ['leeftijd' => ['min' => 65]],
                caseData: [],
                context: ['leeftijd' => 67]
            )
        );
    }//end testLeeftijdMinSatisfied()

    /**
     * Age minimum fails when the resolved age is below the floor.
     *
     * @return void
     */
    public function testLeeftijdMinNotSatisfied(): void
    {
        $this->assertFalse(
            $this->evaluator->evaluate(
                condities: ['leeftijd' => ['min' => 65]],
                caseData: [],
                context: ['leeftijd' => 40]
            )
        );
    }//end testLeeftijdMinNotSatisfied()

    /**
     * A missing datum never satisfies a range condition.
     *
     * @return void
     */
    public function testMissingLeeftijdFails(): void
    {
        $this->assertFalse(
            $this->evaluator->evaluate(
                condities: ['leeftijd' => ['min' => 65]],
                caseData: [],
                context: []
            )
        );
    }//end testMissingLeeftijdFails()

    /**
     * A boolean condition matches the case attribute.
     *
     * @return void
     */
    public function testSpoedAanvraagBoolean(): void
    {
        $this->assertTrue(
            $this->evaluator->evaluate(
                condities: ['spoedAanvraag' => true],
                caseData: ['spoedAanvraag' => true],
                context: []
            )
        );
        $this->assertFalse(
            $this->evaluator->evaluate(
                condities: ['spoedAanvraag' => true],
                caseData: ['spoedAanvraag' => false],
                context: []
            )
        );
    }//end testSpoedAanvraagBoolean()

    /**
     * A bouwsom range from case attributes is evaluated.
     *
     * @return void
     */
    public function testBouwsomRange(): void
    {
        $condities = ['bouwsom' => ['min' => 100000, 'max' => 500000]];
        $this->assertTrue($this->evaluator->evaluate(condities: $condities, caseData: ['bouwsom' => 250000], context: []));
        $this->assertFalse($this->evaluator->evaluate(condities: $condities, caseData: ['bouwsom' => 50000], context: []));
    }//end testBouwsomRange()

    /**
     * A repeat-application condition uses the resolved month gap.
     *
     * @return void
     */
    public function testHerhaalaanvraagWithinMonths(): void
    {
        $condities = ['herhaalaanvraag' => ['within_months' => 12]];
        $this->assertTrue($this->evaluator->evaluate(condities: $condities, caseData: [], context: ['herhaalaanvraag_maanden' => 6]));
        $this->assertFalse($this->evaluator->evaluate(condities: $condities, caseData: [], context: ['herhaalaanvraag_maanden' => 18]));
        $this->assertFalse($this->evaluator->evaluate(condities: $condities, caseData: [], context: []));
    }//end testHerhaalaanvraagWithinMonths()

    /**
     * All declared conditions must hold (logical AND).
     *
     * @return void
     */
    public function testMultipleConditionsAreAnded(): void
    {
        $condities = [
            'leeftijd'      => ['min' => 65],
            'spoedAanvraag' => false,
        ];
        $this->assertTrue(
            $this->evaluator->evaluate(condities: $condities, caseData: ['spoedAanvraag' => false], context: ['leeftijd' => 70])
        );
        $this->assertFalse(
            $this->evaluator->evaluate(condities: $condities, caseData: ['spoedAanvraag' => true], context: ['leeftijd' => 70])
        );
    }//end testMultipleConditionsAreAnded()

    /**
     * An unknown condition key never grants a match.
     *
     * @return void
     */
    public function testUnknownConditionKeyFails(): void
    {
        $this->assertFalse(
            $this->evaluator->evaluate(condities: ['onbekend' => true], caseData: [], context: [])
        );
    }//end testUnknownConditionKeyFails()
}//end class
