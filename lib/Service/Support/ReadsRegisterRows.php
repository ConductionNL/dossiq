<?php

/**
 * Dossiq ReadsRegisterRows trait
 *
 * The raw rows of the app's register that match a filter on one id, capped
 * at the using class's `LIMIT`.
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

use OCA\Dossiq\Kernel\AppIdentity;

/**
 * Register rows through `$this->settings` and `self::LIMIT`.
 *
 * @spec exclude shared private step; each using class carries its own spec
 */
trait ReadsRegisterRows {
	/**
	 * The raw register rows matching the filters, none without OpenRegister or an id.
	 *
	 * @param string               $id      The id the filters look for; '' reads nothing.
	 * @param array<string, mixed> $filters The schema and field filters.
	 *
	 * @return array<mixed> The rows as OpenRegister answered them.
	 */
	private function registerRows(string $id, array $filters): array {
		$objectService = $this->settings->getObjectService();
		if ($objectService === null || $id === '') {
			return [];
		}

		return (array)$objectService->findAll(
			[
				'filters' => (['register' => AppIdentity::REGISTER_SLUG] + $filters),
				'limit' => self::LIMIT,
			]
		);
	}//end registerRows()
}//end trait
