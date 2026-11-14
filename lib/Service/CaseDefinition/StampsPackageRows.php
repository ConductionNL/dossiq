<?php

/**
 * Records the rows a package wrote or adopted in the shipped-set ledger.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

/**
 * Stamps a package's written and adopted rows under the package in the ledger.
 *
 * The using class holds the shipped-set ledger as `$this->ledger`
 * (a ShippedConfigurationService).
 *
 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
 */
trait StampsPackageRows {

	/**
	 * Record the written rows, then the adopted rows this package has not
	 * recorded before, stopping at the first that fails.
	 *
	 * @param PackageInstallRun                $run     The install; its package id is the set name.
	 * @param array<int, array<string, mixed>> $written The rows written.
	 * @param array<int, array<string, mixed>> $adopted The rows adopted.
	 *
	 * @return boolean True when every row was recorded.
	 *
	 * @spec openspec/changes/case-definition-packages/specs/case-types/spec.md
	 */
	private function recordRows(PackageInstallRun $run, array $written, array $adopted): bool {
		return $this->record(run: $run, written: $written)
			&& $this->record(run: $run, written: $this->notYetInThisPackage(id: $run->id, adopted: $adopted));
	}//end recordRows()

	/**
	 * Record every written row in the shipped-set ledger, under the package id.
	 *
	 * @param PackageInstallRun                $run     The install; its package id is the set name.
	 * @param array<int, array<string, mixed>> $written The rows written.
	 *
	 * @return boolean True when every row was recorded.
	 */
	private function record(PackageInstallRun $run, array $written): bool {
		foreach ($written as $row) {
			$stamped = $this->ledger->stamp(
				set: $run->id,
				targetSchema: (string)$row['schema'],
				targetObject: (string)$row['id'],
				object: (array)$row['row'],
				version: $run->version,
				objectsKey: (string)($row['schemaKey'] ?? ''),
			);
			if ($stamped === false) {
				return false;
			}
		}

		return true;
	}//end record()

	/**
	 * The adopted rows this package has not recorded before.
	 *
	 * An adopted row is stamped once, with the row as this instance holds it
	 * at adoption. Stamping it again on every newer version would reset its
	 * fingerprint and hide an edit made here since.
	 *
	 * @param string                           $id      The package id.
	 * @param array<int, array<string, mixed>> $adopted The rows adopted this run.
	 *
	 * @return array<int, array<string, mixed>> The rows to record.
	 */
	private function notYetInThisPackage(string $id, array $adopted): array {
		return array_values(
			array_filter(
				$adopted,
				fn (array $row): bool => $this->ledger->stateOf(
					targetSchema: (string)$row['schema'],
					targetObject: (string)$row['id'],
					object: (array)$row['row'],
				)['set'] !== $id
			)
		);
	}//end notYetInThisPackage()
}//end trait
