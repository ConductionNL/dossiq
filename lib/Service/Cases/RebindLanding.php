<?php

/**
 * Dossiq RebindLanding.
 *
 * Where one answer lands in a rebind: the target field, the value it lands
 * with, and whether the coordinator chose the field.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

/**
 * The target field and value of one ported answer.
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
final class RebindLanding {
	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $target   The field it lands on.
	 * @param string               $value    The value it lands with.
	 * @param boolean              $remapped Whether the coordinator chose the field.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $target,
		public readonly string $value,
		public readonly bool $remapped,
	) {
	}//end __construct()
}//end class
