<?php

/**
 * Dossiq ForfeitInvoiceNotRaisedException.
 *
 * A forfeited penalty that should raise an invoice raised none, because the
 * case could not be read or the finance app refused the request. The caller
 * decides what that means for the act.
 *
 * @category Exception
 * @package  OCA\Dossiq\Exception
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-005)
 */

declare(strict_types=1);

namespace OCA\Dossiq\Exception;

use RuntimeException;
use Throwable;

/**
 * No invoice was raised. The code says why: `store-unreadable` or `finance-refused`.
 *
 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-005)
 */
class ForfeitInvoiceNotRaisedException extends RuntimeException {

	/**
	 * The store holding the case or its case type could not be read.
	 *
	 * @var string
	 */
	public const STORE_UNREADABLE = 'store-unreadable';

	/**
	 * The finance app refused the payment request.
	 *
	 * @var string
	 */
	public const FINANCE_REFUSED = 'finance-refused';

	/**
	 * Constructor.
	 *
	 * @param string         $reasonCode Why nothing was raised.
	 * @param string         $caseId     The case of the forfeited act.
	 * @param Throwable|null $previous   The failure underneath.
	 */
	public function __construct(
		private readonly string $reasonCode,
		private readonly string $caseId,
		?Throwable $previous = null,
	) {
		parent::__construct(message: 'No invoice was raised for the forfeited penalty: ' . $reasonCode, previous: $previous);
	}//end __construct()

	/**
	 * Why nothing was raised.
	 *
	 * @return string The code.
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-005)
	 */
	public function getReasonCode(): string {
		return $this->reasonCode;
	}//end getReasonCode()

	/**
	 * The case of the forfeited act.
	 *
	 * @return string The case id.
	 *
	 * @spec openspec/changes/case-money-is-configuration/specs/case-money-is-configuration/spec.md (REQ-DQM-005)
	 */
	public function getCaseId(): string {
		return $this->caseId;
	}//end getCaseId()
}//end class
