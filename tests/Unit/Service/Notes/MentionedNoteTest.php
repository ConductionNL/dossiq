<?php

/**
 * Unit tests for the note a mention names.
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
use PHPUnit\Framework\TestCase;

/**
 * Tests the object type a mention notification names.
 *
 * @covers \OCA\Dossiq\Service\Notes\MentionedNote
 */
class MentionedNoteTest extends TestCase {
	/**
	 * The object type is the schema, and the coordinates are kept as given.
	 *
	 * @return void
	 */
	public function testTheObjectTypeIsTheSchema(): void {
		$note = new MentionedNote(objectId: 'obj-1', register: 'zaken', schema: 'zaak', noteId: 'n-7');

		self::assertSame('zaak', $note->objectType());
		self::assertSame('obj-1', $note->objectId);
		self::assertSame('zaken', $note->register);
		self::assertSame('n-7', $note->noteId);
	}//end testTheObjectTypeIsTheSchema()

	/**
	 * Without a schema the object type is `note`.
	 *
	 * @return void
	 */
	public function testWithoutASchemaTheObjectTypeIsNote(): void {
		$note = new MentionedNote(objectId: 'obj-1', register: 'zaken', schema: '', noteId: 'n-7');

		self::assertSame('note', $note->objectType());
	}//end testWithoutASchemaTheObjectTypeIsNote()

	/**
	 * A whitespace schema is not empty and is used as given.
	 *
	 * @return void
	 */
	public function testAWhitespaceSchemaIsKept(): void {
		$note = new MentionedNote(objectId: 'obj-1', register: 'zaken', schema: ' ', noteId: 'n-7');

		self::assertSame(' ', $note->objectType());
	}//end testAWhitespaceSchemaIsKept()
}//end class
