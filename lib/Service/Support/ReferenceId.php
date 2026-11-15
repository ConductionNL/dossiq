<?php

/**
 * The id a reference names, however the register spelled it
 *
 * A reference to another object arrives as a bare id string or as an object
 * row. A string is trimmed; a row gives its `id`, else its `uuid`, trimmed
 * when it is a string; anything else names nothing. Unlike
 * CaseRegisterReads::uuidOf(), a row whose `id` is set but is not a string
 * does not fall through to its `uuid`.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

/**
 * Reads the id out of a reference.
 *
 * @spec openspec/specs/case-management/spec.md
 */
final class ReferenceId {
	/**
	 * The id a reference names, or an empty string.
	 *
	 * @param mixed $value A bare id, an object row, or anything else.
	 *
	 * @return string The trimmed id, or an empty string.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public static function of(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? ''));
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end of()
}//end class
