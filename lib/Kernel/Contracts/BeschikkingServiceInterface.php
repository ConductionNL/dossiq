<?php

/**
 * Contract of BeschikkingService
 *
 * The public surface of BeschikkingService that other components call, so they depend
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
use RuntimeException;

/**
 * Contract of BeschikkingService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface BeschikkingServiceInterface {

	/**
	 * Grant mandaat-approval and transition to akkoord-mandaat. [T07]
	 *
	 * @param string $decisionId The beschikking UUID.
	 * @param string $approvedBy The approver's Nextcloud UID.
	 *
	 * @return array<string, mixed> The updated beschikking.
	 *
	 * @throws RuntimeException On a missing beschikking, invalid transition, or insufficient mandaat.
	 * @throws RefusedException When the mandate scheme could not be read, so the mandate cannot be checked.
	 *
	 * @spec openspec/changes/archive/2026-06-13-beschikking-generatie/tasks.md#T07
	 * @spec openspec/specs/quality-gates/spec.md
	 */
	public function akkoord(string $decisionId, string $approvedBy): array;

	/**
	 * Compose a new beschikking from zaakdata (status: ontwerp). [T05]
	 *
	 * @param string $caseId The case UUID.
	 * @param string|null $templateId The chosen template, or null to auto-select.
	 * @param array<string, mixed> $overrides Optional geadresseerde/field overrides.
	 *
	 * @return array<string, mixed> The created beschikking, with `_required` flags on missing fields.
	 *
	 * @throws RefusedException 503 when no number can be reserved for it.
	 *
	 * @spec openspec/changes/archive/2026-06-13-beschikking-generatie/tasks.md#T05
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 */
	public function compose(string $caseId, ?string $templateId = null, array $overrides = []): array;

	/**
	 * Assemble and PKCS#7-sign the verifiable audit-pakket ZIP. [T10]
	 *
	 * Delegates to {@see AuditPacketBuilder::build()}.
	 *
	 * @param string $decisionId The beschikking UUID.
	 *
	 * @return string The ZIP bytes.
	 *
	 * @throws RuntimeException On a missing beschikking or when ZIP support is unavailable.
	 *
	 * @spec openspec/changes/archive/2026-06-13-beschikking-generatie/tasks.md#T10
	 */
	public function exportAuditPacket(string $decisionId): string;

	/**
	 * Load a single beschikking by id. [T06]
	 *
	 * Delegates to {@see BeschikkingRepository::find()}.
	 *
	 * @param string $decisionId The beschikking UUID.
	 *
	 * @return array<string, mixed>|null
	 *
	 * @spec openspec/changes/archive/2026-06-13-beschikking-generatie/tasks.md#T06
	 */
	public function find(string $decisionId): ?array;

	/**
	 * Issue a successor to a signed beschikking.
	 *
	 * @param string               $originalId The beschikking being corrected or withdrawn.
	 * @param string               $kind       `amendment` or `withdrawal`.
	 * @param array<string, mixed> $overrides  Content of the successor: rationale, decision, addressee.
	 * @param string|null          $templateId The template; the original's when null.
	 *
	 * @return array<string, mixed> The successor, a draft with its own number.
	 *
	 * @throws RefusedException 422 for an unknown kind; 409 when the original is
	 *                          still a draft or has already been replaced.
	 * @throws \RuntimeException 'not_found' when the original does not exist.
	 *
	 * @spec openspec/specs/beschikking-generatie/spec.md
	 * @spec openspec/specs/numbered-document-series/spec.md
	 */
	public function issueSuccessor(string $originalId, string $kind, array $overrides = [], ?string $templateId = null): array;

	/**
	 * Sign the beschikking via the TSP and transition to ondertekend. [T08]
	 *
	 * @param string $decisionId The beschikking UUID.
	 * @param string $tspProvider The TSP provider slug.
	 * @param string $signatory The signer's Nextcloud UID.
	 *
	 * @return array<string, mixed> The updated beschikking.
	 *
	 * @throws RuntimeException On a missing beschikking or invalid transition.
	 *
	 * @spec openspec/changes/archive/2026-06-13-beschikking-generatie/tasks.md#T08
	 */
	public function onderteken(string $decisionId, string $tspProvider, string $signatory): array;

	/**
	 * Field-edit a beschikking, honouring the immutability contract. [T11]
	 *
	 * @param string $decisionId The beschikking UUID.
	 * @param array<string, mixed> $updates The field updates.
	 *
	 * @return array<string, mixed> The updated beschikking.
	 *
	 * @throws RuntimeException 'immutable' when the beschikking is ondertekend or later and a content field is touched.
	 *
	 * @spec openspec/changes/archive/2026-06-13-beschikking-generatie/tasks.md#T11
	 */
	public function updateFields(string $decisionId, array $updates): array;

	/**
	 * Send the beschikking to the requester and, only when a transport took it, transition to sent. [T09]
	 *
	 * Creates a BezwaarTrigger with the objection term (Awb 6:7) once the
	 * letter went out. A beschikking no transport took stays where it was: no
	 * status change, no clock, no public line. An internal line says why, and
	 * the refusal carries the transport's sentence (decision 148).
	 *
	 * @param string $decisionId The beschikking UUID.
	 * @param string $actor The dispatching user's UID.
	 *
	 * @return array<string, mixed> The updated beschikking.
	 *
	 * @throws RuntimeException On a missing beschikking or invalid transition.
	 * @throws RefusedException When no transport took the beschikking.
	 *
	 * @spec openspec/changes/archive/2026-06-13-beschikking-generatie/tasks.md#T09
	 * @spec openspec/changes/woo-requester-notices-really-go-out/specs/burger-notifications/spec.md#requirement-a-requester-notice-goes-out-through-a-real-channel-or-is-recorded-as-not-sent-req-wrn-001
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-a-case-records-the-day-its-decision-was-sent-req-cap-001
	 */
	public function verzend(string $decisionId, string $actor): array;
}//end interface
