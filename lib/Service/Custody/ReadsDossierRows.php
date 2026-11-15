<?php

/**
 * Dossiq ReadsDossierRows trait.
 *
 * The row uuid and the register lookups the case custody stores answer the
 * services that use them. The using class provides a `$dossierRegister`
 * ({@see DossierRegister}).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Custody
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Custody;

use RuntimeException;

/**
 * A stored row's uuid, the object service and register, and a configured schema.
 *
 * @spec openspec/specs/case-management/spec.md
 */
trait ReadsDossierRows {
	/**
	 * The uuid of a stored row, however the register spelled it.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or an empty string.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function uuidOf(array $row): string {
		return trim((string)($row['id'] ?? ($row['uuid'] ?? '')));
	}//end uuidOf()

	/**
	 * The object service and the register, or an exception.
	 *
	 * @return array{0: object, 1: string} The service and the register.
	 *
	 * @throws RuntimeException When OpenRegister is absent or unconfigured.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function context(): array {
		return $this->dossierRegister->context();
	}//end context()

	/**
	 * A configured schema, or an exception naming the key.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The schema id or slug.
	 *
	 * @throws RuntimeException When the key is unset.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function schema(string $key): string {
		return $this->dossierRegister->schema(key: $key);
	}//end schema()
}//end trait
