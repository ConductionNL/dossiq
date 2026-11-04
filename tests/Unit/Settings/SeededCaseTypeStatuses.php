<?php

/**
 * Every case type the app ships, with its own status list and its initial status.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Settings
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Settings;

/**
 * Reads case types and status types from the register seed, its fragments, the
 * demo fragments, the example set and the packages.
 *
 * Each source names its rows its own way (`@ref:` slugs, `{{object:key.id}}`
 * tokens, fixed uuids), so every reference is reduced to a bare handle and a
 * case type is matched to the statuses whose `caseType` names one of its handles.
 */
final class SeededCaseTypeStatuses {

	/**
	 * The lib/Settings directory.
	 */
	private const SETTINGS = __DIR__ . '/../../../lib/Settings';

	/**
	 * Every shipped case type.
	 *
	 * @return list<array{name: string, initialStatus: ?string, statuses: list<array{handles: list<string>, order: int}>}> The case types.
	 */
	public static function all(): array {
		$pools = [self::registerPool(), self::exampleSetPool()];
		foreach ((array)glob(self::SETTINGS . '/packages/*/case-type.json') as $path) {
			$pools[] = self::packagePool(path: (string)$path);
		}

		$caseTypes = [];
		foreach ($pools as $pool) {
			foreach ($pool['caseTypes'] as $caseType) {
				$caseType['statuses'] = self::statusesOf(handles: $caseType['handles'], statuses: $pool['statuses']);
				unset($caseType['handles']);
				$caseTypes[] = $caseType;
			}
		}

		return $caseTypes;
	}//end all()

	/**
	 * The handles of the lowest-order statuses in a list.
	 *
	 * @param list<array{handles: list<string>, order: int}> $statuses The status list.
	 *
	 * @return list<string> The handles of every status at the lowest order.
	 */
	public static function firstStatusHandles(array $statuses): array {
		$lowest  = min(array_column($statuses, 'order'));
		$handles = [];
		foreach ($statuses as $status) {
			if ($status['order'] === $lowest) {
				$handles = array_merge($handles, $status['handles']);
			}
		}

		return $handles;
	}//end firstStatusHandles()

	/**
	 * Reduce a reference to the handle it names.
	 *
	 * @param mixed $ref A slug, uuid, `@ref:[schema:]slug` or `{{object:key.id}}`.
	 *
	 * @return string|null The handle, or null for no reference.
	 */
	public static function handle(mixed $ref): ?string {
		if (is_string($ref) === false || $ref === '') {
			return null;
		}

		if (preg_match('/^\{\{object:(.+)\.id\}\}$/', $ref, $match) === 1) {
			return $match[1];
		}

		return (string)preg_replace('/^@ref:((caseType|statusType):)?/', '', $ref);
	}//end handle()

	/**
	 * The statuses in a pool that belong to one case type.
	 *
	 * @param list<string>                                                      $handles  The case type's handles.
	 * @param list<array{caseType: ?string, handles: list<string>, order: int}> $statuses The pool's statuses.
	 *
	 * @return list<array{handles: list<string>, order: int}> Its own status list.
	 */
	private static function statusesOf(array $handles, array $statuses): array {
		$own = [];
		foreach ($statuses as $status) {
			if (in_array($status['caseType'], $handles, true) === true) {
				$own[] = ['handles' => $status['handles'], 'order' => $status['order']];
			}
		}

		return $own;
	}//end statusesOf()

	/**
	 * The register seed, its fragments and the demo fragments: one namespace of slugs.
	 *
	 * @return array{caseTypes: list<array<string, mixed>>, statuses: list<array<string, mixed>>} The pool.
	 */
	private static function registerPool(): array {
		$paths = array_merge(
			[self::SETTINGS . '/dossiq_register.json'],
			(array)glob(self::SETTINGS . '/register.d/*.json'),
			(array)glob(self::SETTINGS . '/demo.d/*.json')
		);
		$pool  = ['caseTypes' => [], 'statuses' => []];
		foreach ($paths as $path) {
			$data = json_decode((string)file_get_contents((string)$path), true);
			foreach ((array)($data['components']['objects'] ?? []) as $row) {
				$self    = (array)($row['@self'] ?? []);
				$handles = array_values(array_filter([$self['slug'] ?? null, $row['id'] ?? null, $row['uuid'] ?? null]));
				$name    = basename((string)$path) . ':' . (string)($self['slug'] ?? '');
				$pool    = self::addRow(pool: $pool, schema: (string)($self['schema'] ?? ''), row: $row, meta: ['name' => $name, 'handles' => $handles]);
			}
		}

		return $pool;
	}//end registerPool()

