<?php

/**
 * Edge cases of SentimentService: blank trigger words, the clamp at +1, and each label and escalation band.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Kcc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Kcc;

use OCA\Dossiq\Service\Kcc\SentimentService;
use PHPUnit\Framework\TestCase;

/**
 * Tests for SentimentService's bands and edges.
 *
 * @covers \OCA\Dossiq\Service\Kcc\SentimentService
 */
class SentimentServiceBandsTest extends TestCase {

	/**
	 * Blank configured trigger words are ignored; real ones match whole words only, once each.
	 *
	 * @return void
	 */
	public function testBlankTriggerWordsAreIgnored(): void {
		$result = (new SentimentService())->analyzeSentiment('Ik ga naar de rechter, echt de RECHTER.', ['', 'rechter', 'recht']);

		self::assertSame(['rechter'], $result['triggers']);
	}//end testBlankTriggerWordsAreIgnored()

	/**
	 * Many positive words are clamped to +1 and read as positive without escalation.
	 *
	 * @return void
	 */
	public function testAVeryPositiveTextIsClampedToOne(): void {
		$result = (new SentimentService())->analyzeSentiment('Geweldig, fantastic, super, top, bedankt!', []);

		self::assertSame(1.0, $result['score']);
		self::assertSame('positief', $result['label']);
		self::assertFalse($result['escalationRecommended']);
		self::assertSame('geen', $result['escalationLevel']);
	}//end testAVeryPositiveTextIsClampedToOne()

	/**
	 * A mildly negative text is labelled negative and escalated orange, not advised to escalate.
	 *
	 * @return void
	 */
	public function testAMildlyNegativeTextIsNegative(): void {
		$result = (new SentimentService())->analyzeSentiment('Ik ben teleurgesteld.', []);

		self::assertSame(-0.5, $result['score']);
		self::assertSame('negatief', $result['label']);
		self::assertTrue($result['escalationRecommended']);
		self::assertSame('oranje', $result['escalationLevel']);
	}//end testAMildlyNegativeTextIsNegative()

	/**
	 * The label bands meet at -0.6, -0.3 and +0.3.
	 *
	 * @return void
	 */
	public function testTheLabelBandBoundaries(): void {
		$service = new SentimentService();

		self::assertSame('boos', $service->analyzeSentiment('woedend', [])['label']);
		self::assertSame('negatief', $service->analyzeSentiment('slecht', [])['label']);
		self::assertSame('neutraal', $service->analyzeSentiment('verkeerd fijn', [])['label']);
		self::assertSame('positief', $service->analyzeSentiment('fijn', [])['label']);
	}//end testTheLabelBandBoundaries()

	/**
	 * A neutral score is yellow, a score of exactly -0.6 orange, below it red.
	 *
	 * @return void
	 */
	public function testTheEscalationBands(): void {
		$service = new SentimentService();

		self::assertSame('geel', $service->getEscalationLevel(0.0, []));
		self::assertSame('oranje', $service->getEscalationLevel(-0.6, []));
		self::assertSame('rood', $service->getEscalationLevel(-0.61, []));
		self::assertFalse($service->shouldEscalate(-0.49, ['onbekend']));
		self::assertTrue($service->shouldEscalate(-0.5, []));
	}//end testTheEscalationBands()
}//end class
