<?php

/**
 * Dossiq case type publication checks.
 *
 * What stands between a draft case type and being published, and what
 * publishing should say out loud without refusing. A finding blocks the
 * publish and is written as a sentence a person can act on; a warning does
 * not block, because a case type may lawfully owe no acknowledgement of
 * receipt and refusing would make a valid configuration unpublishable.
 *
 * 🔑 THE ONE MOMENT THESE CAN BE ASKED. A workflow template is written
 * straight to OpenRegister by the authoring page, so no dossiq code runs when
 * a move is saved. Publication is the write dossiq owns, and it is the act
 * that makes the lifecycle live.
 *
 * Split out of {@see \OCA\Dossiq\Service\CaseTypePublishService}, which was
 * over its complexity ceiling and carried thirteen collaborators. Deciding
 * whether a draft may be published and performing the publish are two jobs,
 * and eight of those thirteen were only ever asked by the first.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/specs/case-type-publish-validation/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

use OCA\Dossiq\Service\Beschikking\RemedyClauseDeclaration;
use OCA\Dossiq\Service\CaseTypeAcknowledgement;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Intake\AdmissibilityJudgement;
use OCA\Dossiq\Service\UnreadTriggerService;
use Throwable;

/**
 * The findings and warnings a publish has to answer for.
 *
 * @spec openspec/specs/case-type-publish-validation/spec.md
 */
class PublicationChecks {

	/**
	 * Constructor.
	 *
	 * @param CaseTypeResolver        $caseTypeResolver The effective blueprint.
	 * @param CaseTypeStore           $store            Reads for the resolver's schemas.
	 * @param CaseTypeAcknowledgement $acknowledgement  What this type declares about confirming receipt.
	 * @param UnreadTriggerService    $unreadTriggers   What this type declares about what makes a case unread.
	 * @param AdmissibilityJudgement  $admissibility    Whether intake ends with a verdict, and what it closes on.
	 * @param RemedyClauseDeclaration $remedy           The remedy open against this case type's decisions.
	 * @param CaseTypeHandling        $handling         The one reader of the handling switches.
	 * @param CaseTypeReachability    $reachability     What this type's moves can and cannot reach.
	 *
	 * @SuppressWarnings(PHPMD.ExcessiveParameterList) Constructor DI. Each one
	 *  answers a different question the publish has to ask before the one write
	 *  it owns: is the draft valid, can a case actually run through it, and
	 *  what does it warn about. They came here together out of
	 *  CaseTypePublishService, where they sat beside the write itself.
	 */
	public function __construct(
		private readonly CaseTypeResolver $caseTypeResolver,
		private readonly CaseTypeStore $store,
		private readonly CaseTypeAcknowledgement $acknowledgement,
		private readonly UnreadTriggerService $unreadTriggers,
		private readonly AdmissibilityJudgement $admissibility,
		private readonly RemedyClauseDeclaration $remedy,
		private readonly CaseTypeHandling $handling,
		private readonly CaseTypeReachability $reachability,
	) {
	}//end __construct()

	/**
	 * What publishing this case type should say out loud without refusing.
	 *
	 * 🔴 A WARNING AND NOT A FINDING, ON PURPOSE. A finding blocks publication,
	 * and a case type may genuinely owe no acknowledgement of receipt, so
	 * refusing would make a lawful configuration unpublishable. What must not
	 * happen is the duty coming OFF quietly: Awb 4:3a owes a confirmation to
	 * every electronic submission, and an app that stops sending one still
	 * reads green. So publication says so and names the article, and the person
	 * publishing decides.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, string> The warnings, empty when nothing is off.
	 *
	 * @spec openspec/changes/ontvangstbevestiging/specs/burger-notifications/spec.md
	 */
	public function warnings(string $caseTypeId): array {
		$caseType = $this->caseTypeResolver->effectiveCaseType(caseTypeId: $caseTypeId);
		if ($caseType === []) {
			return [];
		}

		return array_merge(
			$this->acknowledgement->publicationWarnings(caseType: $caseType),
			$this->unreadTriggers->publicationWarnings(caseType: $caseType),
			// Decision outcomes on the case: a case type that judges
			// admissibility with no result to close on, or closes an
			// inadmissible aanvraag without telling the applicant.
			$this->admissibility->publicationWarnings(caseType: $caseType),
			// A case type whose decisions declare no remedy. Its besluit would
			// print no bezwaarclausule, which is a decision going out without
			// saying how to object to it.
			$this->remedy->publicationWarnings(caseType: $caseType)
		);
	}//end warnings()

