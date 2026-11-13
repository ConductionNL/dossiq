<?php

/**
 * Unit tests for the ZGW document envelope a case note travels in.
 *
 * ZGW has no note resource, so this envelope IS the decision about what a note
 * is on the wire. These arms pin the three fields a receiving register needs
 * to be able to file it and show it, and the two refusals that happen before
 * anything leaves the building.
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
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Notes;

use InvalidArgumentException;
use OCA\Dossiq\Service\Notes\NoteEnvelope;
use PHPUnit\Framework\TestCase;

/**
 * Tests the note-to-document mapping.
 *
 * @covers \OCA\Dossiq\Service\Notes\NoteEnvelope
 */
class NoteEnvelopeTest extends TestCase {
	/**
	 * A note with an author, a moment, a body and a case becomes a document.
	 *
	 * @return void
	 */
	public function testANoteBecomesADocumentCarryingItsAuthorAndItsText(): void {
		$envelope = (new NoteEnvelope())->build(
			note: [
				'id' => 42,
				'message' => "Gebeld met de aanvrager\nHij stuurt de tekening na.",
				'actorId' => 'jdoe',
				'actorDisplayName' => 'J. Doe',
				'createdAt' => '2026-09-18T10:15:00+02:00',
				'visibility' => 'public',
			],
			caseId: 'case-1',
			informatieobjecttype: 'https://catalogi.example/iot/werknotitie',
			bronorganisatie: '002564440',
		);

		// The FIRST LINE is the title, because that is what a reader would call
		// the note; the whole body is the content, base64 as ZGW takes it.
		$this->assertSame('Gebeld met de aanvrager', $envelope['titel']);
		$this->assertSame(
			"Gebeld met de aanvrager\nHij stuurt de tekening na.",
			base64_decode($envelope['inhoud'])
		);
		$this->assertSame('J. Doe', $envelope['auteur']);
		$this->assertSame('2026-09-18', $envelope['creatiedatum']);
		$this->assertSame('case-1', $envelope['zaak']);
		$this->assertSame('https://catalogi.example/iot/werknotitie', $envelope['informatieobjecttype']);
		$this->assertSame('nld', $envelope['taal']);
		$this->assertSame('002564440', $envelope['bronorganisatie']);
	}//end testANoteBecomesADocumentCarryingItsAuthorAndItsText()

	/**
	 * A note whose writer has no display name still names somebody.
	 *
	 * `auteur` is required, and an empty one is how a note arrives at a
	 * neighbouring register with nobody's name on it.
	 *
	 * @return void
	 */
	public function testANoteWithoutADisplayNameFallsBackToTheActorId(): void {
		$envelope = (new NoteEnvelope())->build(
			note: ['id' => 1, 'message' => 'Kort', 'actorId' => 'jdoe'],
			caseId: 'case-1',
			informatieobjecttype: 'iot',
		);

		$this->assertSame('jdoe', $envelope['auteur']);
	}//end testANoteWithoutADisplayNameFallsBackToTheActorId()

	/**
	 * A long first line is cut rather than sent whole as a title.
	 *
	 * @return void
	 */
	public function testALongFirstLineIsCutForTheTitle(): void {
		$envelope = (new NoteEnvelope())->build(
			note: ['id' => 1, 'message' => str_repeat('a', 250)],
			caseId: 'case-1',
			informatieobjecttype: 'iot',
		);

		$this->assertSame(100, mb_strlen($envelope['titel']));
		// The whole note is still in the content: the title is a label, not a
		// truncation of the record.
		$this->assertSame(str_repeat('a', 250), base64_decode($envelope['inhoud']));
	}//end testALongFirstLineIsCutForTheTitle()

	/**
	 * An empty note is refused before the push rather than sent as a document
	 * with no content.
	 *
	 * @return void
	 */
	public function testAnEmptyNoteIsRefusedBeforeItIsSent(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A note with no text is not sent.');

		(new NoteEnvelope())->build(
			note: ['id' => 1, 'message' => "   \n  "],
			caseId: 'case-1',
			informatieobjecttype: 'iot',
		);
	}//end testAnEmptyNoteIsRefusedBeforeItIsSent()

	/**
	 * With no reserved informatieobjecttype the push is refused, and the
	 * refusal names the key to set.
	 *
	 * A guessed type carries a guessed retention term, and the term for a
	 * working note is not the term for a decision letter: guessing silently
	 * destroys notes or silently keeps them for years.
	 *
	 * @return void
	 */
	public function testWithNoReservedTypeNothingIsSentAndTheKeyIsNamed(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('note_informatieobjecttype');

		(new NoteEnvelope())->build(
			note: ['id' => 1, 'message' => 'Kort'],
			caseId: 'case-1',
			informatieobjecttype: '',
		);
	}//end testWithNoReservedTypeNothingIsSentAndTheKeyIsNamed()

	/**
	 * A note with no case is refused before the push, whatever its text.
	 *
	 * @return void
	 */
	public function testANoteWithNoCaseIsRefusedBeforeItIsSent(): void {
		$this->expectException(InvalidArgumentException::class);
		$this->expectExceptionMessage('A note with no case is not sent.');

		(new NoteEnvelope())->build(
			note: ['id' => 1, 'message' => 'Kort'],
			caseId: '   ',
			informatieobjecttype: 'iot',
		);
	}//end testANoteWithNoCaseIsRefusedBeforeItIsSent()

	/**
	 * A note whose first line is blank takes its whole text as the title,
	 * rather than an empty one. A form feed survives the trim but breaks the
	 * line, which is how such a note arrives.
	 *
	 * @return void
	 */
	public function testABlankFirstLineTakesTheWholeTextAsTheTitle(): void {
		$envelope = (new NoteEnvelope())->build(
			note: ['id' => 7, 'message' => "\fTweede regel"],
			caseId: 'case-1',
			informatieobjecttype: 'iot',
		);

		$this->assertSame("\fTweede regel", $envelope['titel']);
		$this->assertSame('dossiq-note-7', $envelope['identificatie']);
		$this->assertSame('text/plain', $envelope['formaat']);
	}//end testABlankFirstLineTakesTheWholeTextAsTheTitle()

	/**
	 * A note whose moment cannot be read is dated today, as is one with no
	 * moment: the field is required, so it is never sent empty.
	 *
	 * @return void
	 */
	public function testAnUnreadableOrMissingMomentIsDatedToday(): void {
		$before = (new \DateTimeImmutable())->format('Y-m-d');
		$unreadable = (new NoteEnvelope())->build(
			note: ['id' => 1, 'message' => 'Kort', 'createdAt' => 'not a date'],
			caseId: 'case-1',
			informatieobjecttype: 'iot',
		);
		$missing = (new NoteEnvelope())->build(
			note: ['id' => 1, 'message' => 'Kort'],
			caseId: 'case-1',
			informatieobjecttype: 'iot',
		);
		$after = (new \DateTimeImmutable())->format('Y-m-d');

		// Read around the call, so a run that crosses midnight still passes.
		$this->assertContains($unreadable['creatiedatum'], [$before, $after]);
		$this->assertContains($missing['creatiedatum'], [$before, $after]);
	}//end testAnUnreadableOrMissingMomentIsDatedToday()
}//end class
