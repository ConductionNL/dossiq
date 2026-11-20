<?php

/**
 * Dossiq ReadsDossierRegister trait.
 *
 * The register and schema lookups the zaakdossier document stores share. The
 * using class provides a `$dossierRegister` ({@see \OCA\Dossiq\Service\Custody\DossierRegister})
 * and a `$settingsService`.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
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
 * @spec openspec/specs/document-writes-need-every-case/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use RuntimeException;

/**
 * The object service, the register and a configured schema, or an exception.
 *
 * @spec openspec/specs/document-writes-need-every-case/spec.md
 */
trait ReadsDossierRegister {
	/**
	 * The object service and register, or an exception.
	 *
	 * @return array{0: object, 1: string} The object service and the register.
	 *
	 * @throws RuntimeException When OpenRegister or the register is not configured.
	 */
	private function requireRegister(): array {
		return $this->dossierRegister->context();
	}//end requireRegister()

	/**
	 * A configured schema slug or id, or an exception when unset.
	 *
	 * @param string $key The configuration key.
	 *
	 * @return string The schema.
	 *
	 * @throws RuntimeException When the key is not configured.
	 */
	private function schema(string $key): string {
		$schema = $this->settingsService->getConfigValue($key);
		if ($schema === '') {
			throw new RuntimeException('Dossier schema ' . $key . ' not configured');
		}

		return $schema;
	}//end schema()
}//end trait