	/**
	 * What stands between this draft and being published.
	 *
	 * Returns the findings as sentences a person can act on, never as codes:
	 * the list is rendered straight into the Publish dialog, and "no findings"
	 * is the empty array.
	 *
	 * @param string $caseTypeId CaseType UUID.
	 *
	 * @return array<int, string> The findings, empty when the draft is publishable.
	 *
	 * @spec openspec/specs/zaaktype-versioning/spec.md
	 */
	public function validate(string $caseTypeId): array {
		$caseType = $this->caseTypeResolver->effectiveCaseType(caseTypeId: $caseTypeId);
		if ($caseType === []) {
			return ['This case type could not be read.'];
		}

		$findings = [];
		$statuses = $this->caseTypeResolver->statusTypesFor(caseTypeId: $caseTypeId);

		if ($statuses === []) {
			$findings[] = 'Give the case type at least one status.';
		}

		if ($statuses !== [] && $this->hasFinalStatus(statuses: $statuses) === false) {
			$findings[] = 'Mark one of the statuses as the final one, so a case can close.';
		}

		if ($statuses !== [] && $this->initialStatusIsOwn(caseType: $caseType, statuses: $statuses) === false) {
			$findings[] = 'Pick the status a new case of this type starts in.';
		}

		if (trim((string)($caseType['title'] ?? '')) === '') {
			$findings[] = 'Give the case type a title.';
		}

		$cycle = $this->cycleFinding(caseTypeId: $caseTypeId, caseType: $caseType);
		if ($cycle !== '') {
			$findings[] = $cycle;
		}

		return array_merge(
			$findings,
			$this->handlingFindings(caseType: $caseType),
			$this->reachabilityFindings(caseTypeId: $caseTypeId, caseType: $caseType, statuses: $statuses)
		);
	}//end validate()

	/**
	 * What this case type's moves could never reach.
	 *
	 * 🔑 THE ONE MOMENT THIS CAN BE ASKED. A workflow template is written
	 * straight to OpenRegister by the authoring page, so no dossiq code runs
	 * when a move is saved; publication is the write dossiq owns, and it is the
	 * act that makes the lifecycle live. Asking here is what keeps a move that
	 * nothing can fire, and a status nothing leads to, from reaching a desk.
	 *
	 * Extracted from `validate()` for the reason `handlingFindings()` was: that
	 * method's complexity has a ceiling the analyser enforces.
	 *
	 * @param string                           $caseTypeId The type being published.
	 * @param array<string, mixed>             $caseType   Its effective row.
	 * @param array<int, array<string, mixed>> $statuses   Its resolved statuses.
	 *
	 * @return array<int, string> The findings, empty when everything is reachable.
	 *
	 * @spec openspec/specs/case-type-publish-validation/spec.md
	 */
	private function reachabilityFindings(string $caseTypeId, array $caseType, array $statuses): array {
		$template = $this->store->activeTemplate(caseTypeId: $caseTypeId);
		if ($template === []) {
			return [];
		}

		$declared = [];
		foreach ($statuses as $status) {
			$id = $this->store->rowId(row: $status);
			if ($id === '') {
				continue;
			}

			$title = trim((string)($status['name'] ?? ($status['title'] ?? '')));
			if ($title === '') {
				$title = $id;
			}

			$declared[$id] = [
				'title' => $title,
				'final' => in_array(($status['isFinal'] ?? false), [true, 1, '1', 'true'], true),
			];
		}

		return $this->reachability->findings(
			statuses: $declared,
			initial: $this->store->referenceId(value: ($caseType['initialStatus'] ?? '')),
			moves: $this->moves(template: $template)
		);
	}//end reachabilityFindings()

