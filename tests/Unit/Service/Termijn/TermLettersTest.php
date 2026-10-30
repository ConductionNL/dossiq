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
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Termijn\TermLetters
 * @uses \OCA\Dossiq\Service\Support\LetterDays
 * @uses \OCA\Dossiq\Service\Support\ReadableDay
 */
class TermLettersTest extends TestCase {

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
}//end class
