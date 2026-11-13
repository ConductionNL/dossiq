<?php

/**
 * Contract of WooPublicationService
 *
 * The public surface of WooPublicationService that other components call, so they depend
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

use RuntimeException;

/**
 * Contract of WooPublicationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WooPublicationServiceInterface {

	/**
	 * Publish (or republish) a WOO decision to OpenCatalogi.
	 *
	 * Idempotent per decision: republishing an already-published decision
	 * updates the existing OpenCatalogi publication rather than creating a
	 * duplicate (see design.md D6). Without a decision id the case's one Woo
	 * decision is used (design D-1). Afterwards the case reads `published`
	 * with the link, and a request started from a dossier gets the
	 * publication back in that dossier (D-7).
	 *
	 * @param string $caseId The case UUID.
	 * @param string $decisionId The decision UUID, or '' to use the case's Woo decision.
	 *
	 * @return array<string, mixed> `{available: bool, reason?: string, decisionIds?, publicationId?, publicationUrl?}`.
	 *
	 * @throws RuntimeException When the decision or case cannot be loaded.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publish-endpoints-find-the-cases-woo-decision-req-wpi-005
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-a-decision-comes-back-to-the-dossier-it-was-asked-from-req-wpi-008
	 */
	public function publish(string $caseId, string $decisionId = ''): array;

	/**
	 * Withdraw (depublish) a previously published WOO decision.
	 *
	 * @param string $decisionId The decision UUID, or '' to use the case's Woo decision.
	 * @param string $caseId The case UUID; needed when no decision id is given.
	 *
	 * @return array<string, mixed> `{available: bool, reason?: string}`.
	 *
	 * @throws RuntimeException When the decision cannot be loaded.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publish-endpoints-find-the-cases-woo-decision-req-wpi-005
	 */
	public function withdraw(string $decisionId, string $caseId = ''): array;
}//end interface
