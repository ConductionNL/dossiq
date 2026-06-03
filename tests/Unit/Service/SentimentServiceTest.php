<?php

/**
 * SentimentService Unit Tests
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
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T09
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Service;

use OCA\Procest\Service\SentimentService;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for SentimentService.
 *
 * @covers \OCA\Procest\Service\SentimentService
 */
class SentimentServiceTest extends TestCase
{
    /**
     * @var SentimentService
     */
    private SentimentService $service;

    /**
     * @var array<int, string>
     */
    private array $triggers = ['ongelooflijk', 'klacht', 'wethouder', 'advocaat', 'media', 'rechtszaak'];

    /**
     * Set up fixtures.
     *
     * @return void
     */
    protected function setUp(): void
    {
        $this->service = new SentimentService();
    }//end setUp()

    /**
     * Serious trigger words drive escalation, a boos label and the rood level.
     *
     * @return void
     */
    public function testSeriousTriggersEscalateToRood(): void
    {
        $result = $this->service->analyzeSentiment(
            'Ik dien een klacht in en schakel mijn advocaat in.',
            $this->triggers,
        );

        $this->assertContains('klacht', $result['triggers']);
        $this->assertContains('advocaat', $result['triggers']);
        $this->assertTrue($result['escalatieAanbevolen']);
        $this->assertSame('rood', $result['escalatieLevel']);
        $this->assertSame('boos', $result['label']);
        $this->assertLessThanOrEqual(-0.5, $result['score']);
    }//end testSeriousTriggersEscalateToRood()

    /**
     * Positive text yields a positive label, no escalation and the geen level.
     *
     * @return void
     */
    public function testPositiveTextDoesNotEscalate(): void
    {
        $result = $this->service->analyzeSentiment(
            'Heel erg bedankt, prima geregeld, ik ben zeer tevreden!',
            $this->triggers,
        );

        $this->assertFalse($result['escalatieAanbevolen']);
        $this->assertSame('geen', $result['escalatieLevel']);
        $this->assertSame('positief', $result['label']);
        $this->assertGreaterThan(0.0, $result['score']);
    }//end testPositiveTextDoesNotEscalate()

    /**
     * Neutral text yields a neutral label and no trigger words.
     *
     * @return void
     */
    public function testNeutralText(): void
    {
        $result = $this->service->analyzeSentiment(
            'Kunt u mij de openingstijden van het gemeentehuis doorgeven?',
            $this->triggers,
        );

        $this->assertSame([], $result['triggers']);
        $this->assertFalse($result['escalatieAanbevolen']);
        $this->assertSame('neutraal', $result['label']);
    }//end testNeutralText()

    /**
     * Word-boundary matching prevents false positives on substrings.
     *
     * @return void
     */
    public function testWordBoundaryAvoidsSubstringFalsePositive(): void
    {
        // "klachtenfunctionaris" must NOT match the trigger "klacht".
        $result = $this->service->analyzeSentiment(
            'U kunt terecht bij de klachtenfunctionaris voor meer informatie.',
            ['klacht'],
        );

        $this->assertNotContains('klacht', $result['triggers']);
    }//end testWordBoundaryAvoidsSubstringFalsePositive()

    /**
     * shouldEscalate returns true on a very low score even without triggers.
     *
     * @return void
     */
    public function testShouldEscalateOnLowScore(): void
    {
        $this->assertTrue($this->service->shouldEscalate(-0.6, []));
        $this->assertFalse($this->service->shouldEscalate(-0.2, []));
    }//end testShouldEscalateOnLowScore()

    /**
     * getEscalationLevel maps score bands without triggers.
     *
     * @return void
     */
    public function testEscalationLevelBands(): void
    {
        $this->assertSame('geen', $this->service->getEscalationLevel(0.5, []));
        $this->assertSame('geel', $this->service->getEscalationLevel(-0.2, []));
        $this->assertSame('oranje', $this->service->getEscalationLevel(-0.4, []));
        $this->assertSame('rood', $this->service->getEscalationLevel(-0.8, []));
    }//end testEscalationLevelBands()

    /**
     * The snippet is centred on the first detected trigger word.
     *
     * @return void
     */
    public function testSnippetCentredOnTrigger(): void
    {
        $result = $this->service->analyzeSentiment(
            'Lange inleiding zonder lading hier, en dan volgt het woord rechtszaak verderop.',
            ['rechtszaak'],
        );

        $this->assertStringContainsString('rechtszaak', $result['snippet']);
    }//end testSnippetCentredOnTrigger()
}//end class
