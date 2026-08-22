<?php

/**
 * Dossiq OpenRegister row normaliser.
 *
 * Turns one `ObjectService::findAll()` result row into a uuid + payload pair,
 * whatever shape the row arrives in. Split out of BackfillLegalHoldsCommand
 * because this is not backfill logic at all: it is tolerance for an
 * OpenRegister return shape that has already changed once and may change
 * again. `findAll()` returns ObjectEntity instances on that path rather than
 * the rendered arrays its docblock implies, so both are accepted here —
 * a guess at the shape is precisely the kind of assumption that made the
 * original dead-listener bug invisible.
 *
 * @category Command
 * @package  OCA\Dossiq\Command\Backfill
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command\Backfill;

/**
 * Normalises OpenRegister findAll() rows into uuid + payload pairs.
 *
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */
class OpenRegisterRowNormaliser {
	/**
	 * Normalise one findAll() result into a uuid + payload pair.
	 *
	 * @param mixed $row One findAll() result row.
	 *
	 * @return array{uuid: string, data: array<string, mixed>} Normalised row.
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	public function normalise(mixed $row): array {
		if (is_object($row) === true) {
			return $this->normaliseObjectRow(row: $row);
		}

		if (is_array($row) === true) {
			return ['uuid' => $this->uuidFromArray(row: $row), 'data' => $row];
		}

		return ['uuid' => '', 'data' => []];
	}//end normalise()

	/**
	 * Normalise an ObjectEntity-shaped findAll() row into a uuid + payload pair.
	 *
	 * @param object $row One findAll() result row.
	 *
	 * @return array{uuid: string, data: array<string, mixed>} Normalised row.
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	private function normaliseObjectRow(object $row): array {
		$uuid = $this->uuidFromObject(row: $row);
		$data = [];

		if (method_exists($row, 'getObject') === true && is_array($row->getObject()) === true) {
			$data = $row->getObject();
		}

		// Fall back to jsonSerialize(), the shape the rest of OpenRegister
		// renders to, so a future return-shape change cannot silently empty
		// the uuid again — an empty uuid here disables the closed-proceeding
		// filter without any visible error, which is exactly what happened
		// during development of this command.
		if ($uuid === '' && method_exists($row, 'jsonSerialize') === true) {
			$serialised = $row->jsonSerialize();
			if (is_array($serialised) === true) {
				$uuid = $this->uuidFromArray(row: $serialised);
				if ($data === []) {
					$data = $serialised;
				}
			}
		}//end if

		return ['uuid' => $uuid, 'data' => $data];
	}//end normaliseObjectRow()

	/**
	 * Read an object uuid off an ObjectEntity, trying getUuid() then getId().
	 *
	 * @param object $row One findAll() result row.
	 *
	 * @return string The uuid, or '' when neither getter yields one.
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	private function uuidFromObject(object $row): string {
		$uuid = '';
		foreach (['getUuid', 'getId'] as $getter) {
			if ($uuid === '' && method_exists($row, $getter) === true) {
				$uuid = (string)($row->$getter() ?? '');
			}
		}

		return $uuid;
	}//end uuidFromObject()

	/**
	 * Read an object uuid out of a rendered object array, whatever its shape.
	 *
	 * @param array<string, mixed> $row A rendered OpenRegister object.
	 *
	 * @return string The uuid, or '' when no known key carries one.
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	private function uuidFromArray(array $row): string {
		$self = ($row['@self'] ?? []);
		if (is_array($self) === false) {
			$self = [];
		}

		foreach ([$self['uuid'] ?? null, $self['id'] ?? null, $row['uuid'] ?? null, $row['id'] ?? null] as $value) {
			if (is_scalar($value) === true && (string)$value !== '') {
				return (string)$value;
			}
		}

		return '';
	}//end uuidFromArray()
}//end class
