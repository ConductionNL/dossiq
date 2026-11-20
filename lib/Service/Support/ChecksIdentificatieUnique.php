<?php

/**
 * Dossiq ChecksIdentificatieUnique trait
 *
 * Whether a ZGW body's identificatie is unique within its bronorganisatie.
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

/**
 * Whether a ZGW body's identificatie is unique within its bronorganisatie.
 *
 * @spec exclude shared private step; each using class carries its own spec
 */
trait ChecksIdentificatieUnique {
	/**
	 * Drc-008: a given identificatie is unique together with the bronorganisatie.
	 *
	 * @param array $body The request body
	 *
	 * @return array|null The refusal, or null when unique or not given
	 */
	private function checkIdentificatieUnique(array $body): ?array {
		if (empty($body['identificatie']) === true) {
			return null;
		}

		return $this->checkFieldUniqueness(
			field1Value: $body['identificatie'],
			field1Search: 'identifier',
			field2Value: $body['bronorganisatie'] ?? '',
			field2Search: 'sourceOrganisation',
			errorField: 'identificatie'
		);
	}//end checkIdentificatieUnique()
}//end trait
