<?php

/**
 * Dossiq case rebind terms.
 *
 * Re-arms the running terms of a rebound case under its new case type. Term
 * definitions are keyed by the case type SLUG, and a uuid matches none of
 * them: handing one over re-arms nothing and reports a clean zero, which is
 * the silent half of a rebind. So this class turns the case type reference
 * into its slug first, through {@see CaseTypeSlugResolver::toSlug()}, which
 * passes a slug through unchanged and refuses to guess at a uuid it cannot
 * resolve.
 *
 * Split out of {@see \OCA\Dossiq\Service\CaseRebindService}, whose constructor
 * and coupling were over their ceiling once the translated labels arrived.
 * The slug lookup and the re-arm are one step of the rebind, so they travel
 * together.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\Termijn\TermRearm;

/**
 * The re-arm of a rebound case's terms, keyed by its new case type's slug.
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
class CaseRebindTerms {
	/**
	 * Constructor.
	 *
	 * @param TermRearm            $terms The re-arm of a case's running terms.
	 * @param CaseTypeSlugResolver $slugs Case type uuid to the slug term definitions are keyed by.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function __construct(
		private readonly TermRearm $terms,
		private readonly CaseTypeSlugResolver $slugs,
	) {
	}//end __construct()

	/**
	 * Re-arm the case's running terms under the case type it now has.
	 *
	 * @param string $caseId           The case whose terms are re-armed.
	 * @param string $targetCaseTypeId The case type it was rebound onto, as a uuid or a slug.
	 * @param string $reason           Why the case was rebound, for the trail.
	 *
	 * @return array{rearmed: int, kept: int, note: string} What happened to the clocks.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function rearm(string $caseId, string $targetCaseTypeId, string $reason): array {
		return $this->terms->forDefinition(
			caseId: $caseId,
			caseTypeSlug: $this->slugs->toSlug(reference: $targetCaseTypeId),
			reason: $reason
		);
	}//end rearm()
}//end class
