<?php

/**
 * Dossiq sub-case link.
 *
 * What a child is derived from: the parent entity, OpenRegister's relation
 * service when it can be reached, and what `case.parentCase` declares the
 * child takes.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Deelzaak
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
 * @spec openspec/specs/deelzaak-support/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Deelzaak;

/**
 * The parent, relation service and declaration of one derive.
 *
 * @spec openspec/specs/deelzaak-support/spec.md
 */
final class SubCaseLink {

	/**
	 * Constructor.
	 *
	 * @param object               $parent      The parent entity.
	 * @param object|null          $relations   OpenRegister's relation service, or null when unreachable.
	 * @param array<string, mixed> $declaration What `case.parentCase` declares.
	 */
	public function __construct(
		public readonly object $parent,
		public readonly ?object $relations,
		public readonly array $declaration,
	) {
	}//end __construct()

	/**
	 * What the child takes from its parent, as the declaration names it.
	 *
	 * @return mixed The inherits map; none when the declaration names none.
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	public function inherits(): mixed {
		return ($this->declaration['inherits'] ?? []);
	}//end inherits()

	/**
	 * The vocabulary key naming the link, as the declaration gives it.
	 *
	 * @return string|null The relation type.
	 *
	 * @spec openspec/specs/deelzaak-support/spec.md
	 */
	public function relationType(): ?string {
		return ($this->declaration['type'] ?? null);
	}//end relationType()
}//end class
