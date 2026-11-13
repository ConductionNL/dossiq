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

	/**
	 * The identificatie is the note's own id, prefixed, so a receiver can
	 * recognise a resend of the same note; a note with no id still gets a
	 * well-formed one rather than no identification at all.
	 *
	 * @return void
	 */
	public function testTheIdentificationNamesTheNote(): void {
		$envelope = new NoteEnvelope();
		$note = ['id' => 42, 'message' => 'Gebeld met de aanvrager'];

		$built = $envelope->build(note: $note, caseId: 'case-1', informatieobjecttype: 'iot-1');
		$this->assertSame(expected: 'dossiq-note-42', actual: $built['identificatie']);

		$built = $envelope->build(note: ['message' => 'Zonder id'], caseId: 'case-1', informatieobjecttype: 'iot-1');
		$this->assertSame(expected: 'dossiq-note-', actual: $built['identificatie']);
	}//end testTheIdentificationNamesTheNote()

	/**
	 * The source organisation is passed through as given, and absent by
	 * default: the RSIN is the instance's configuration, not this class's, so
	 * an unset one travels as empty rather than as a guess.
	 *
	 * @return void
	 */
	public function testTheSourceOrganisationIsPassedThroughOrEmpty(): void {
		$envelope = new NoteEnvelope();
		$note = ['message' => 'Gebeld'];

		$this->assertSame(
			expected: '002564440',
			actual: $envelope->build(note: $note, caseId: 'case-1', informatieobjecttype: 'iot-1', bronorganisatie: '002564440')['bronorganisatie']
		);
		$this->assertSame(
			expected: '',
			actual: $envelope->build(note: $note, caseId: 'case-1', informatieobjecttype: 'iot-1')['bronorganisatie']
		);
	}//end testTheSourceOrganisationIsPassedThroughOrEmpty()

	/**
	 * The text travels base64-encoded and unaltered: the body is not trimmed
	 * per line, re-wrapped or stripped, so what the handler wrote is what the
	 * receiver files.
	 *
	 * @return void
	 */
	public function testTheTextTravelsEncodedAndUnaltered(): void {
		$body = "Eerste regel\n\n  Tweede regel met <b>opmaak</b> & een \"citaat\"\nDerde";

		$built = (new NoteEnvelope())->build(
			note: ['message' => '  ' . $body . '  '],
			caseId: 'case-1',
			informatieobjecttype: 'iot-1'
		);

		$this->assertSame(expected: $body, actual: base64_decode($built['inhoud'], true));
		$this->assertSame(expected: 'Eerste regel', actual: $built['titel'], message: 'the title is the first line only');
		$this->assertSame(expected: 'text/plain', actual: $built['formaat']);
		$this->assertSame(expected: NoteEnvelope::LANGUAGE, actual: $built['taal']);
	}//end testTheTextTravelsEncodedAndUnaltered()

	/**
	 * A title of exactly the limit is kept whole; one character more is cut
	 * with an ellipsis and still fits the limit, so a receiver with a 100-char
	 * column never refuses the document for its title.
	 *
	 * @return void
	 */
	public function testATitleOfExactlyTheLimitIsKeptWhole(): void {
		$envelope = new NoteEnvelope();

		$exact = str_repeat('a', 100);
		$built = $envelope->build(note: ['message' => $exact], caseId: 'case-1', informatieobjecttype: 'iot-1');
		$this->assertSame(expected: $exact, actual: $built['titel']);
		$this->assertSame(expected: 100, actual: mb_strlen($built['titel']));

		$built = $envelope->build(note: ['message' => str_repeat('a', 101)], caseId: 'case-1', informatieobjecttype: 'iot-1');
		$this->assertSame(expected: str_repeat('a', 99) . "\u{2026}", actual: $built['titel']);
		$this->assertSame(expected: 100, actual: mb_strlen($built['titel']));
	}//end testATitleOfExactlyTheLimitIsKeptWhole()

	/**
	 * The title is counted in characters, not bytes: a multi-byte first line
	 * is cut on a character boundary, never halfway through one.
	 *
	 * @return void
	 */
	public function testAMultiByteTitleIsCutOnACharacterBoundary(): void {
		$built = (new NoteEnvelope())->build(
			note: ['message' => str_repeat('é', 120)],
			caseId: 'case-1',
			informatieobjecttype: 'iot-1'
		);

		$this->assertSame(expected: str_repeat('é', 99) . "\u{2026}", actual: $built['titel']);
		$this->assertSame(expected: 100, actual: mb_strlen($built['titel']));
	}//end testAMultiByteTitleIsCutOnACharacterBoundary()

	/**
	 * The informatieobjecttype travels trimmed, and the case as given: the
	 * case is the caller's own reference and is not reshaped here.
	 *
	 * @return void
	 */
	public function testTheReservedTypeTravelsTrimmed(): void {
		$built = (new NoteEnvelope())->build(
			note: ['message' => 'Gebeld'],
			caseId: 'case-1',
			informatieobjecttype: "  https://catalogi.example/iot/werknotitie\n"
		);

		$this->assertSame(expected: 'https://catalogi.example/iot/werknotitie', actual: $built['informatieobjecttype']);
		$this->assertSame(expected: 'case-1', actual: $built['zaak']);
	}//end testTheReservedTypeTravelsTrimmed()
}//end class
