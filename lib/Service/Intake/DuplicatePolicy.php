<?php

/**
 * Dossiq duplicate policy at intake.
 *
 * What a case type does when the case being filed looks like one that already
 * exists. The matching is not here and never will be: OpenRegister's
 * `x-openregister-dedup` block on the `case` schema says what counts as the
 * same case, its DuplicateDetectionService scores it RBAC- and tenant-scoped,
 * and this class reads the answer (ADR-022). What is dossiq's is the ACT:
 * whether a handler may file the case anyway, and who may overrule that.
 *
 * WHY THE POLICY IS PER CASE TYPE AND THE RULES ARE PER SCHEMA. The schema is
 * one, and two municipalities do not agree about which case types tolerate a
 * second copy. A melding of a broken street light filed twice in a week is a
 * second report of the same light and costs nothing; a subsidy application
 * filed twice is two claims on one budget. So the rules live once on the schema
 * and the consequence lives on the case type, which is the object an
 * administrator already edits.
 *
 * ENFORCED ON THE WRITE, NOT ONLY DRAWN IN THE FORM, for the reason
 * `IntakeRequirementsListener` gives about the intake declarations: cases are
 * created from the create form, from the mail intake, from an import and from
 * any integration a gemeente builds, and a rule that lives in the form is a
 * rule four of those five walk past.
 *
 * 🔴 IT FAILS OPEN, DELIBERATELY, AND THAT IS NOT THE SAME CHOICE
 * `AssigneeNarrowing` MAKES. When OpenRegister's detection service cannot be
 * resolved the answer is "no matches", so the case is filed. A duplicate
 * warning is a quality rule, not an authorisation boundary: refusing every
 * create in a municipality because one service is missing would stop the
 * intake desk working, and the cost of the other failure is a second case
 * somebody merges later. The one thing that must never happen quietly is the
 * opposite reading, an empty match list presented as "we looked and found
 * nothing", which is why the frontend is told the check did not run.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Intake
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Intake;

use OCA\Dossiq\Exception\RefusedException;
use OCP\IGroupManager;
use OCP\IUser;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * What a case type does about a case that already exists (REQ-FCF-10).
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
 */
class DuplicatePolicy {

	/**
	 * The case-type property holding the policy.
	 */
	public const PROPERTY = 'duplicatePolicy';

	/**
	 * The handler sees the matches and decides. The default, and what every
	 * case type that says nothing does.
	 */
	public const POLICY_WARN = 'warn';

	/**
	 * Only an override group may file the case, and they say why.
	 */
	public const POLICY_BLOCK = 'block';

	/**
	 * The case field carrying the reason a coordinator filed anyway.
	 */
	public const FIELD_REASON = 'duplicateOverrideReason';

	/**
	 * The case field listing what it was filed over.
	 */
	public const FIELD_OVER = 'duplicateOverrideOf';

	/**
	 * The rule a refused create names.
	 */
	public const RULE_BLOCKED = 'duplicate-of-an-existing-case';

	/**
	 * The register the `case` schema lives in. FROZEN: the OpenRegister
	 * register slug, not this app's id.
	 */
	private const REGISTER = 'dossiq';

	/**
	 * The schema the rules are declared on.
	 */
	private const SCHEMA = 'case';

	/**
	 * OpenRegister's scorer. Resolved by name so dossiq keeps no compile-time
	 * dependency on it.
	 */
	private const DETECTION_SERVICE = 'OCA\OpenRegister\Service\Quality\DuplicateDetectionService';

