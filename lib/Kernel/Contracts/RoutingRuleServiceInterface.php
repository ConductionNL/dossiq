<?php

/**
 * Contract of RoutingRuleService
 *
 * The public surface of RoutingRuleService that other components call, so they depend
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

use OCP\AppFramework\OCS\OCSBadRequestException;

/**
 * Contract of RoutingRuleService.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface RoutingRuleServiceInterface {

	/**
	 * Create a routing rule.
	 *
	 * @param array<string, mixed> $data The rule data.
	 *
	 * @return array<string, mixed> The saved rule.
	 *
	 * @throws OCSBadRequestException When validation fails.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-klantcontact-integratie/tasks.md#TASK-KCC-17
	 */
	public function createRule(array $data): array;

	/**
	 * Delete a routing rule.
	 *
	 * @param string $id The rule id.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-klantcontact-integratie/tasks.md#TASK-KCC-17
	 */
	public function deleteRule(string $id): void;

	/**
	 * List all routing rules.
	 *
	 * @return array<int, array<string, mixed>> The routing rules.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-klantcontact-integratie/tasks.md#TASK-KCC-17
	 */
	public function listRules(): array;

	/**
	 * Evaluate routing for a contact moment and rank candidate agents.
	 *
	 * @param array<string, mixed> $contactMoment The contact moment.
	 * @param \DateTimeImmutable|null $now Reference time.
	 *
	 * @return array<string, mixed> The routing decision plus agent suggestions.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md#requirement-routing-rules-evaluate-through-the-shared-decision-table-engine
	 */
	public function route(array $contactMoment, ?\DateTimeImmutable $now = null): array;

	/**
	 * Update a routing rule.
	 *
	 * @param string $id The rule id.
	 * @param array<string, mixed> $data The rule data.
	 *
	 * @return array<string, mixed> The saved rule.
	 *
	 * @throws OCSBadRequestException When validation fails or not found.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-klantcontact-integratie/tasks.md#TASK-KCC-17
	 */
	public function updateRule(string $id, array $data): array;
}//end interface
