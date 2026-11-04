<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Support;

/**
 * A terminal delivery outcome on its way onto the case it is about.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
final class ConcludedDelivery {

	/**
	 * Constructor.
	 *
	 * @param string                                                                         $caseId        The case.
	 * @param string                                                                         $correlationId The delivery's correlation id.
	 * @param string                                                                         $channel       The channel it travelled on.
	 * @param array{status: string, attempts: int, error: string|null, concludedAt: string} $outcome       What the publication's delivery records.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $correlationId,
		public readonly string $channel,
		public readonly array $outcome,
	) {
	}//end __construct()
}//end class
