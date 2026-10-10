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

		self::assertStringContainsString('2026-11-16', $letter['body']);
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
		self::assertStringContainsString('2026-11-16', $letter['body']);
	}//end testAnExtensionWithoutAReasonPrintsNoReasonLine()
}//end class
