<?php

/**
 * Contract of AdviceDelegationService
 *
 * The public surface of AdviceDelegationService that other components call, so they depend
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
 * Contract of AdviceDelegationService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface AdviceDelegationServiceInterface {

	/**
	 * Raise a decidesk `advice` Decision for a BAC / adviesAanvraag / consultatie request.
	 *
	 * The caller MUST have run its dossiq domain rule (BAC panel-independence,
	 * the advice IDOR gate) BEFORE invoking this. FAILS CLOSED when the
	 * decidesk leaf is unavailable.
	 *
	 * @param string $subjectSchema The dossiq subject schema (bacAdviceRequest, adviesAanvraag, consultation).
	 * @param string $subjectId The subject object UUID.
	 * @param array<string,mixed> $payload Advice context + provenance: subjectRegister,
	 *                                     subjectLabel, externalReference, question,
	 *                                     adviceType, etc.
	 *
	 * @return string The decidesk decisionRef (UUID) to persist on the case.
	 *
	 * @throws \RuntimeException When the decidesk leaf is unavailable or the Decision could not be created.
	 *
	 * @spec openspec/specs/remaining-decision-delegation/spec.md
	 * @spec openspec/specs/remaining-decision-delegation/spec.md#requirement-req-pdrd-002-delegation-fails-closed-when-decidesk-is-unavailable
	 */
	public function raiseAdviceDecision(string $subjectSchema, string $subjectId, array $payload = []): string;
}//end interface
