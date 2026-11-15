<?php

/**
 * Contract of CaseAttentionMarkerService
 *
 * The public surface of CaseAttentionMarkerService that other components call, so they depend
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

use DateTimeImmutable;

/**
 * Contract of CaseAttentionMarkerService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseAttentionMarkerServiceInterface {

	/**
	 * The related rows the conditions read, for one case.
	 *
	 * Pass-through to {@see CaseAttentionConditionService::contextFor()}, so a
	 * caller that has this service has everything it needs to derive a marker
	 * set and does not have to know which class fetches what.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, array<int, array<string, mixed>>> The context.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function contextFor(string $caseId): array;

	/**
	 * The marker fields this save implies.
	 *
	 * @param array<string, mixed>   $case    The case being saved.
	 * @param DateTimeImmutable|null $now     Today.
	 * @param array<string, mixed>   $context The related rows, from `contextFor()`.
	 *
	 * @return array{attentionMarkers: array<int, array<string, mixed>>,
	 *               hasAttentionMarkers: bool} The fields to write back.
	 *
	 * @spec openspec/specs/case-management/spec.md
	 */
	public function resolve(array $case, ?DateTimeImmutable $now = null, array $context = []): array;
}//end interface
