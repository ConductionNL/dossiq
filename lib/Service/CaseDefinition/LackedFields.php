<?php

/**
 * Dossiq lacked fields.
 *
 * The fields a package ships that a stored row does not have. When a package
 * adopts a case type an instance already holds, those are the only fields it
 * may give that row: a field the row has, empty or not, is the instance's.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
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
 * @spec openspec/changes/package-adoption-fills-missing-case-type-fields/specs/case-types/spec.md#requirement-an-adopted-case-type-gets-the-fields-it-lacks-and-keeps-the-ones-it-has-req-ct-adopt-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

/**
 * Which shipped fields a stored row lacks.
 *
 * @spec openspec/changes/package-adoption-fills-missing-case-type-fields/specs/case-types/spec.md#requirement-an-adopted-case-type-gets-the-fields-it-lacks-and-keeps-the-ones-it-has-req-ct-adopt-01
 */
class LackedFields {
	/**
	 * Keys that belong to the store or to the row's identity, never filled.
	 *
	 * @var array<int, string>
	 */
	private const NEVER = ['id', 'uuid', '@self'];

	/**
	 * The shipped fields the stored row does not have.
	 *
	 * A field counts as lacking when the stored row has no such key or holds
	 * null there. An empty string or an empty list is a value somebody set, so
	 * it stays.
	 *
	 * @param array<string, mixed> $shipped The row as the package ships it.
	 * @param array<string, mixed> $stored  The row as this instance holds it.
	 *
	 * @return array<string, mixed> The fields to add, with the package's values.
	 *
	 * @spec openspec/changes/package-adoption-fills-missing-case-type-fields/specs/case-types/spec.md#requirement-an-adopted-case-type-gets-the-fields-it-lacks-and-keeps-the-ones-it-has-req-ct-adopt-01
	 */
	public function of(array $shipped, array $stored): array {
		$lacked = [];
		foreach ($shipped as $key => $value) {
			if (in_array((string)$key, self::NEVER, true) === true || $value === null) {
				continue;
			}

			if (array_key_exists($key, $stored) === false || $stored[$key] === null) {
				$lacked[$key] = $value;
			}
		}

		return $lacked;
	}//end of()
}//end class
