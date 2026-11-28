<?php

/**
 * Contract of StufIntegriqBridge
 *
 * The public surface of StufIntegriqBridge that other components call, so they depend
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

use OCA\Dossiq\Service\Stuf\StufException;

/**
 * Contract of StufIntegriqBridge.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface StufIntegriqBridgeInterface {

	/**
	 * Ask integriq to send a vrij bericht on the endpoint's integriq source.
	 *
	 * @param array  $endpoint The stufEndpoint row.
	 * @param string $name     The template name.
	 * @param array  $payload  The template fields.
	 *
	 * @return array<string, mixed> Integriq's result: success, retrying, referentienummer, messageId, caseIdentification, fout.
	 *
	 * @throws StufException 400 when the endpoint names no integriq source or integriq refuses; 503 when integriq does not answer.
	 *
	 * @spec openspec/specs/stuf-zkn-outbound/spec.md#requirement-outbound-stuf-goes-through-integriq
	 */
	public function vrijBericht(array $endpoint, string $name, array $payload): array;
}//end interface
