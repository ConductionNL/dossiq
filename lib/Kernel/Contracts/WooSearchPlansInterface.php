<?php

/**
 * Contract of WooSearchPlans
 *
 * The public surface of WooSearchPlans that other components call, so they depend
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

use OCA\Dossiq\Woo\WooCorpusRefused;

/**
 * Contract of WooSearchPlans.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface WooSearchPlansInterface {

	/**
	 * The names of a plan's custodians.
	 *
	 * @param array<string, mixed> $plan The plan row.
	 *
	 * @return array<int, string> The names.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-the-collection-is-reported-per-custodian-and-system-req-wrc-002
	 */
	public function custodianNames(array $plan): array;

	/**
	 * The case's plan, draft or recorded, or null: the plan as the caller may read it.
	 *
	 * A plan is private to the people on its case, so this is the read for
	 * what a person is shown. A rule asks planOfCase() instead.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>|null The plan row.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
	 */
	public function planOf(string $caseId): ?array;

	/**
	 * Record the case's plan, and keep its configuration in step.
	 *
	 * A plan the handler filled from a named template says so in `startedFrom`.
	 * The configuration then takes every key of that template and names it in
	 * `copiedFrom`; the plan's own custodians, systems and terms still win.
	 *
	 * @param string               $caseId      The case uuid.
	 * @param array<string, mixed> $input       `{custodians, systems, periodFrom, periodTo, terms}`.
	 * @param string               $userId      Who records it.
	 * @param string               $startedFrom The uuid of the template the plan was filled from, or ''.
	 *
	 * @return array<string, mixed> The plan as stored.
	 *
	 * @throws WooCorpusRefused When the plan is incomplete, or names a template the caller cannot use.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
	 */
	public function record(string $caseId, array $input, string $userId, string $startedFrom=''): array;

	/**
	 * The case's recorded plan, or null while it has none or only a draft.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>|null The plan row.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-search-plan-is-recorded-before-collection-req-wrc-001
	 */
	public function recorded(string $caseId): ?array;

	/**
	 * Start a case from an earlier case's configuration, or from a named template of the
	 * configuration schema: copy every key, and a draft plan without the period.
	 *
	 * @param string $caseId The new case uuid.
	 * @param string $from   The earlier case uuid, or the uuid of a named template.
	 *
	 * @return array<string, mixed>|null The new configuration, null when `$from` names neither a case
	 *                                   with a configuration nor a template the caller may use.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-new-request-starts-from-the-configuration-of-an-earlier-one-req-wrc-005
	 */
	public function startFrom(string $caseId, string $from): ?array;

	/**
	 * The schema id named templates of the request configuration are kept under in OpenRegister.
	 *
	 * @return string The configured schema id, '' when the schema is not configured.
	 *
	 * @spec openspec/changes/woo-request-corpus-collection/specs/woo-case-type/spec.md#requirement-a-new-request-starts-from-the-configuration-of-an-earlier-one-req-wrc-005
	 */
	public function templateSchema(): string;
}//end interface
