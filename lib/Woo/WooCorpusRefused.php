<?php

/**
 * Dossiq Woo corpus refused
 *
 * A corpus step that cannot happen: no recorded search plan, an incomplete
 * plan, a pick without a plan custodian, a document that is already assessed.
 * The reason code is the message; the status is what the endpoint answers.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use RuntimeException;

/**
 * A refused corpus step with the status to answer.
 *
 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
 */
class WooCorpusRefused extends RuntimeException {

	/**
	 * The refusal for a case without a recorded plan.
	 */
	public const NO_PLAN = 'no_search_plan';

	/**
	 * Constructor.
	 *
	 * @param string $reason The reason code; also the message.
	 * @param int    $status The HTTP status the endpoint answers.
	 */
	public function __construct(string $reason, private readonly int $status = 409) {
		parent::__construct(message: $reason);
	}//end __construct()

	/**
	 * The HTTP status to answer.
	 *
	 * @return int The status.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
	 */
	public function getStatus(): int {
		return $this->status;
	}//end getStatus()
}//end class
