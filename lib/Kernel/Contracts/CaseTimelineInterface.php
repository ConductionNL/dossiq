<?php

/**
 * Contract of CaseTimeline
 *
 * The public surface of CaseTimeline that other components call, so they depend
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

use Throwable;

/**
 * Contract of CaseTimeline.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface CaseTimelineInterface {

	/**
	 * An entry only the handling organisation reads.
	 *
	 * @var string
	 */
	public const INTERNAL = 'internal';

	/**
	 * An entry the applicant may read too.
	 *
	 * @var string
	 */
	public const PUBLIC_ENTRY = 'public';

	/**
	 * The entries on a case that the applicant may read.
	 *
	 * ONE READER FOR BOTH OUTSIDE SURFACES. The portal contribution and the
	 * public status page ask the same question, and a second reader beside
	 * this one is how the two would come to disagree: the disagreement would
	 * be an internal note on a citizen's screen, and it would look like a
	 * working page right up until someone read it.
	 *
	 * THE FILTER IS THE READER'S, NOT THE CALLER'S. `publicEntries()` takes no
	 * visibility argument, so no caller can ask it for the internal ones. A
	 * surface that wants the whole feed reads OpenRegister's own endpoint with
	 * a signed-in user behind it, which is where the access check belongs.
	 *
	 * THE READ RUNS UNSCOPED, AND THE CALLER IS WHAT MAKES THAT SAFE. A portal
	 * request carries no Nextcloud user, so a scoped read of the case found
	 * nothing and every resident saw an empty timeline. The public status page
	 * reads OpenRegister's case-token endpoint and does not call this method
	 * today, so the one caller is
	 * {@see \OCA\Dossiq\Portal\PortalContributionProvider::caseTimeline()},
	 * which portaliq calls only after its scoped read proved the case is the
	 * resident's (`mijnZaken`, scope `portalSubject`); a foreign or absent case
	 * is a 404 there and this method is never asked. The read is pinned to the
	 * configured register and case schema, and answers only the public entries
	 * of that one case, the same posture as
	 * {@see \OCA\Dossiq\Portal\PortalCaseDocuments::forCase()}. A new caller
	 * must prove the case is its reader's before it asks.
	 *
	 * THE AUTHOR DOES NOT TRAVEL. A handler's user id is not part of what
	 * happened on the case as far as the applicant is concerned, and a public
	 * projection is the last place to hand one out.
	 *
	 * IT ANSWERS THE EMPTY LIST ONLY WHEN IT KNOWS THE LIST IS EMPTY: no case
	 * was asked for, OpenRegister or its reader is absent, the register is
	 * unconfigured, or the case is not there. Each of those is a fact the
	 * method establishes, not a failure it hides. A read that THROWS is a
	 * different fact, so it is logged at warning naming the case and then
	 * travels on. Unlike {@see self::record()}, which softens because an entry
	 * records something that already happened, a reader has nothing to protect
	 * by lying about what it found.
	 *
	 * @param string  $caseId The case to read.
	 * @param integer $limit  How many entries at most, newest first.
	 *
	 * @return array<int, array<string, mixed>> The public entries.
	 *
	 * @throws Throwable When the timeline could not be read at all.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md
	 */
	public function publicEntries(string $caseId, int $limit = 50): array;

	/**
	 * Record one entry on a case, and optionally on its related cases.
	 *
	 * @param string               $caseId         The case the entry hangs on.
	 * @param string               $kind           The declared kind, e.g. `contactmoment`.
	 * @param string               $message        The text a handler reads.
	 * @param array<string, mixed> $fields         The values the kind declares. Anything else is dropped by OpenRegister.
	 * @param string               $visibility     `internal` or `public`.
	 * @param array<int, string>   $relatedCaseIds Further cases the same entry belongs on.
	 *
	 * @return string The entry uuid, or '' when nothing was written.
	 *
	 * @spec openspec/specs/case-history-surface/spec.md
	 */
	public function record(
		string $caseId,
		string $kind,
		string $message,
		array $fields = [],
		string $visibility = self::INTERNAL,
		array $relatedCaseIds = [],
	): string;
}//end interface
