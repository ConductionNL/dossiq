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
}//end class
