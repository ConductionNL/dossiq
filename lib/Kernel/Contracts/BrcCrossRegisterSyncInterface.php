<?php

/**
 * Contract of BrcCrossRegisterSync
 *
 * The public surface of BrcCrossRegisterSync that other components call, so they depend
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
 * Contract of BrcCrossRegisterSync.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BrcCrossRegisterSyncInterface {

	/**
	 * Delete ObjectInformatieObjecten from DRC for a given besluit (brc-005b/009).
	 *
	 * @param string $decisionUrl The besluit URL to match OIOs against
	 *
	 * @return void
	 * @SuppressWarnings(PHPMD.StaticAccess) ZgwSearchScope::fromMapping() is a named
	 *  constructor on a value object, not a service call. Injecting it would put a
	 *  collaborator in four controllers to answer one question about their own config.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function deleteOiosForDecision(string $decisionUrl): void;

	/**
	 * Sync a zaak-besluit relation to ZRC (brc-006).
	 *
	 * Creates a "zaakbesluit" record linking the zaak to the besluit
	 * in the ZRC register.
	 *
	 * @param string $caseUrl The zaak URL
	 * @param string $decisionUrl The besluit URL
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function syncCaseDecisionToZrc(string $caseUrl, string $decisionUrl): void;

	/**
	 * Create an ObjectInformatieObject in the DRC register (brc-005a).
	 *
	 * @param string $decisionUrl The besluit URL (full ZGW URL)
	 * @param string $ioUrl The informatieobject URL (full ZGW URL)
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function createOioInDrc(string $decisionUrl, string $ioUrl): void;

	/**
	 * Delete an OIO from DRC matching a specific besluit and informatieobject (brc-005b).
	 *
	 * @param string $decisionUrl The besluit URL
	 * @param string $ioUrl The informatieobject URL
	 *
	 * @return void
	 * @SuppressWarnings(PHPMD.StaticAccess) ZgwSearchScope::fromMapping() is a named
	 *  constructor on a value object, not a service call. Injecting it would put a
	 *  collaborator in four controllers to answer one question about their own config.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function deleteOioByDecisionAndIo(string $decisionUrl, string $ioUrl): void;
}//end interface
