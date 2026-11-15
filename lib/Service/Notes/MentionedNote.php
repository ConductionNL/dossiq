<?php

/**
 * The note a mention is made in: the object it is attached to, where that
 * object lives, and the note's own id.
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
 * A note on an OpenRegister object, as a mention names it.
 *
 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
 */
final class MentionedNote {

	/**
	 * Constructor.
	 *
	 * @param string $objectId The OpenRegister object UUID the note is attached to.
	 * @param string $register The OpenRegister register slug.
	 * @param string $schema   The OpenRegister schema slug.
	 * @param string $noteId   The note's id.
	 */
	public function __construct(
		public readonly string $objectId,
		public readonly string $register,
		public readonly string $schema,
		public readonly string $noteId,
	) {
	}//end __construct()

	/**
	 * The object type a notification names: the schema, or `note` when there is none.
	 *
	 * @return string The object type.
	 *
	 * @spec openspec/specs/ncvue-w2-leaves-adoption/spec.md
	 */
	public function objectType(): string {
		if ($this->schema === '') {
			return 'note';
		}

		return $this->schema;
	}//end objectType()
}//end class