	/**
	 * Constructor.
	 *
	 * @param ContainerInterface $container    Resolves OpenRegister's scorer.
	 * @param IGroupManager      $groupManager Who is in the override group.
	 * @param LoggerInterface    $logger       Logger.
	 */
	public function __construct(
		private readonly ContainerInterface $container,
		private readonly IGroupManager $groupManager,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The policy this case type declares.
	 *
	 * An unrecognised value reads as `warn` rather than as a refusal. A typo in
	 * an administrator's case type should not stop an intake desk filing cases,
	 * and `warn` is what every case type that says nothing already does.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return string Either {@see self::POLICY_WARN} or {@see self::POLICY_BLOCK}.
	 *
	 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
	 */
	public function policyFor(array $caseType): string {
		$declared = trim((string)($caseType[self::PROPERTY] ?? ''));
		if ($declared === self::POLICY_BLOCK) {
			return self::POLICY_BLOCK;
		}

		return self::POLICY_WARN;
	}//end policyFor()

	/**
	 * The groups the `case` schema lets file a case over a warning.
	 *
	 * Read off the LIVE schema annotation rather than repeated here, because
	 * OpenRegister enforces the same list on its own blocked creates and two
	 * copies of one list drift. An empty list is a real answer: a schema that
	 * names no override group has said that nobody overrides.
	 *
	 * @return array<int, string> The group ids, possibly empty.
	 *
	 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
	 */
	public function overrideGroups(): array {
		$annotation = $this->dedupAnnotation();
		$declared = ($annotation['overrideGroups'] ?? []);
		if (is_array($declared) === false) {
			return [];
		}

		$groups = [];
		foreach ($declared as $group) {
			$group = trim((string)$group);
			if ($group !== '') {
				$groups[] = $group;
			}
		}

		return $groups;
	}//end overrideGroups()

	/**
	 * Whether this account may file a case over a warning.
	 *
	 * There is no implicit administrator bypass, matching OpenRegister's own
	 * rule: an administrator is no more entitled to a second copy of a case
	 * than anyone else.
	 *
	 * @param IUser|null $user The account filing the case.
	 *
	 * @return boolean True when the account is in a declared override group.
	 *
	 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
	 */
	public function mayOverride(?IUser $user): bool {
		if ($user === null) {
			return false;
		}

		foreach ($this->overrideGroups() as $group) {
			if ($this->groupManager->isInGroup($user->getUID(), $group) === true) {
				return true;
			}
		}

		return false;
	}//end mayOverride()

	/**
	 * What the create form is told before it asks anybody anything.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 * @param IUser|null           $user     The account filing the case.
	 *
	 * @return array{policy: string, mayOverride: bool, overrideGroups: array<int, string>}
	 *
	 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
	 */
	public function declarationFor(array $caseType, ?IUser $user): array {
		return [
			'policy' => $this->policyFor(caseType: $caseType),
			'mayOverride' => $this->mayOverride(user: $user),
			'overrideGroups' => $this->overrideGroups(),
		];
	}//end declarationFor()

	/**
	 * The stored cases that look like this one, as OpenRegister scores them.
	 *
	 * @param array<string, mixed> $case The case as it would be written.
	 *
	 * @return array<int, array<string, mixed>> Matches, strongest first.
	 *
	 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
	 */
	public function matchesFor(array $case): array {
		$detection = $this->detectionService();
		if ($detection === null) {
			return [];
		}

		try {
			$matches = $detection->checkCandidate(
				register: self::REGISTER,
				schema: self::SCHEMA,
				candidate: $this->candidateFrom(case: $case)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the duplicate check could not run: ' . $e->getMessage()
			);

			return [];
		}

		if (is_array($matches) === false) {
			return [];
		}

		return $matches;
	}//end matchesFor()

	/**
	 * Refuse a create the case type blocks.
	 *
	 * Runs only when the case type says `block`, which is what bounds the blast
	 * radius: nothing happens at all to a case type that never asked for it.
	 *
	 * @param array<string, mixed> $case     The case as it would be written.
	 * @param array<string, mixed> $caseType The effective case type row.
	 * @param IUser|null           $user     The account filing the case.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the case type blocks and the account may not override.
	 *
	 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
	 */
	public function assertCreatable(array $case, array $caseType, ?IUser $user): void {
		if ($this->policyFor(caseType: $caseType) !== self::POLICY_BLOCK) {
			return;
		}

		$matches = $this->matchesFor(case: $case);
		if ($matches === []) {
			return;
		}

		if ($this->mayOverride(user: $user) === false) {
			throw new RefusedException(
				rule: self::RULE_BLOCKED,
				sentence: 'A case like this one already exists, and this case type does not let it be filed twice. '
					. 'Open the case it matches, or ask a coordinator to file this one anyway.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		if (trim((string)($case[self::FIELD_REASON] ?? '')) === '') {
			throw new RefusedException(
				rule: self::RULE_BLOCKED,
				sentence: 'Say why this case is filed although one like it already exists. '
					. 'The next handler reading two near-identical cases needs to know which of them was meant.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}
	}//end assertCreatable()

	/**
	 * The uuids of the matches, for the case field that records what it was
	 * filed over.
	 *
	 * @param array<int, array<string, mixed>> $matches The scored matches.
	 *
	 * @return array<int, string> The uuids, in the order they were scored.
	 *
	 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
	 */
	public function uuidsOf(array $matches): array {
		$uuids = [];
		foreach ($matches as $match) {
			if (is_array($match) === false) {
				continue;
			}

			$uuid = trim((string)($match['uuid'] ?? ''));
			if ($uuid !== '') {
				$uuids[] = $uuid;
			}
		}

		return $uuids;
	}//end uuidsOf()

	/**
	 * The candidate body, with dossiq's own override bookkeeping removed and
	 * every empty value dropped.
	 *
	 * The two override fields are a record of THIS decision, never something to
	 * compare cases on. Leaving them in would make a case filed over a warning
	 * score against the next one on the reason somebody typed.
	 *
	 * 🔴 AN EMPTY STRING IS NOT AN ABSENT FIELD TO THE SCORER, AND THE
	 * DIFFERENCE DECIDES WHETHER MOST CASES MATCH EACH OTHER.
	 * `SimilarityCalculator::similarity()` answers 0.0 when either side is not
	 * scalar, so an ABSENT field never contributes. Two EMPTY STRINGS are both
	 * scalar and equal, so an `exact` rule on them scores a perfect 1.0.
	 * `permitApplicationRef` is empty on every case that did not arrive from the
	 * DSO, which is nearly all of them, so leaving the blank in would score
	 * every pair of ordinary cases as a partial match on a field neither of them
	 * has. Dropping the blanks here makes the candidate side immune; the stored
	 * side is OpenRegister's to fix and is reported as such.
	 *
	 * @param array<string, mixed> $case The case as it would be written.
	 *
	 * @return array<string, mixed> The candidate.
	 */
	private function candidateFrom(array $case): array {
		unset($case[self::FIELD_REASON], $case[self::FIELD_OVER]);

		foreach ($case as $field => $value) {
			if ($value === null || $value === '' || $value === []) {
				unset($case[$field]);
			}
		}

		return $case;
	}//end candidateFrom()

	/**
	 * The `x-openregister-dedup` block the live `case` schema carries.
	 *
	 * @return array<string, mixed> The annotation, empty when unreadable.
	 */
	private function dedupAnnotation(): array {
		$detection = $this->detectionService();
		if ($detection === null) {
			return [];
		}

		try {
			$annotation = $detection->dedupAnnotation(
				register: self::REGISTER,
				schema: self::SCHEMA
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the dedup declaration could not be read: ' . $e->getMessage()
			);

			return [];
		}

		if (is_array($annotation) === false) {
			return [];
		}

		return $annotation;
	}//end dedupAnnotation()

	/**
	 * OpenRegister's duplicate scorer, or null when OpenRegister is not
	 * installed.
	 *
	 * @return object|null The DuplicateDetectionService.
	 *
	 * @psalm-return \OCA\OpenRegister\Service\Quality\DuplicateDetectionService|null
	 */
	private function detectionService(): ?object {
		try {
			return $this->container->get(self::DETECTION_SERVICE);
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: OpenRegister duplicate detection is unavailable: ' . $e->getMessage()
			);

			return null;
		}
	}//end detectionService()
}//end class