	/**
	 * The example set: rows named by `key`, an initial status set by a later change.
	 *
	 * @return array{caseTypes: list<array<string, mixed>>, statuses: list<array<string, mixed>>} The pool.
	 */
	private static function exampleSetPool(): array {
		$set     = json_decode((string)file_get_contents(self::SETTINGS . '/example-sets/zuiddrecht.json'), true);
		$changed = [];
		foreach ((array)($set['changes'] ?? []) as $change) {
			if (isset($change['object'], $change['set']['initialStatus']) === true) {
				$changed[(string)$change['object']] = $change['set']['initialStatus'];
			}
		}

		$pool = ['caseTypes' => [], 'statuses' => []];
		foreach ((array)($set['objects'] ?? []) as $object) {
			$key = (string)($object['key'] ?? '');
			$row = (array)($object['data'] ?? []);
			$row['initialStatus'] = ($row['initialStatus'] ?? ($changed[$key] ?? null));
			$meta = ['name' => 'zuiddrecht.json:' . $key, 'handles' => [$key]];
			$pool = self::addRow(pool: $pool, schema: (string)($object['schema'] ?? ''), row: $row, meta: $meta);
		}

		return $pool;
	}//end exampleSetPool()

	/**
	 * One package's case type file: rows named by fixed uuids.
	 *
	 * @param string $path The case-type.json path.
	 *
	 * @return array{caseTypes: list<array<string, mixed>>, statuses: list<array<string, mixed>>} The pool.
	 */
	private static function packagePool(string $path): array {
		$data      = json_decode((string)file_get_contents($path), true);
		$caseTypes = (array)($data['caseType'] ?? []);
		if (array_is_list($caseTypes) === false) {
			$caseTypes = [$caseTypes];
		}

		$package = basename(dirname($path));
		$pool    = ['caseTypes' => [], 'statuses' => []];
		foreach ($caseTypes as $row) {
			$meta = ['name' => $package . ':' . (string)($row['identifier'] ?? ''), 'handles' => [(string)($row['id'] ?? '')]];
			$pool = self::addRow(pool: $pool, schema: 'caseType', row: $row, meta: $meta);
		}

		foreach ((array)($data['statusTypes'] ?? []) as $row) {
			$pool = self::addRow(pool: $pool, schema: 'statusType', row: $row, meta: ['name' => '', 'handles' => [(string)($row['id'] ?? '')]]);
		}

		return $pool;
	}//end packagePool()

	/**
	 * Add a case type or status type row to a pool; other rows are left out.
	 *
	 * @param array<string, list<array<string, mixed>>>    $pool   The pool so far.
	 * @param string                                       $schema The row's schema.
	 * @param array<string, mixed>                         $row    The row.
	 * @param array{name: string, handles: list<string>}   $meta   Its name and handles.
	 *
	 * @return array<string, list<array<string, mixed>>> The pool.
	 */
	private static function addRow(array $pool, string $schema, array $row, array $meta): array {
		if ($schema === 'caseType') {
			$initial = self::handle(ref: $row['initialStatus'] ?? null);
			$pool['caseTypes'][] = ['name' => $meta['name'], 'handles' => $meta['handles'], 'initialStatus' => $initial];
		}

		if ($schema === 'statusType') {
			$caseType = self::handle(ref: $row['caseType'] ?? null);
			$pool['statuses'][] = ['caseType' => $caseType, 'handles' => $meta['handles'], 'order' => (int)($row['order'] ?? 0)];
		}

		return $pool;
	}//end addRow()
}//end class
