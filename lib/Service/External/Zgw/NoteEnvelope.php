<?php

/**
 * A case note, shaped as a ZGW document envelope.
 *
 * ZGW HAS NO NOTE RESOURCE, and that is the whole reason this class exists.
 * `zgw-connectors-for-dossiq` (integriq#2070) ships six packaged sets,
 * `zgw-zaken`, `zgw-documenten`, `zgw-catalogi`, `zgw-besluiten`,
 * `zgw-objecten` and `zgw-notificaties`, and none of them carries a note,
 * because the standard has nowhere to put one. So the hole was never a
 * missing connector; it was that nothing had decided what a note IS on the
 * wire, and dossiq is the app that owns the note.
 *
 * The decision is that a note travels as a `zaakinformatieobject` of a
 * reserved informatieobjecttype, over the `submitDocument` path that already
 * exists. A note has an author, a moment, a body and a case, and so does a
 * ZGW document; the receiving register can read it, file it and show it
 * without knowing dossiq exists.
 *
 * The three alternatives are worse and were each considered. Appending notes
 * to `zaak.toelichting` overwrites one note with the next and loses the
 * author. Waiting for the Klantinteracties route makes a note ABOUT A CASE
 * into a note about a person, which it is not. A private extension gives a
 * payload only dossiq can read, which is the opposite of what a neighbouring
 * register is for.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Zgw
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Zgw;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Builds the ZGW document envelope one note travels in.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
class NoteEnvelope {

	/**
	 * The language every note is declared in.
	 *
	 * ZGW `taal` is ISO 639-2/B. A note written by a Dutch caseworker on a
	 * Dutch case is Dutch; a per-note language would need somewhere to store
	 * one, and there is nowhere, so declaring the instance's language is the
	 * honest answer rather than leaving the field off and letting the
	 * receiver guess.
	 */
	public const LANGUAGE = 'nld';

	/**
	 * How many characters of the first line become the title.
	 */
	private const TITLE_LIMIT = 100;

	/**
	 * Shape one note as a ZGW document envelope.
	 *
	 * @param array<string, mixed> $note             The note as OpenRegister answers it.
	 * @param string               $caseId           The case this note is on.
	 * @param string               $informatieobjecttype The reserved note type.
	 * @param string               $bronorganisatie  The sending organisation's RSIN.
	 *
	 * @return array<string, mixed> The envelope.
	 *
	 * @throws InvalidArgumentException When the note has no text, or no type is reserved.
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function build(
		array $note,
		string $caseId,
		string $informatieobjecttype,
		string $bronorganisatie = '',
	): array {
		$body = trim((string)($note['message'] ?? ''));
		if ($body === '') {
			// An empty note sent as a document is a document with no content,
			// which the receiver files and nobody can read. Refusing here is
			// cheaper than a rejection three systems away.
			throw new InvalidArgumentException('A note with no text is not sent.');
		}

		if (trim($informatieobjecttype) === '') {
			// ADR-102: absent configuration fails closed, with a reason. A
			// guessed informatieobjecttype carries a guessed retention term,
			// and the term for a working note is not the term for a decision
			// letter: guessing silently destroys notes or silently keeps them
			// for years.
			throw new InvalidArgumentException(
				'No informatieobjecttype is reserved for case notes, so nothing is sent. '
				. 'Set the dossiq app config key `note_informatieobjecttype` to the type '
				. 'your catalogue reserved for them.'
			);
		}

		if (trim($caseId) === '') {
			throw new InvalidArgumentException('A note with no case is not sent.');
		}

		return [
			'identificatie' => 'dossiq-note-' . (string)($note['id'] ?? ''),
			'bronorganisatie' => $bronorganisatie,
			'titel' => $this->titleOf(body: $body),
			'auteur' => $this->authorOf(note: $note),
			'taal' => self::LANGUAGE,
			'informatieobjecttype' => trim($informatieobjecttype),
			'creatiedatum' => $this->createdOn(note: $note),
			'formaat' => 'text/plain',
			'inhoud' => base64_encode($body),
			'zaak' => $caseId,
		];
	}//end build()

	/**
	 * The title a note gets, from its first line.
	 *
	 * A ZGW document needs a title and a note has none. The first line is what
	 * a reader would call the note anyway, and a long one is cut rather than
	 * sent whole: a title field holding four paragraphs renders as noise in
	 * every list the receiving register shows.
	 *
	 * @param string $body The note's text.
	 *
	 * @return string The title.
	 */
	private function titleOf(string $body): string {
		$firstLine = trim((string)(preg_split('/\R/', $body)[0] ?? ''));
		if ($firstLine === '') {
			$firstLine = $body;
		}

		if (mb_strlen($firstLine) <= self::TITLE_LIMIT) {
			return $firstLine;
		}

		return mb_substr($firstLine, 0, (self::TITLE_LIMIT - 1)) . "\u{2026}";
	}//end titleOf()

	/**
	 * Who wrote the note.
	 *
	 * The display name, falling back to the actor id. Never empty: `auteur` is
	 * required, and an empty one is how a note arrives at a neighbouring
	 * register with nobody's name on it.
	 *
	 * @param array<string, mixed> $note The note.
	 *
	 * @return string The author.
	 */
	private function authorOf(array $note): string {
		$displayName = trim((string)($note['actorDisplayName'] ?? ''));
		if ($displayName !== '') {
			return $displayName;
		}

		$actorId = trim((string)($note['actorId'] ?? ''));

		if ($actorId === '') {
			return 'onbekend';
		}

		return $actorId;
	}//end authorOf()

	/**
	 * The day the note was written, as ZGW `creatiedatum`.
	 *
	 * A note whose moment cannot be read gets today rather than nothing: the
	 * field is required, and an absent one is refused by the receiver after
	 * the push rather than before it.
	 *
	 * @param array<string, mixed> $note The note.
	 *
	 * @return string The date, `Y-m-d`.
	 */
	private function createdOn(array $note): string {
		$raw = trim((string)($note['createdAt'] ?? ''));
		if ($raw === '') {
			return (new DateTimeImmutable())->format('Y-m-d');
		}

		try {
			return (new DateTimeImmutable($raw))->format('Y-m-d');
		} catch (\Exception $e) {
			return (new DateTimeImmutable())->format('Y-m-d');
		}
	}//end createdOn()
}//end class
