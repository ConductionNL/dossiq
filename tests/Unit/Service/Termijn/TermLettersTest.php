<?php

/**
 * The extension letter tells the requester why, and until when.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Termijn
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-an-extension-reaches-the-requester-with-its-reason-req-wrn-005
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Termijn;

use OCA\Dossiq\Service\Termijn\TermLetters;
use OCA\Dossiq\Tests\Support\MatchesGoldenFiles;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Termijn\TermLetters
 * @covers \OCA\Dossiq\Service\Term\TermLetter
 * @uses \OCA\Dossiq\Service\Support\LetterDays
 * @uses \OCA\Dossiq\Service\Support\ReadableDay
 */
class TermLettersTest extends TestCase {
	use MatchesGoldenFiles;

	/**
	 * Woo art. 4.4 lid 2: the extension letter carries the reason and the new end date.
	 *
	 * @return void
	 */
	public function testTheExtensionLetterCarriesItsReason(): void {
		$letter = (new TermLetters())->render(
			type: 'extension',
			instance: ['case' => 'WOO-2026-7', 'endDateCurrent' => '2026-11-02'],
			context: ['reason' => 'Veel documenten van derden, zienswijzen nodig.', 'newEinddatum' => '2026-11-16'],
		);

		self::assertStringContainsString('De nieuwe deadline is 16 november 2026.', $letter['body']);
		self::assertStringContainsString('De reden: Veel documenten van derden, zienswijzen nodig.', $letter['body']);
		self::assertStringNotContainsString('nodig..', $letter['body']);
	}//end testTheExtensionLetterCarriesItsReason()

	/**
	 * Without a reason the letter says nothing about one rather than an empty line.
	 *
	 * @return void
	 */
	public function testAnExtensionWithoutAReasonPrintsNoReasonLine(): void {
		$letter = (new TermLetters())->render(type: 'extension', instance: ['case' => 'Z-1'], context: ['newEinddatum' => '2026-11-16']);

		self::assertStringNotContainsString('De reden', $letter['body']);
		self::assertStringContainsString('16 november 2026', $letter['body']);
	}//end testAnExtensionWithoutAReasonPrintsNoReasonLine()

	/**
	 * Every date a letter prints reads as the boards write it ("10 augustus 2026"), whatever the store holds;
	 * a placeholder stays a placeholder (letters-print-every-date-the-same-way).
	 *
	 * @return void
	 *
	 * @spec openspec/specs/burger-notifications/spec.md#requirement-every-date-in-a-letter-reads-the-same-way-req-lpd-001
	 */
	public function testEveryDateInALetterReadsTheSameWay(): void {
		$letters = new TermLetters();
		$receipt = $letters->render(
			type: 'ontvangstbevestiging',
			instance: ['case' => '2026-0099', 'endDateCurrent' => '2026-08-10'],
			context: ['receivedAt' => '2026-06-15T09:12:00+02:00', 'termStartsAt' => '2026-06-15T09:12:00+02:00', 'subject' => 'Subsidie buurtfeest'],
		);
		self::assertStringContainsString('ontvangen op 15 juni 2026 en de beslistermijn start op 15 juni 2026', $receipt['body']);
		self::assertStringContainsString('uiterlijk op 10 augustus 2026', $receipt['body']);

		$grace = $letters->render(type: 'ingebrekestelling-receipt', instance: ['case' => 'Z-1'], context: ['graceEnd' => '2026-09-01']);
		self::assertStringContainsString('eindigt op 1 september 2026', $grace['body']);

		$request = $letters->render(type: 'hersteltermijn-request', instance: ['case' => 'Z-1'], context: ['items' => ['Bouwtekening'], 'pauseDeadline' => '2026-10-20']);
		self::assertStringContainsString('20 oktober 2026', $request['body']);
		$reminder = $letters->render(type: 'hersteltermijn-reminder', instance: ['case' => 'Z-1'], context: ['pauseDeadline' => '2026-10-20']);
		self::assertStringContainsString('20 oktober 2026', $reminder['body']);
		$english = $letters->render(type: 'hersteltermijn-reminder', instance: ['case' => 'Z-1'], context: ['locale' => 'en', 'pauseDeadline' => '2026-10-20']);
		self::assertStringContainsString('20 October 2026', $english['body'], 'an English letter names the month in English');

		foreach ([$receipt, $grace, $request, $reminder] as $letter) {
			self::assertDoesNotMatchRegularExpression('/\b\d{4}-\d{2}-\d{2}\b/', $letter['body']);
		}

		$noTerm = $letters->render(type: 'ingebrekestelling-receipt', instance: ['case' => 'Z-1'], context: []);
		self::assertStringContainsString('eindigt op –.', $noTerm['body'], 'a placeholder stays a placeholder');
	}//end testEveryDateInALetterReadsTheSameWay()

