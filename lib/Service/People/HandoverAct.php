<?php

/**
 * One leaver handover: who left, who receives the work, who ran it, and the batch and moment it ran under.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\People
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\People;

/**
 * One leaver handover: who left, who receives the work, who ran it, and the batch and moment it ran under.
 *
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
 */
final class HandoverAct {

	/**
	 * Constructor.
	 *
	 * @param string $fromUser The person who is leaving.
	 * @param string $toUser   The person receiving the work.
	 * @param string $actor    The administrator running the act.
	 * @param string $batchId  The handover's batch id.
	 * @param string $now      The moment of the act.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-everything-a-leaver-holds-moves-in-one-act-req-hand-07
	 */
	public function __construct(
		public readonly string $fromUser,
		public readonly string $toUser,
		public readonly string $actor,
		public readonly string $batchId,
		public readonly string $now,
	) {
	}//end __construct()
}//end class
