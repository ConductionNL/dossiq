<?php

/**
 * Dossiq RecordsOrRefuses trait
 *
 * Stores a record through the using class's own `store()` step and turns a
 * failed or unreadable write into the class's own `unrecorded()` refusal.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude shared private step; each using class carries its own spec
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Support;

use OCA\Dossiq\Exception\RefusedException;
use Throwable;

/**
 * Stores a record or refuses through the using class's `store()` and `unrecorded()`.
 *
 * @spec exclude shared private step; each using class carries its own spec
 */
trait RecordsOrRefuses {
	/**
	 * The stored record, or the class's refusal when the write failed or read nothing back.
	 *
	 * @param array<string, mixed> $record The record, without its metadata.
	 * @param string|null          $uuid   The uuid to update, or null to create.
	 *
	 * @return array<string, mixed> The stored record.
	 *
	 * @throws RefusedException When it could not be stored.
	 */
	private function storedOrRefused(array $record, ?string $uuid): array {
		try {
			$saved = $this->store(record: $record, uuid: $uuid);
		} catch (Throwable $e) {
			throw $this->unrecorded(previous: $e);
		}

		if ($saved === null) {
			throw $this->unrecorded(previous: null);
		}

		return $saved;
	}//end storedOrRefused()
}//end trait
