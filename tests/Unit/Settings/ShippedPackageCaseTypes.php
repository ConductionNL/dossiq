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
	 * @param string|null $install Only the packages with this install mode (`auto`, `on-request`); null for every package.
	 *
	 * @return list<array<string, mixed>> The case types.
	 */
	public static function nested(?string $install = null): array {
		$nested = [];
		foreach ((array)glob(__DIR__ . '/../../../lib/Settings/packages/*/case-type.json') as $path) {
			$manifest = json_decode((string)file_get_contents(dirname((string)$path) . '/package.json'), true);
			if ($install !== null && (string)($manifest['install'] ?? '') !== $install) {
				continue;
			}

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

	/**
	 * The case types a register.d seed writes, nested like {@see self::nested()}.
	 *
	 * A package may ship only the flow of a case type that register.d seeds:
	 * the Woo request case type keeps its fixed uuid in register.d because the
	 * portal intake opens requests on it, and its flow ships in the
	 * `woo-verzoek` package. The flow sweeps check such a flow against these.
	 *
	 * @return array<int, array<string, mixed>> The case types, children nested.
	 *
	 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/status-transition-engine/spec.md
	 */
	public static function seededByRegister(): array {
		$rows = [];
		foreach ((array)glob(__DIR__ . '/../../../lib/Settings/register.d/*.json') as $path) {
			$data = json_decode((string)file_get_contents((string)$path), true);
			foreach ((array)($data['components']['objects'] ?? []) as $object) {
				if (is_array($object) === true) {
					$rows[] = $object;
				}
			}
		}

		$byCollection = ['statusType' => 'statusTypes', 'roleType' => 'roleTypes', 'resultType' => 'resultTypes', 'propertyDefinition' => 'propertyDefinitions'];
		$nested = [];
		foreach ($rows as $row) {
			if ((string)($row['@self']['schema'] ?? '') !== 'caseType') {
				continue;
			}

			$caseType = $row;
			unset($caseType['@self']);
			$caseType['slug'] = (string)($caseType['identifier'] ?? '');
			foreach ($rows as $child) {
				$collection = ($byCollection[(string)($child['@self']['schema'] ?? '')] ?? null);
				if ($collection !== null && (string)($child['caseType'] ?? '') === (string)($caseType['id'] ?? '')) {
					unset($child['@self']);
					$caseType[$collection][] = $child;
				}
			}

			$nested[] = $caseType;
		}

		return $nested;
	}//end seededByRegister()
}//end class
