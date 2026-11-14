<?php

/**
 * An acceptance or refusal of one pending handover.
 *
 * Built by `InternalHandover::accept()` and `refuse()` and handed through the settle steps: the
 * handover, the status it settles to, who settled it and, on a refusal, why.
 *
 * @category Transfer
 * @package  OCA\Dossiq\Service\Transfer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Transfer;

/**
 * An acceptance or refusal of one pending handover.
 *
 * @spec openspec/specs/case-management/spec.md#requirement-the-receiving-team-can-refuse-a-handover-back-req-hand-02
 */
final class HandoverSettlement {

	/**
	 * Constructor.
	 *
	 * @param string $transferId The handover's uuid.
	 * @param string $status     'accepted' or 'rejected'.
	 * @param string $actor      Who settled it.
	 * @param string $reason     The refusal's reason, empty on an acceptance.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly string $transferId,
		public readonly string $status,
		public readonly string $actor,
		public readonly string $reason,
	) {
	}//end __construct()
}//end class
