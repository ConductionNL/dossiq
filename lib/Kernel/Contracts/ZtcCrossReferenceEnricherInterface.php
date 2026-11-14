<?php

/**
 * Contract of ZtcCrossReferenceEnricher
 *
 * The public surface of ZtcCrossReferenceEnricher that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of ZtcCrossReferenceEnricher.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ZtcCrossReferenceEnricherInterface {

	/**
	 * Fill a zaaktype's or besluittype's cross-reference lists.
	 *
	 * @param string $resource The ZGW resource (`zaaktypen`, `besluittypen`; others pass through)
	 * @param array $data The outbound-mapped object
	 * @param string $baseUrl The Catalogi API base URL
	 *
	 * @return array The enriched object
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function enrich(string $resource, array $data, string $baseUrl): array;
}//end interface
