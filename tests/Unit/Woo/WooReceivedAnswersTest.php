<?php

/**
 * opencatalogi's Woo answer keys become the request a Woo case keeps.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Test
 * @package   OCA\Dossiq\Tests\Unit\Woo
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-dossiq-receives-a-woo-request-in-opencatalogis-shape-req-wto-001
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Woo;

use OCA\Dossiq\Woo\WooReceivedAnswers;
use OCA\Dossiq\Woo\WooRequestRefused;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Woo\WooReceivedAnswers
 * @covers \OCA\Dossiq\Woo\WooRequestRefused
 */
class WooReceivedAnswersTest extends TestCase {

	/**
	 * Every answer key lands on its request field.
	 *
	 * @return void
	 */
	public function testEveryAnswerKeyLandsOnItsField(): void {
		$request = (new WooReceivedAnswers())->toRequest(
			answers: [
				'requestedInformation' => '  Adviezen   Stationsweg ',
				'requesterName' => 'J. de Vries',
				'requesterEmail' => 'j@example.nl',
				'requesterPhone' => '0612345678',
				'requesterAddress' => 'Stationsweg 1',
				'originReference' => 'src-uuid',
				'subjectRef' => ['not', 'text'],
			],
			origin: 'opencatalogi'
		);

		self::assertSame(
			[
				'subjectRef' => '',
				'onderwerp' => 'Adviezen Stationsweg',
				'omschrijving' => 'Adviezen   Stationsweg',
				'origin' => 'opencatalogi',
				'originReference' => 'src-uuid',
				'verzoekerNaam' => 'J. de Vries',
				'verzoekerEmail' => 'j@example.nl',
				'verzoekerTelefoon' => '0612345678',
				'verzoekerAdres' => 'Stationsweg 1',
			],
			$request
		);
	}//end testEveryAnswerKeyLandsOnItsField()

	/**
	 * A subject is cut on a word boundary, and a single long word is cut hard.
	 *
	 * @return void
	 */
	public function testALongRequestIsCutOnAWordBoundary(): void {
		$answers = new WooReceivedAnswers();
		$words = $answers->toRequest(answers: ['requestedInformation' => str_repeat('abcdefghi ', 20)], origin: 'portal-form');
		self::assertSame(rtrim(str_repeat('abcdefghi ', 12)), $words['onderwerp']);

		$word = $answers->toRequest(answers: ['requestedInformation' => str_repeat('x', 200)], origin: 'portal-form');
		self::assertSame(str_repeat('x', 120), $word['onderwerp']);
	}//end testALongRequestIsCutOnAWordBoundary()

	/**
	 * Each opencatalogi channel maps, none falls back on the origin, and an unknown one is refused.
	 *
	 * @return void
	 */
	public function testChannelsMapAndAnUnknownOneIsRefused(): void {
		$answers = new WooReceivedAnswers();
		foreach (['web' => 'website', 'email' => 'email', 'post' => 'post', 'counter' => 'balie', 'phone' => 'phone'] as $from => $to) {
			self::assertSame($to, $answers->intakeChannel(answers: ['channel' => $from], origin: 'portal-form'));
		}

		self::assertSame('website', $answers->intakeChannel(answers: [], origin: 'portal-form'));
		self::assertSame('other', $answers->intakeChannel(answers: [], origin: 'opencatalogi'));

		$this->expectException(WooRequestRefused::class);
		$answers->intakeChannel(answers: ['channel' => 'fax'], origin: 'portal-form');
	}//end testChannelsMapAndAnUnknownOneIsRefused()

	/**
	 * Another origin, or nothing asked, is refused.
	 *
	 * @return void
	 */
	public function testAnotherOriginOrNothingAskedIsRefused(): void {
		$answers = new WooReceivedAnswers();
		foreach ([[['requestedInformation' => 'x'], 'portal'], [['requestedInformation' => ''], 'portal-form']] as [$given, $origin]) {
			try {
				$answers->toRequest(answers: $given, origin: $origin);
				self::fail('Accepted ' . json_encode($given) . ' from ' . $origin);
			} catch (WooRequestRefused $e) {
				self::assertSame(WooRequestRefused::INVALID, $e->getReason());
			}
		}
	}//end testAnotherOriginOrNothingAskedIsRefused()
}//end class
