<?php

/**
 * One change of hands on the custody chain of a case.
 *
 * The case, the unit and person taking it, the reason, who made the move and
 * the moment it happened, carried as one value from the caller to the chain so
 * a move and the first holding of a backfill build their row the same way.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Custody
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
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Custody;

/**
 * A case moving into a holding, as the caller gave it (untrimmed).
 *
 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
 */
final class CustodyMove {
	/**
	 * Constructor.
	 *
	 * @param string $caseId           The case that is moving.
	 * @param string $organisationUnit The unit taking the case.
	 * @param string $handler          The person on the seat, or an empty string when nobody is named.
	 * @param string $reason           Why the case is moving.
	 * @param string $movedBy          Who made the move.
	 * @param string $at               The moment of the move in ISO 8601, or an empty string for now.
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $organisationUnit,
		public readonly string $handler,
		public readonly string $reason,
		public readonly string $movedBy,
		public readonly string $at = '',
	) {
	}//end __construct()

	/**
	 * The open holding this move writes, every text value trimmed.
	 *
	 * @param string $from     The moment the holding begins, in ISO 8601.
	 * @param int    $sequence Its place on the chain, counting from one.
	 *
	 * @return array<string, mixed> The holding row.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-case-ownership-is-a-dated-chain-of-holdings-req-cus-01
	 */
	public function openHolding(string $from, int $sequence): array {
		return [
			'caseId' => trim($this->caseId),
			'organisationUnit' => trim($this->organisationUnit),
			'handler' => trim($this->handler),
			'from' => $from,
			'until' => '',
			'open' => true,
			'reason' => trim($this->reason),
			'movedBy' => trim($this->movedBy),
			'sequence' => $sequence,
		];
	}//end openHolding()
}//end class
