<?php

/**
 * Contract of PortalSubmissionLedger
 *
 * The public surface of PortalSubmissionLedger that other components call, so they depend
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
 * Contract of PortalSubmissionLedger.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface PortalSubmissionLedgerInterface {

	/**
	 * The header the key arrives in.
	 */
	public const HEADER = 'Idempotency-Key';

	/**
	 * Claim a key for a first send.
	 *
	 * @param string $scope      The action, e.g. `woo-verzoek`.
	 * @param string $subjectRef The resident, from the verified assertion.
	 * @param string $key        The submission key.
	 *
	 * @return array{state: string, answer?: array<string, mixed>} `claimed` for a first send,
	 *                                                              `processing` while the first is busy,
	 *                                                              `answered` with the first one's answer.
	 *
	 * @spec openspec/changes/portal-submission-is-made-once/specs/portal-contribution/spec.md#requirement-a-repeated-portal-submission-returns-the-first-ones-answer-req-psm-001
	 */
	public function claim(string $scope, string $subjectRef, string $key): array;

	/**
	 * The key a request carries, or '' when it carries none or one that is not a plain token.
	 *
	 * @param string $header The header's value.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/portal-submission-is-made-once/specs/portal-contribution/spec.md#requirement-a-repeated-portal-submission-returns-the-first-ones-answer-req-psm-001
	 */
	public function keyOf(string $header): string;

	/**
	 * Let the key go after a send that made nothing, so a corrected resend is a first send.
	 *
	 * @param string $scope      The action.
	 * @param string $subjectRef The resident.
	 * @param string $key        The submission key.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-submission-is-made-once/specs/portal-contribution/spec.md#requirement-a-repeated-portal-submission-returns-the-first-ones-answer-req-psm-001
	 */
	public function release(string $scope, string $subjectRef, string $key): void;

	/**
	 * Keep the first send's answer for a resend with the same key.
	 *
	 * @param string               $scope      The action.
	 * @param string               $subjectRef The resident.
	 * @param string               $key        The submission key.
	 * @param array<string, mixed> $answer     What the first send answered.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/portal-submission-is-made-once/specs/portal-contribution/spec.md#requirement-a-repeated-portal-submission-returns-the-first-ones-answer-req-psm-001
	 */
	public function remember(string $scope, string $subjectRef, string $key, array $answer): void;
}//end interface
