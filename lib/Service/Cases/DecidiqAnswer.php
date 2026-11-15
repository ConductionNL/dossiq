<?php

/**
 * The decision a gate waits on: the declared gate, decidiq's decision id, and who decidiq named.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
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
 * @spec openspec/specs/besluitvorming-leaf/spec.md#requirement-a-case-is-gated-by-the-approval-outcome-decidiq-walks-req-dec-01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

/**
 * The decision a gate waits on: the declared gate, decidiq's decision id, and who decidiq named.
 *
 * @spec openspec/specs/besluitvorming-leaf/spec.md#requirement-a-case-is-gated-by-the-approval-outcome-decidiq-walks-req-dec-01
 */
final class DecidiqAnswer {

	/**
	 * Constructor.
	 *
	 * @param array{act: string, decisionType: string, label: string} $gate      The declared gate.
	 * @param string                                                  $reference The decidiq decision id, '' when there is none.
	 * @param array<int, string>                                      $approvers Who it waits on, when decidiq named them.
	 *
	 * @spec openspec/specs/besluitvorming-leaf/spec.md#requirement-a-case-is-gated-by-the-approval-outcome-decidiq-walks-req-dec-01
	 */
	public function __construct(
		public readonly array $gate,
		public readonly string $reference,
		public readonly array $approvers,
	) {
	}//end __construct()
}//end class
