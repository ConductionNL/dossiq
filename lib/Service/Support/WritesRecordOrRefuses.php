<?php

/**
 * Writing one record to its register, or refusing when it was not recorded.
 *
 * The using class saves through its own `store(record, uuid)` step and names
 * its own refusal through `unrecorded(previous)`; this trait gives the write
 * that strips the record's metadata, saves it and refuses on a failure or an
 * empty answer.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use OCA\Dossiq\Exception\RefusedException;
use Throwable;

/**
 * Store a record, new or existing, or refuse.
 *
 * @spec exclude shared register write; each using store carries its own spec
 */
trait WritesRecordOrRefuses {

	/**
	 * Store a record, new or existing.
	 *
	 * @param array<string, mixed> $record The record.
	 * @param string|null          $uuid   The uuid to update, or null to create.
	 *
	 * @return array<string, mixed> The stored record.
	 *
	 * @throws RefusedException When it could not be stored.
	 */
	private function writeOrRefuse(array $record, ?string $uuid): array {
		unset($record['@self'], $record['id'], $record['uuid']);

		try {
			$saved = $this->store(record: $record, uuid: $uuid);
		} catch (Throwable $e) {
			throw $this->unrecorded(previous: $e);
		}

		if ($saved === null) {
			throw $this->unrecorded(previous: null);
		}

		return $saved;
	}//end writeOrRefuse()
}//end trait
