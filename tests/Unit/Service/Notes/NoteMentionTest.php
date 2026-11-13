<?php

/**
 * Unit tests for the author and note of one mention.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Notes
 *
 * @author    Conduction <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Notes;

use OCA\Dossiq\Service\Notes\MentionedNote;
use OCA\Dossiq\Service\Notes\NoteMention;
use PHPUnit\Framework\TestCase;

/**
 * Tests the writer name a mention shows.
 *
 * @covers \OCA\Dossiq\Service\Notes\NoteMention
 * @uses   \OCA\Dossiq\Service\Notes\MentionedNote
 */
class NoteMentionTest extends TestCase {
	/**
	 * The writer is the display name, and the note is the one given.
	 *
	 * @return void
	 */
	public function testTheWriterIsTheDisplayName(): void {
		$note    = new MentionedNote(objectId: 'obj-1', register: 'zaken', schema: 'zaak', noteId: 'n-7');
		$mention = new NoteMention(actorUserId: 'jdoe', actorDisplayName: 'Jan Doe', note: $note);

		self::assertSame('Jan Doe', $mention->writer());
		self::assertSame('jdoe', $mention->actorUserId);
		self::assertSame($note, $mention->note);
	}//end testTheWriterIsTheDisplayName()

	/**
	 * Without a display name the writer is the user id.
	 *
	 * @return void
	 */
	public function testWithoutADisplayNameTheWriterIsTheUserId(): void {
		$mention = new NoteMention(
			actorUserId: 'jdoe',
			actorDisplayName: '',
			note: new MentionedNote(objectId: 'obj-1', register: 'zaken', schema: 'zaak', noteId: 'n-7')
		);

		self::assertSame('jdoe', $mention->writer());
	}//end testWithoutADisplayNameTheWriterIsTheUserId()

	/**
	 * A display name of spaces is a display name: the writer is not trimmed
	 * here, so a mention naming a blank author is visible in the notification
	 * rather than silently replaced by the uid.
	 *
	 * @return void
	 */
	public function testADisplayNameOfSpacesIsNotTreatedAsAbsent(): void {
		$mention = new NoteMention(
			actorUserId: 'jdoe',
			actorDisplayName: ' ',
			note: new MentionedNote(objectId: 'obj-1', register: 'zaken', schema: 'zaak', noteId: 'n-7')
		);

		self::assertSame(' ', $mention->writer());
	}//end testADisplayNameOfSpacesIsNotTreatedAsAbsent()

	/**
	 * Without either name the writer is empty, never a placeholder: a mention
	 * by nobody is a bug upstream, and inventing a name would hide it.
	 *
	 * @return void
	 */
	public function testWithoutEitherNameTheWriterIsEmpty(): void {
		$mention = new NoteMention(
			actorUserId: '',
			actorDisplayName: '',
			note: new MentionedNote(objectId: 'obj-1', register: 'zaken', schema: 'zaak', noteId: 'n-7')
		);

		self::assertSame('', $mention->writer());
	}//end testWithoutEitherNameTheWriterIsEmpty()

	/**
	 * The mention holds the note it was built with, unchanged and read-only.
	 *
	 * @return void
	 */
	public function testTheMentionIsReadOnly(): void {
		$mention = new NoteMention(
			actorUserId: 'jdoe',
			actorDisplayName: 'Jan Doe',
			note: new MentionedNote(objectId: 'obj-1', register: 'zaken', schema: 'zaak', noteId: 'n-7')
		);

		self::assertSame('obj-1', $mention->note->objectId);
		self::assertSame('zaken', $mention->note->register);
		self::assertSame('n-7', $mention->note->noteId);

		$this->expectException(\Error::class);

		/* @phpstan-ignore-next-line — the point of the test is that this is refused. */
		$mention->actorDisplayName = 'Iemand anders';
	}//end testTheMentionIsReadOnly()
}//end class
