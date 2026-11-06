<?php

/**
 * Dossiq adopted case type fill.
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

use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;

/**
 * Which shipped fields an adopted case type lacks, and writing them.
 *
 * @spec openspec/changes/package-adoption-fills-missing-case-type-fields/specs/case-types/spec.md#requirement-an-adopted-case-type-gets-the-fields-it-lacks-and-keeps-the-ones-it-has-req-ct-adopt-01
 */
class AdoptedCaseTypeFill {

	use WritesPackageRows;

	/**
	 * Keys that belong to the store or to the row's identity, never filled.
	 *
	 * @var array<int, string>
	 */
	private const NEVER = ['id', 'uuid', '@self'];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Hands out the object service.
	 * @param LoggerInterface $logger   Records a fill the store refused.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
	public function lackedBy(array $shipped, array $stored): array {
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
	}//end lackedBy()

	/**
	 * The row with every package id that was adopted replaced by the id it has here.
	 *
	 * References sit in plain fields (`caseType`) and inside JSON strings
	 * (a flow's `steps` and `transitions`), so every string value is rewritten,
	 * at any depth. A package id is a UUID, so it cannot occur by accident
	 * inside other text. The row's own id is left alone.
	 *
	 * @param array<string, mixed>  $row The row.
	 * @param array<string, string> $ids Package id to existing id.
	 *
	 * @return array<string, mixed> The row.
	 *
	 * @spec openspec/changes/package-adoption-fills-missing-case-type-fields/specs/case-types/spec.md#requirement-an-adopted-case-type-gets-the-fields-it-lacks-and-keeps-the-ones-it-has-req-ct-adopt-01
	 */
	public function pointedAt(array $row, array $ids): array {
		if ($ids === []) {
			return $row;
		}

		$own = ($row['id'] ?? null);
		array_walk_recursive(
			$row,
			static function (mixed &$value) use ($ids): void {
				if (is_string($value) === true) {
					$value = strtr($value, $ids);
				}
			}
		);
		if ($own !== null) {
			$row['id'] = $own;
		}

		return $row;
	}//end pointedAt()

	/**
	 * The adopted rows with their fills pointing at this instance's ids, and
	 * each row as it will read once filled.
	 *
	 * A fill is taken when its case type is adopted, before the statuses and
	 * roles after it are; it is pointed at their ids once the whole run is known.
	 *
	 * @param array<int, array<string, mixed>> $adopted The rows adopted this run.
	 * @param array<string, string>            $ids     Package id to existing id.
	 *
	 * @return array<int, array<string, mixed>> The adopted rows.
	 *
	 * @spec openspec/changes/package-adoption-fills-missing-case-type-fields/specs/case-types/spec.md#requirement-an-adopted-case-type-gets-the-fields-it-lacks-and-keeps-the-ones-it-has-req-ct-adopt-01
	 */
	public function withFillsPointedAt(array $adopted, array $ids): array {
		foreach ($adopted as $index => $entry) {
			if (($entry['fill'] ?? []) === []) {
				continue;
			}

			$fill = $this->pointedAt(row: $entry['fill'], ids: $ids);
			$adopted[$index]['fill'] = $fill;
			$adopted[$index]['row'] = array_merge($entry['row'], $fill);
		}

		return $adopted;
	}//end withFillsPointedAt()

	/**
	 * Write each adopted case type as it reads once filled.
	 *
	 * @param array<int, array<string, mixed>> $adopted The adopted rows, from {@see self::withFillsPointedAt()}.
	 *
	 * @return array<int, string> The ids of the rows that were filled.
	 *
	 * @spec openspec/changes/package-adoption-fills-missing-case-type-fields/specs/case-types/spec.md#requirement-an-adopted-case-type-gets-the-fields-it-lacks-and-keeps-the-ones-it-has-req-ct-adopt-01
	 */
	public function write(array $adopted): array {
		$objectService = $this->settings->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$filled = [];
		foreach ($adopted as $entry) {
			if (($entry['fill'] ?? []) === []) {
				continue;
			}

			try {
				// An administrator's install, which passes the access check as PackageWriter's writes do.
				$objectService->saveObject(
					$this->withoutMetadata(row: (array)$entry['row']),
					register: (string)$entry['register'],
					schema: (string)$entry['schema'],
					uuid: (string)$entry['id'],
					_rbac: false
				);
				$filled[] = (string)$entry['id'];
			} catch (\Throwable $e) {
				$this->logger->warning(
					'Package install: adopted case type {id} kept without the fields it lacked: {error}',
					['id' => $entry['id'], 'error' => $e->getMessage()]
				);
			}
		}//end foreach

		return $filled;
	}//end write()
}//end class
