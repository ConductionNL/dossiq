<?php

/**
 * What the Link to case dialog reads of a queued message (board DqKoppelAanZaak).
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-email-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Intake;

use OCA\Dossiq\Service\Intake\MessagePreview;
use PHPUnit\Framework\TestCase;

/**
 * Tests for MessagePreview.
 *
 * @covers \OCA\Dossiq\Service\Intake\MessagePreview
 */
class MessagePreviewTest extends TestCase {

	/**
	 * A multipart message: its sender's name, its text on one line and its attachment names.
	 *
	 * @return void
	 */
	public function testAMultipartMessageGivesItsSenderTextAndAttachments(): void {
		$original = implode(
			"\r\n",
			[
				'From: "Henk Bakker" <henk.bakker@example.org>',
				'Subject: Vraag over mijn kapvergunning 2026-0206',
				'Content-Type: multipart/mixed; boundary="b1"',
				'',
				'--b1',
				'Content-Type: text/plain; charset="utf-8"',
				'Content-Transfer-Encoding: quoted-printable',
				'',
				'Goedemorgen, drie weken geleden vroeg ik een kapvergunning aan voor de e=',
				'ik in mijn achtertuin.',
				'',
				'--b1',
				'Content-Type: image/jpeg',
				'Content-Disposition: attachment; filename="eik-achtertuin-1.jpg"',
				'',
				'AAAA',
				'--b1',
				'Content-Type: image/jpeg; name="eik-achtertuin-2.jpg"',
				'',
				'AAAA',
				'--b1--',
			]
		);

		$read = (new MessagePreview())->read(original: $original);

		self::assertSame(expected: 'Henk Bakker', actual: $read['senderName']);
		self::assertSame(
			expected: 'Goedemorgen, drie weken geleden vroeg ik een kapvergunning aan voor de eik in mijn achtertuin.',
			actual: $read['preview']
		);
		self::assertSame(expected: ['eik-achtertuin-1.jpg', 'eik-achtertuin-2.jpg'], actual: $read['attachments']);
	}//end testAMultipartMessageGivesItsSenderTextAndAttachments()

	/**
	 * A long text is cut with an ellipsis; a bare address gives no name; no original gives nothing.
	 *
	 * @return void
	 */
	public function testALongTextIsCutAndABareAddressHasNoName(): void {
		$read = (new MessagePreview())->read(original: "From: info@example.org\r\n\r\n" . str_repeat('woord ', 60));

		self::assertSame(expected: '', actual: $read['senderName']);
		self::assertStringEndsWith(suffix: '…', string: $read['preview']);
		self::assertSame(expected: ['senderName' => '', 'preview' => '', 'attachments' => []], actual: (new MessagePreview())->read(original: ''));
	}//end testALongTextIsCutAndABareAddressHasNoName()

	/**
	 * Every queue item gets the three fields.
	 *
	 * @return void
	 */
	public function testAnnotateAddsTheFieldsToEveryItem(): void {
		$items = (new MessagePreview())->annotate(items: [['id' => 'e1', 'original' => "From: Ann <a@example.org>\n\nHoi"], ['id' => 'e2']]);

		self::assertSame(expected: 'Ann', actual: $items[0]['senderName']);
		self::assertSame(expected: 'Hoi', actual: $items[0]['preview']);
		self::assertSame(expected: [], actual: $items[1]['attachments']);
	}//end testAnnotateAddsTheFieldsToEveryItem()
}//end class