	/**
	 * The transitions on a template row, whichever way OpenRegister stored them.
	 *
	 * A template read straight off the store can carry `transitions` as a JSON
	 * string, because that is how the authoring page writes it. Treating the
	 * string as an empty list would make every finding here silently disappear
	 * on exactly the case types that have the most moves.
	 *
	 * @param array<string, mixed> $template The active workflow template row.
	 *
	 * @return array<int, array<string, mixed>> The transitions.
	 */
	private function moves(array $template): array {
		$raw = ($template['transitions'] ?? []);
		if (is_string($raw) === true) {
			$raw = json_decode($raw, true);
		}

		if (is_array($raw) === false) {
			return [];
		}

		return array_values(array_filter($raw, 'is_array'));
	}//end moves()

	/**
	 * The findings the handling block produces, if it produces any.
	 *
	 * A switch declared on a case type and read by nothing is a promise the
	 * product does not keep, and it is invisible until somebody relies on it.
	 * Extracted from `validate()` rather than inlined, so that method's
	 * complexity stays inside the threshold the analyser enforces.
	 *
	 * @param array<string, mixed> $caseType The effective case type row.
	 *
	 * @return array<int, string> The findings, empty when every switch is read.
	 *
	 * @spec openspec/changes/starter-content-and-templates/specs/case-type-seed-data/spec.md
	 */
	private function handlingFindings(array $caseType): array {
		$findings = [];
		foreach ($this->handling->unreadSwitches(caseType: $caseType) as $switch) {
			$findings[] = ('Nothing reads the handling switch "' . $switch . '". Remove it, or name a switch that is read.');
		}

		return $findings;
	}//end handlingFindings()

	/**
	 * The finding a looping parent chain produces, if it loops.
	 *
	 * 🔴 THIS IS THE ONLY PLACE DOSSIQ CAN REFUSE A CYCLE. The spec words the
	 * refusal as "on save", and dossiq does not own the save: a case type is
	 * written straight to OpenRegister's object API by the page, and no dossiq
	 * code runs in between. Publishing is the one write dossiq does own, so it
	 * is where a type whose chain returns to itself is stopped. `chainFor()`
	 * degrades safely on a chain already stored that way — it stops rather
	 * than looping — so a mis-saved type stays readable while it is unpublished.
	 *
	 * @param string               $caseTypeId The type being published.
	 * @param array<string, mixed> $caseType   Its effective row.
	 *
	 * @return string The finding, or '' when the chain is sound.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	private function cycleFinding(string $caseTypeId, array $caseType): string {
		$parent = $this->store->referenceId(value: ($caseType['parentCaseType'] ?? ''));
		if ($parent === '') {
			return '';
		}

		try {
			$this->caseTypeResolver->assertNoCycle(caseTypeId: $caseTypeId, parentCaseTypeId: $parent);
		} catch (Throwable $e) {
			return $e->getMessage();
		}

		return '';
	}//end cycleFinding()

	/**
	 * Whether one of the statuses closes a case.
	 *
	 * @param array<int, array<string, mixed>> $statuses The resolved statuses.
	 *
	 * @return boolean True when at least one is final.
	 */
	private function hasFinalStatus(array $statuses): bool {
		foreach ($statuses as $status) {
			if (in_array(($status['isFinal'] ?? false), [true, 1, '1', 'true'], true) === true) {
				return true;
			}
		}

		return false;
	}//end hasFinalStatus()

	/**
	 * Whether the type's initial status is one of the statuses it resolves to.
	 *
	 * An initial status pointing at a status the type does not have is worse
	 * than none: a new case is filed into a status its own lifecycle cannot
	 * move it out of.
	 *
	 * @param array<string, mixed>             $caseType The effective case type.
	 * @param array<int, array<string, mixed>> $statuses The resolved statuses.
	 *
	 * @return boolean True when the initial status resolves.
	 */
	private function initialStatusIsOwn(array $caseType, array $statuses): bool {
		$initial = $this->store->referenceId(value: ($caseType['initialStatus'] ?? ''));
		if ($initial === '') {
			return false;
		}

		foreach ($statuses as $status) {
			if ($this->store->rowId(row: $status) === $initial) {
				return true;
			}
		}

		return false;
	}//end initialStatusIsOwn()
}//end class
