<?php

/**
 * The cases a substitute covers today, for the personal work queue
 *
 * "Assigned to me" can include the work of colleagues the reader stands in
 * for (board DqAanMijToegewezen, "Inclusief werk waarvoor u waarneemt"). The
 * active substitutions route an absentee's open cases to the substitute
 * ({@see SubstitutionService::getSubstitutedWorkFor()}); this keeps the ones
 * the substitute may read and says, per case, whom it is covered for.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Substitution
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Substitution;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\SubstitutionService;
use OCP\IUser;
use OCP\IUserManager;

/**
 * Lists the covered cases a substitute may read, each marked with its absentee.
 *
 * 🔴 COVERING NEVER WIDENS ACCESS. The substitution search already runs in the
 * substitute's own permissions; every case is asked of the case read guard as
 * well, so a case the substitute could not open from its own page is never
 * listed for them.
 *
 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
 */
class CoveredWork {

	/**
	 * Constructor.
	 *
	 * @param SubstitutionService $substitutions   The active substitutions and the work they route.
	 * @param CaseAccessGuard     $caseAccessGuard Per-case read authorization.
	 * @param IUserManager        $userManager     The absentee's display name.
	 */
	public function __construct(
		private readonly SubstitutionService $substitutions,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * The open cases this user covers on a day, each with `coveredFor`.
	 *
	 * @param IUser                  $user The substitute.
	 * @param DateTimeImmutable|null $date The day; today when null.
	 *
	 * @return array<int, array<string, mixed>> The open cases, each with `coveredFor: {absentee, name, until}`.
	 *
	 * @spec openspec/specs/handler-vervanging-waarneming/spec.md
	 */
	public function casesFor(IUser $user, ?DateTimeImmutable $date = null): array {
		$work = $this->substitutions->getSubstitutedWorkFor(userId: $user->getUID(), date: $date);

		$cases = [];
		foreach (($work['cases'] ?? []) as $case) {
			$caseId = (string)($case['id'] ?? ($case['uuid'] ?? ''));
			if ($caseId === '' || $this->isOpenInLists(case: $case) === false
				|| $this->caseAccessGuard->hasCaseReadAccess(caseId: $caseId, user: $user) === false
			) {
				continue;
			}

			$absentee = (string)($case['_substituted']['absentee'] ?? '');
			$case['coveredFor'] = [
				'absentee' => $absentee,
				'name' => ($this->userManager->getDisplayName($absentee) ?? $absentee),
				'until' => (string)($case['_substituted']['until'] ?? ''),
			];
			$cases[] = $case;
		}

		return $cases;
	}//end casesFor()

	/**
	 * Whether a case is in the lists the work queue ranks: not closed, not a
	 * draft, and not in a status hidden from lists.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return bool
	 */
	private function isOpenInLists(array $case): bool {
		return (string)($case['endDate'] ?? '') === ''
			&& ($case['isDraft'] ?? false) !== true
			&& ($case['statusHiddenInLists'] ?? false) !== true;
	}//end isOpenInLists()
}//end class