	/**
	 * Every letter type in both languages over a full and an empty context,
	 * plus the case-number fallbacks and an unknown type, pinned whole.
	 *
	 * @return void
	 */
	public function testGoldenLetters(): void {
		$full = [
			'case' => ' 2026-0117 ',
			'subject' => ' a permit ',
			'contact' => ' the desk ',
			'receivedAt' => '2026-10-31T12:00:00+00:00',
			'termStartsAt' => '2026-11-02',
			'receivedOutsideWorkingHours' => true,
			'graceEnd' => '2026-12-01',
			'newEinddatum' => '2026-12-15',
			'reason' => ' more advice is needed. ',
			'items' => ['a map', '', 'a photo'],
			'pauseDeadline' => '2026-11-20',
			'chaseText' => ' please send it ',
			'destination' => ' the province ',
			'bedragCents' => 123456,
			'betalingsreferentie' => 'REF-9',
		];
		$types = ['ontvangstbevestiging', 'extension', 'ingebrekestelling-receipt', 'hersteltermijn-request', 'hersteltermijn-reminder', 'doorzending', 'niet-ontvankelijk', 'dwangsom-payment', 'unknown'];
		$instance = ['case' => 'term-link', 'endDateCurrent' => '2026-12-01'];
		$letters = new TermLetters();

		$world = [];
		foreach ($types as $type) {
			foreach (['nl', 'en'] as $locale) {
				$world[$type . ' ' . $locale . ' full'] = $letters->render(type: $type, instance: $instance, context: ($full + ['locale' => $locale]));
				$world[$type . ' ' . $locale . ' bare'] = $letters->render(type: $type, instance: [], context: ['locale' => $locale]);
			}
		}

		$world['no locale, term link only'] = $letters->render(type: 'ontvangstbevestiging', instance: ['case' => 'term-link'], context: ['endDate' => '2026-12-01']);
		$world['blank type'] = $letters->render(type: '', instance: $instance, context: $full);
		$world['wrong-case type'] = $letters->render(type: 'Ontvangstbevestiging', instance: $instance, context: $full);
		$world['withheld'] = $letters->render(type: 'ontvangstbevestiging', instance: $instance, context: ['contentWithheld' => true, 'locale' => 'en']);
		$world['withheld nl'] = $letters->render(type: 'ontvangstbevestiging', instance: $instance, context: ['contentWithheld' => true, 'locale' => 'nl']);
		$world['received in hours'] = $letters->render(type: 'ontvangstbevestiging', instance: $instance, context: ['receivedAt' => '2026-11-03', 'termStartsAt' => '2026-11-03', 'locale' => 'nl']);
		$world['received in hours en'] = $letters->render(type: 'ontvangstbevestiging', instance: $instance, context: ['receivedAt' => '2026-11-03', 'termStartsAt' => '2026-11-03', 'locale' => 'en']);

		$this->assertMatchesGolden('term-letters', $world);
	}//end testGoldenLetters()
}//end class
