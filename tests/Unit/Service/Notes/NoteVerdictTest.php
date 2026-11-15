<?php

/**
 * NoteVerdict and NoteAttempt tests: the sentence a note push records on the
 * case, and the attempt it is recorded against.
 *
 * The sentence is what a handler reads back in the case history months later,
 * so it is pinned here rather than only through NotePush's golden file: that
 * file would absorb a change of wording without anyone deciding to make one.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Notes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Notes;

use OCA\Dossiq\Service\Notes\NoteAttempt;
use OCA\Dossiq\Service\Notes\NotePush;
use OCA\Dossiq\Service\Notes\NoteVerdict;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Dossiq\Service\Notes\NoteVerdict
 * @covers \OCA\Dossiq\Service\Notes\NoteAttempt
 */
class NoteVerdictTest extends TestCase {

	public function testAVerdictWithAReasonReadsAsOneSentence(): void {
		$verdict = new NoteVerdict(
			message: 'Note not sent to the neighbouring register',
			outcome: NotePush::OUTCOME_FAILED,
			reason: 'integriq could not take the note.'
		);

		$this->assertSame(
			expected: 'Note not sent to the neighbouring register: integriq could not take the note.',
			actual: $verdict->sentence()
		);
		$this->assertSame(expected: NotePush::OUTCOME_FAILED, actual: $verdict->outcome);
	}

	public function testAVerdictWithoutAReasonIsTheMessageAloneWithNoTrailingColon(): void {
		$verdict = new NoteVerdict(
			message: 'Note handed to integriq for the neighbouring register',
			outcome: NotePush::OUTCOME_SENT,
			reason: ''
		);

		$this->assertSame(expected: 'Note handed to integriq for the neighbouring register', actual: $verdict->sentence());
		$this->assertStringEndsNotWith(':', $verdict->sentence());
	}

	/**
	 * A reason that is only spaces is a reason: it is not trimmed away here,
	 * because the only caller trims before it builds the verdict and a second
	 * trim would hide a caller that forgot to.
	 *
	 * @return void
	 */
	public function testAReasonOfSpacesIsStillJoined(): void {
		$verdict = new NoteVerdict(message: 'Note kept here', outcome: NotePush::OUTCOME_NOT_SENT, reason: ' ');

		$this->assertSame(expected: 'Note kept here:  ', actual: $verdict->sentence());
	}

	/**
	 * Nothing in the verdict is mutable: a recorded outcome cannot be reworded
	 * between the record and the answer the caller gets.
	 *
	 * @return void
	 */
	public function testAVerdictIsReadOnly(): void {
		$verdict = new NoteVerdict(message: 'Note kept here', outcome: NotePush::OUTCOME_NOT_SENT, reason: 'internal');

		$this->expectException(\Error::class);

		/* @phpstan-ignore-next-line — the point of the test is that this is refused. */
		$verdict->outcome = NotePush::OUTCOME_SENT;
	}

	public function testAnAttemptCarriesItsCaseAndTheNoteAsGiven(): void {
		$note = ['id' => 42, 'message' => 'Gebeld met de aanvrager', 'visibility' => 'public'];

		$attempt = new NoteAttempt(caseId: 'case-1', note: $note);

		$this->assertSame(expected: 'case-1', actual: $attempt->caseId);
		$this->assertSame(expected: $note, actual: $attempt->note);
	}

	/**
	 * An attempt takes a note of any shape, including one with no id: what a
	 * note must carry is NoteEnvelope's rule, and the attempt is the thing the
	 * failure is recorded against, so it must hold a note that cannot be sent.
	 *
	 * @return void
	 */
	public function testAnAttemptTakesANoteThatCouldNotBeSent(): void {
		$attempt = new NoteAttempt(caseId: '', note: []);

		$this->assertSame(expected: '', actual: $attempt->caseId);
		$this->assertSame(expected: [], actual: $attempt->note);
	}
}
