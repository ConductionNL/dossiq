<?php

/**
 * Contract of RecallSamples
 *
 * The public surface of RecallSamples that other components call, so they depend
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

use OCA\Dossiq\Exception\RefusedException;

/**
 * Contract of RecallSamples.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RecallSamplesInterface {

	/**
	 * Draw a sample from the case's null set, store it and hand it to a reviewer as a sample batch.
	 *
	 * @param string $caseId The case UUID.
	 * @param int $size The sample size.
	 * @param string $assignee The reviewer of the sample.
	 * @param string $userId Who draws it.
	 * @param int|null $seed The seed, or null for a fresh one.
	 *
	 * @return array<string, mixed> The stored sample.
	 *
	 * @throws RefusedException When the size does not fit the null set, or the sample cannot be stored.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
	 */
	public function draw(string $caseId, int $size, string $assignee, string $userId, ?int $seed = null): array;

	/**
	 * The case's samples, newest first, each with its progress.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return list<array<string, mixed>> The samples.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
	 */
	public function forCase(string $caseId): array;

	/**
	 * Judge one sampled document in or out of scope, in the sample only; estimate when it was the last.
	 *
	 * @param string $caseId The case UUID.
	 * @param string $sampleId The recall sample.
	 * @param string $documentRef The sampled document.
	 * @param bool $inScope Whether the document is about the request after all.
	 * @param string $userId Who judges.
	 *
	 * @return array<string, mixed> The sample, with its progress and, once complete, its estimate.
	 *
	 * @throws RefusedException When the sample or document is unknown, the sample is closed, or it cannot be stored.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
	 */
	public function judge(string $caseId, string $sampleId, string $documentRef, bool $inScope, string $userId): array;

	/**
	 * A sample with `progress` {judged, total}; an incomplete sample carries no estimate.
	 *
	 * @param array<string, mixed> $sample The sample.
	 *
	 * @return array<string, mixed> The sample with its progress.
	 *
	 * @spec openspec/changes/woo-review-triage/specs/document-review/spec.md#requirement-the-recall-is-estimated-from-an-elusion-sample-with-its-uncertainty-req-wrs-002
	 */
	public function withProgress(array $sample): array;
}//end interface
