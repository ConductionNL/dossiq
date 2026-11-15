<?php

/**
 * Dossiq DelegatedDecisionRequest.
 *
 * One decision this app asks the decision app to take: its type, the
 * case or subject reference it is raised under, the subject, the actor and
 * the decision body.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * A decision request while ContractDecisionDelegationService raises it.
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */
final class DelegatedDecisionRequest {
	/**
	 * The decision type slug.
	 *
	 * @var string
	 */
	public readonly string $decisionType;

	/**
	 * The ZGW case/subject reference, also the correlation id.
	 *
	 * @var string
	 */
	public readonly string $externalReference;

	/**
	 * Subject fields: subjectRegister, subjectSchema, subjectId, subjectLabel.
	 *
	 * @var array<string, string>
	 */
	public array $subject = [];

	/**
	 * The requesting actor id (may be empty).
	 *
	 * @var string
	 */
	public string $actorId = '';

	/**
	 * The decision body (title, context, ...).
	 *
	 * @var array<string, mixed>
	 */
	public array $payload = [];

	/**
	 * Start a request of a type under a reference.
	 *
	 * @param string $decisionType      The decision type slug.
	 * @param string $externalReference The ZGW case/subject reference.
	 */
	public function __construct(string $decisionType, string $externalReference) {
		$this->decisionType = $decisionType;
		$this->externalReference = $externalReference;
	}//end __construct()
}//end class
