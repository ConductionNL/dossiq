<?php

/**
 * The Zuiddrecht example set installs without the on-request packages.
 *
 * OpenRegister's example-set installer refuses the whole set when one lookup
 * finds nothing. A case type that only an on-request package ships is absent
 * on an instance where nobody asked for that package, so a lookup of it would
 * take every example of every app down with it. Example cases of such a case
 * type ship with the package (`examples/`) instead.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/package-examples/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

use PHPUnit\Framework\TestCase;

/**
 * No lookup of the Zuiddrecht set depends on an on-request package.
 *
 * @spec openspec/changes/package-examples/specs/case-types/spec.md
 */
class ZuiddrechtSetNeedsNoOnRequestPackageTest extends TestCase {

	/**
	 * What only an on-request package ships: case types by identifier and
	 * role types by name, the two business keys the set looks them up by.
	 *
	 * @return array{caseType: array<string, true>, roleType: array<string, true>} The keys.
	 */
	private function onRequestOnly(): array {
		$always = ['caseType' => [], 'roleType' => []];
		foreach (array_merge(ShippedPackageCaseTypes::nested(install: 'auto'), ShippedPackageCaseTypes::seededByRegister()) as $caseType) {
			$always['caseType'][(string)$caseType['slug']] = true;
			foreach ((array)($caseType['roleTypes'] ?? []) as $roleType) {
				$always['roleType'][(string)($roleType['name'] ?? '')] = true;
			}
		}

		$onRequest = ['caseType' => [], 'roleType' => []];
		foreach (ShippedPackageCaseTypes::nested(install: 'on-request') as $caseType) {
			$slug = (string)$caseType['slug'];
			if ($slug !== '' && isset($always['caseType'][$slug]) === false) {
				$onRequest['caseType'][$slug] = true;
			}

			foreach ((array)($caseType['roleTypes'] ?? []) as $roleType) {
				$name = (string)($roleType['name'] ?? '');
				if ($name !== '' && isset($always['roleType'][$name]) === false) {
					$onRequest['roleType'][$name] = true;
				}
			}
		}

		return $onRequest;
	}//end onRequestOnly()

	/**
	 * Every case type (by identifier) and role type (by name) the set looks
	 * up, with the object that looks it up.
	 *
	 * @return array<int, array{key: string, schema: string, value: string}> The lookups.
	 */
	private function lookups(): array {
		$set = json_decode((string)file_get_contents(__DIR__ . '/../../../lib/Settings/example-sets/zuiddrecht.json'), true);
		self::assertIsArray(actual: $set, message: 'The Zuiddrecht set must parse.');

		$lookups = [];
		foreach ((array)($set['objects'] ?? []) as $object) {
			foreach ((array)($object['lookups'] ?? []) as $lookup) {
				$schema = (string)($lookup['schema'] ?? '');
				$value = match ($schema) {
					'caseType' => ($lookup['where']['identifier'] ?? null),
					'roleType' => ($lookup['where']['name'] ?? null),
					default => null,
				};
				if (is_string($value) === true) {
					$lookups[] = ['key' => (string)$object['key'], 'schema' => $schema, 'value' => $value];
				}
			}
		}

		return $lookups;
	}//end lookups()

	/**
	 * The set installs on an instance that has none of the on-request packages.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/package-examples/specs/case-types/spec.md
	 */
	public function testNoLookupNeedsAnOnRequestPackage(): void {
		$onRequest = $this->onRequestOnly();
		self::assertArrayHasKey(
			key: 'aanbesteding',
			array: $onRequest['caseType'],
			message: 'The Aanbesteding case type ships only on request; this test reads the packages wrongly if it is missing.'
		);
		self::assertArrayHasKey(
			key: 'Voorzitter commissie',
			array: $onRequest['roleType'],
			message: 'The committee chair ships only with bezwaar-beroep; this test reads the packages wrongly if it is missing.'
		);

		$lookups = $this->lookups();
		self::assertNotSame(expected: [], actual: $lookups, message: 'The set looks up case types; finding none means this test reads it wrongly.');
		foreach ($lookups as $lookup) {
			self::assertArrayNotHasKey(
				key: $lookup['value'],
				array: $onRequest[$lookup['schema']],
				message: "Object \"{$lookup['key']}\" looks up {$lookup['schema']} \"{$lookup['value']}\", which only an on-request package ships."
				. ' Ship the example with that package (examples/) instead.'
			);
		}
	}//end testNoLookupNeedsAnOnRequestPackage()
}//end class
