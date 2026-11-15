<?php

/**
 * Contract of HouseholdCases
 *
 * The public surface of HouseholdCases that other components call, so they depend
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
 * Contract of HouseholdCases.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface HouseholdCasesInterface {

	/**
	 * The BSNs of the people in the case's family plan, the case's own requester left in.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<int, string> The BSNs, or none without a plan.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	public function householdBsns(array $case): array;

	/**
	 * Look the household up in the other domains, on a chosen ground: one
	 * logged lookup per person, and nothing but `exists`, `domain` and
	 * `contact` per domain that holds an open case.
	 *
	 * @param array<int, string> $bsns      The household's BSNs.
	 * @param string             $ownDomain The case's own domain, which is left out.
	 * @param string             $ground    The chosen ground.
	 * @param string             $requester The uid of whoever is asking.
	 *
	 * @return array<int, array{exists: bool, domain: string, contact: string}> One row per domain and contact.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md#requirement-another-domain-answers-only-that-a-case-exists-req-xdv-01
	 */
	public function otherDomains(array $bsns, string $ownDomain, string $ground, string $requester): array;

	/**
	 * The domain of a case, from its case type's category, or '' when none of the three.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return string The domain.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	public function ownDomain(array $case): string;

	/**
	 * Read the case a page shows.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>|null The case, or null when it does not resolve.
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	public function readCase(string $caseId): ?array;

	/**
	 * The household's other cases in this case's own domain (its case type
	 * category) that the reader may open.
	 *
	 * @param array<string, mixed>   $case    The case the page shows.
	 * @param array<int, string>     $bsns    The household's BSNs.
	 * @param callable(string): bool $canRead Whether the reader may open a case.
	 *
	 * @return array<int, array<string, mixed>> One row per case: `caseId`,
	 *         `identifier`, `title`, `caseTypeTitle`, `statusLabel`,
	 *         `statusRole`, `access` (`full`).
	 *
	 * @spec openspec/specs/dossiq-sociaal-domein-avg-consent/spec.md
	 */
	public function sameDomain(array $case, array $bsns, callable $canRead): array;
}//end interface
