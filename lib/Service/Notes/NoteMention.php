<?php

/**
 * One mention being delivered: who wrote the note, under which name, and the note.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Notes
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Notes;

/**
 * The author and the note of one mention.
 *
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */
final class NoteMention {

	/**
	 * Constructor.
	 *
	 * @param string        $actorUserId      The note author's user id.
	 * @param string        $actorDisplayName The note author's display name.
	 * @param MentionedNote $note             The note.
	 */
	public function __construct(
		public readonly string $actorUserId,
		public readonly string $actorDisplayName,
		public readonly MentionedNote $note,
	) {
	}//end __construct()

	/**
	 * The name the queue shows as the writer: the display name, or the user id when there is none.
	 *
	 * @return string The writer.
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	public function writer(): string {
		if ($this->actorDisplayName === '') {
			return $this->actorUserId;
		}

		return $this->actorDisplayName;
	}//end writer()
}//end class
