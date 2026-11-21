<?php

/**
 * A package flow names only statuses its own case type has.
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

use PHPUnit\Framework\TestCase;

/**
 * Sweeps every shipped case definition package.
 *
 * A flow step or transition points at a status by id. A status that exists on
 * the instance but belongs to another case type reads as correct and is never
 * reached: the flow engine compares against the statuses of the case's own
 * type. The VTH catalogue shipped that once (`toezichtbezoek` named
 * `Inspectie`, a status of `toezichtzaak-milieu`), and the template was
 * dropped on every install without a run saying which status was missing.
 *
 * @coversNothing
 *
 * @spec openspec/changes/archive/2026-10-30-vth-package/tasks.md#task-3
 */
class PackageFlowStatusReferencesTest extends TestCase {

	/**
	 * The shipped package directories that carry flows.
	 *
	 * @return list<string> The directories.
	 */
	private function packagesWithFlows(): array {
		$dirs = [];
		foreach ((array)glob(__DIR__ . '/../../../lib/Settings/packages/*', GLOB_ONLYDIR) as $dir) {
			if ((array)glob($dir . '/flows/*.json') !== []) {
				$dirs[] = (string)$dir;
			}
		}

		return $dirs;
	}//end packagesWithFlows()

	/**
	 * The status names of one package, by case type id and status id.
	 *
	 * @param string $dir The package directory.
	 *
	 * @return array<string, array<string, string>> Case type id to status id to name.
	 */
	private function statusesByCaseType(string $dir): array {
		$statuses = [];

		// A package that ships only flows names a case type register.d seeds.
		foreach (ShippedPackageCaseTypes::seededByRegister() as $caseType) {
			foreach ((array)($caseType['statusTypes'] ?? []) as $status) {
				$statuses[(string)($status['caseType'] ?? '')][(string)($status['id'] ?? '')] = (string)($status['name'] ?? '');
			}
		}

		if (is_file($dir . '/case-type.json') === false) {
			return $statuses;
		}

		$data = json_decode((string)file_get_contents($dir . '/case-type.json'), true);
		self::assertIsArray($data, basename($dir) . '/case-type.json must parse');

		foreach ((array)($data['statusTypes'] ?? []) as $status) {
			$statuses[(string)($status['caseType'] ?? '')][(string)($status['id'] ?? '')] = (string)($status['name'] ?? '');
		}

		return $statuses;
	}//end statusesByCaseType()

	/**
	 * A flow's list field, which a package stores as a JSON string.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return list<array<string, mixed>> The decoded rows.
	 */
	private function rows(mixed $value): array {
		if (is_string($value) === true) {
			$value = json_decode($value, true);
		}

		return array_values(array_filter((array)$value, 'is_array'));
	}//end rows()

	/**
	 * THE SWEEP: every status a flow names is a status of the flow's case type, under the name the flow gives it.
	 *
	 * @return void
	 */
	public function testEveryFlowStatusBelongsToTheFlowsCaseType(): void {
		$packages = $this->packagesWithFlows();
		self::assertNotSame([], $packages, 'No package carries a flow, so this sweep would pass on anything.');

		$checked = 0;
		$wrong = [];
		foreach ($packages as $dir) {
			$statuses = $this->statusesByCaseType(dir: $dir);
			foreach ((array)glob($dir . '/flows/*.json') as $file) {
				$flow = json_decode((string)file_get_contents((string)$file), true);
				$where = basename($dir) . '/flows/' . basename((string)$file);
				self::assertIsArray($flow, $where . ' must parse');

				$known = ($statuses[(string)($flow['caseType'] ?? '')] ?? null);
				if ($known === null) {
					$wrong[] = $where . ' names a case type that has no status in its package';
					continue;
				}

				$references = [];
				foreach ($this->rows(value: ($flow['steps'] ?? [])) as $step) {
					$references[] = [(string)($step['status'] ?? ''), (string)($step['statusName'] ?? '')];
				}

				foreach ($this->rows(value: ($flow['transitions'] ?? [])) as $transition) {
					$references[] = [(string)($transition['fromStatus'] ?? ''), (string)($transition['fromStatusName'] ?? '')];
					$references[] = [(string)($transition['toStatus'] ?? ''), (string)($transition['toStatusName'] ?? '')];
				}

				foreach ($references as [$id, $name]) {
					$checked++;
					if (isset($known[$id]) === false) {
						$wrong[] = $where . ' names status "' . $id . '" (' . $name . '), which its case type does not have';
						continue;
					}

					if ($name !== '' && $known[$id] !== $name) {
						$wrong[] = $where . ' calls status ' . $id . ' "' . $name . '", but it is "' . $known[$id] . '"';
					}
				}
			}//end foreach
		}//end foreach

		self::assertGreaterThan(0, $checked, 'No status reference was read, so this sweep would pass on anything.');
		self::assertSame([], $wrong, implode("\n", $wrong));
	}//end testEveryFlowStatusBelongsToTheFlowsCaseType()
}//end class
