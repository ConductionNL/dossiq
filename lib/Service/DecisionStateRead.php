<?php

/**
 * Dossiq DecisionStateRead.
 *
 * One read-back of a decision this app raised: the decision id, the uid the
 * read is scoped to, and the event class that carries the read.
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
 * A decision-state read while ContractDecisionDelegationService makes it.
 *
 * @spec openspec/specs/contract-decision-delegation/spec.md
 */
final class DecisionStateRead {
	/**
	 * The decidiq decision id, trimmed.
	 *
	 * @var string
	 */
	public readonly string $decisionId;

	/**
	 * The Nextcloud uid the read is scoped to, trimmed.
	 *
	 * @var string
	 */
	public readonly string $actorId;

	/**
	 * The installed read event class, or null when the seam is absent.
	 *
	 * @var string|null
	 */
	public ?string $eventClass = null;

	/**
	 * Start a read of a decision as a uid.
	 *
	 * @param string $decisionId The decidiq decision id, trimmed.
	 * @param string $actorId    The uid, trimmed.
	 */
	public function __construct(string $decisionId, string $actorId) {
		$this->decisionId = $decisionId;
		$this->actorId = $actorId;
	}//end __construct()
}//end class
