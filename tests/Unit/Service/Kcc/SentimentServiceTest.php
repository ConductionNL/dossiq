<?php

/**
 * SentimentService Unit Tests
 *
 * Covers the deterministic Dutch sentiment analyser used by the KCC werkplek.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Kcc
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
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T09
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Kcc;

use OCA\Dossiq\Service\Kcc\SentimentService;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Kcc\SentimentService
 */
class SentimentServiceTest extends TestCase {
	private SentimentService $service;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->service = new SentimentService();
	}//end setUp()

	/**
	 * @return void
	 */
	public function testNeutralTextScoresAroundZero(): void {
		$result = $this->service->analyzeSentiment(
			text: 'Goedendag, ik bel namens mijn buurvrouw over een afvalcontainer.'
		);

		self::assertEqualsWithDelta(0.0, $result['score'], 0.4);
		self::assertContains($result['label'], ['neutraal', 'positief']);
		self::assertFalse($result['escalationRecommended']);
	}//end testNeutralTextScoresAroundZero()

	/**
	 * @return void
	 */
	public function testPositiveTextScoresAboveZero(): void {
		$result = $this->service->analyzeSentiment(
			text: 'Heel erg bedankt, dit is fantastisch geregeld, top!'
		);

		self::assertGreaterThan(0.3, $result['score']);
		self::assertSame('positief', $result['label']);
		self::assertSame('geen', $result['escalationLevel']);
	}//end testPositiveTextScoresAboveZero()

	/**
	 * @return void
	 */
	public function testAngryTextScoresStronglyNegative(): void {
		$result = $this->service->analyzeSentiment(
			text: 'Ik ben woedend en boos! Dit is verschrikkelijk en schandalig.'
		);

		self::assertLessThan(-0.5, $result['score']);
		self::assertSame('boos', $result['label']);
		self::assertTrue($result['escalationRecommended']);
	}//end testAngryTextScoresStronglyNegative()

	/**
	 * @return void
	 */
	public function testSeriousTriggerEscalatesImmediately(): void {
		$result = $this->service->analyzeSentiment(
			text: 'Als jullie dit niet oplossen ga ik naar de advocaat en de krant.'
		);

		self::assertContains('advocaat', $result['triggers']);
		self::assertContains('krant', $result['triggers']);
		self::assertTrue($result['escalationRecommended']);
		self::assertSame('rood', $result['escalationLevel']);
	}//end testSeriousTriggerEscalatesImmediately()

	/**
	 * @return void
	 */
	public function testKlachtTriggerIsDetectedButRedOnlyIfNegativeEnough(): void {
		$result = $this->service->analyzeSentiment(
			text: 'Ik wil een klacht indienen want jullie zijn boos en slecht bezig.'
		);

		self::assertContains('klacht', $result['triggers']);
		self::assertTrue($result['escalationRecommended']);
		self::assertContains($result['escalationLevel'], ['oranje', 'rood']);
	}//end testKlachtTriggerIsDetectedButRedOnlyIfNegativeEnough()

	/**
	 * @return void
	 */
	public function testCustomTriggerListOverridesDefault(): void {
		$result = $this->service->analyzeSentiment(
			text: 'Dit gaat zo niet langer, ik bel de gemeenteraad in.',
			triggerWords: ['gemeenteraad']
		);

		self::assertContains('gemeenteraad', $result['triggers']);

		// Default trigger 'wethouder' isn't in the override list — must NOT
		// appear despite being similar in domain.
		$resultNoTriggers = $this->service->analyzeSentiment(
			text: 'Goedendag, dank u wel.',
			triggerWords: ['gemeenteraad']
		);
		self::assertSame([], $resultNoTriggers['triggers']);
	}//end testCustomTriggerListOverridesDefault()

	/**
	 * @return void
	 */
	public function testTriggerDetectionUsesWordBoundary(): void {
		// "krantjegoed" must NOT match "krant".
		$result = $this->service->analyzeSentiment(
			text: 'Ik heb een krantje gekocht dat goed leesbaar is.'
		);

		// "krantje" should not match the "krant" trigger as a substring.
		self::assertNotContains('krant', $result['triggers']);
	}//end testTriggerDetectionUsesWordBoundary()

	/**
	 * @return void
	 */
	public function testShouldEscalateReturnsTrueOnSeriousTrigger(): void {
		self::assertTrue(
			$this->service->shouldEscalate(score: 0.0, triggers: ['advocaat'])
		);
		self::assertTrue(
			$this->service->shouldEscalate(score: -0.7, triggers: [])
		);
		self::assertFalse(
			$this->service->shouldEscalate(score: -0.1, triggers: ['klacht'])
		);
	}//end testShouldEscalateReturnsTrueOnSeriousTrigger()

	/**
	 * @return void
	 */
	public function testEscalationLevelLadder(): void {
		self::assertSame('geen', $this->service->getEscalationLevel(score: 0.5, triggers: []));
		self::assertSame('geel', $this->service->getEscalationLevel(score: -0.2, triggers: []));
		self::assertSame('oranje', $this->service->getEscalationLevel(score: -0.5, triggers: []));
		self::assertSame('rood', $this->service->getEscalationLevel(score: -0.8, triggers: []));
		self::assertSame('rood', $this->service->getEscalationLevel(score: 0.5, triggers: ['rechtbank']));
	}//end testEscalationLevelLadder()
}//end class
