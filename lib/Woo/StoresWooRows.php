<?php

/**
 * Dossiq StoresWooRows trait.
 *
 * Saving a row of a Woo schema and reading a row's uuid, as the Woo query
 * and search-plan stores do it.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
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
 * @spec exclude shared storage plumbing of the Woo stores; each store carries its own spec
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

/**
 * Saves a Woo row into its schema and reads a row's uuid.
 *
 * @spec exclude shared storage plumbing of the Woo stores; each store carries its own spec
 */
trait StoresWooRows {
	/**
	 * Save a row, new or existing, and answer it as stored.
	 *
	 * @param array{0: object, 1: string, 2: string} $target OpenRegister, the register and the schema.
	 * @param array<string, mixed>                   $row    The row.
	 * @param string                                 $uuid   The existing uuid, '' for a new row.
	 *
	 * @return array<string, mixed> The row as stored.
	 */
	private function storeRow(array $target, array $row, string $uuid): array {
		[$objectService, $register, $schema] = $target;
		unset($row['id'], $row['uuid'], $row['@self']);

		$existing = null;
		if ($uuid !== '') {
			$existing = $uuid;
		}

		$saved = $objectService->saveObject(object: $row, register: $register, schema: $schema, uuid: $existing);

		if (is_object($saved) === true && method_exists($saved, 'jsonSerialize') === true) {
			$saved = $saved->jsonSerialize();
		}

		return (array)$saved;
	}//end storeRow()

	/**
	 * A row's uuid.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or ''.
	 */
	private function idOf(array $row): string {
		$self = (array)($row['@self'] ?? []);
		return trim((string)($row['id'] ?? ($row['uuid'] ?? ($self['id'] ?? ''))));
	}//end idOf()
}//end trait
