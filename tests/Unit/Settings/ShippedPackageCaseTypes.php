<?php

/**
 * Reads the case types the shipped case definition packages carry.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

/**
 * The sweeps over shipped case types were written against seed files that nest
 * a case type's statuses, roles, document types and fields inside it and name
 * it by `slug`. A package stores the same rows flat, each child pointing at
 * its case type by id, and names the case type by `identifier`. This reader
 * answers the package rows in the nested shape, so one sweep reads both.
 */
final class ShippedPackageCaseTypes {

	/**
	 * The child collections a package's case type file carries.
	 */
	private const COLLECTIONS = ['statusTypes', 'roleTypes', 'documentTypes', 'propertyDefinitions', 'resultTypes'];

	/**
	 * Every case type of every shipped package, children nested, `slug` set to its identifier.
	 *
	 * @return list<array<string, mixed>> The case types.
	 */
	public static function nested(): array {
		$nested = [];
		foreach ((array)glob(__DIR__ . '/../../../lib/Settings/packages/*/case-type.json') as $path) {
			$data = json_decode((string)file_get_contents((string)$path), true);
			if (is_array($data) === false) {
				continue;
			}

			$caseTypes = (array)($data['caseType'] ?? []);
			if (array_is_list($caseTypes) === false) {
				$caseTypes = [$caseTypes];
			}

			foreach ($caseTypes as $caseType) {
				$caseType = (array)$caseType;
				$caseType['slug'] = (string)($caseType['identifier'] ?? '');
				foreach (self::COLLECTIONS as $collection) {
					$children = array_filter(
						(array)($data[$collection] ?? []),
						static fn ($row): bool => is_array($row) === true
							&& (string)($row['caseType'] ?? '') === (string)($caseType['id'] ?? '')
					);
					if ($children !== []) {
						$caseType[$collection] = array_values($children);
					}
				}

				$nested[] = $caseType;
			}
		}//end foreach

		return $nested;
	}//end nested()
}//end class
