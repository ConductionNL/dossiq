<?php

/**
 * Dossiq Case Merge Service
 *
 * Dossiq's half of a merge. OpenRegister owns the merge itself (ADR-045): it
 * relinks its own source records, flips `mergeState`, writes the
 * `mergeOperation` row and raises the event. What is left over is the part
 * only a case management system knows about, and that is what this service
 * does:
 *
 *   - the dossiq-owned rows that hang off a case (roles, documents, objects,
 *     properties, contact moments, decisions) move to the survivor, and the
 *     moves are written down so a reversal can put them back;
 *   - the merged case says where it went (`mergedInto`) and how it ended
 *     (`endingAct: merged`);
 *   - its running term is completed, because the survivor's term is the one
 *     the applicant is owed an answer within.
 *
 * The rule lives on the schema, not here. `x-openregister-merge` on `case`
 * names the reversal window, the state field and which kinds relink, and this
 * service reads it. A rule read from the shipped register is the same rule the
 * import wrote, so the two cannot drift apart in one direction only.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/case-merge/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Service\Cases\CaseMergeRelink;
use OCA\Dossiq\Service\Cases\CaseMergeRule;
use OCA\Dossiq\Service\Cases\CaseMergeStore;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The dossiq consequences of an OpenRegister merge on a `case`.
 *
 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
 */
class CaseMergeService {

	/**
	 * Term statuses that still count as running, and so are the ones a merge
	 * closes. Mirrors `CaseDeleteGuardListener::OPEN_TERM_STATUSES`, which asks
	 * the same question about the same rows.
	 */
	private const OPEN_TERM_STATUSES = ['lopend', 'verlengd', 'paused'];

	/**
	 * The ending act a merged case carries.
	 */
	public const ENDING_ACT_MERGED = 'merged';

	/**
	 * How far `resolveSurvivor()` follows the chain before it gives up. A
	 * merge of a merge is ordinary; a cycle is not, and a cycle with no
	 * ceiling is a request that never returns.
	 */
	private const MAX_HOPS = 16;

	/**
	 * OpenRegister's merge engine, reached by name so dossiq still enables
	 * without it.
	 */
	private const MERGE_SERVICE_CLASS = 'OCA\\OpenRegister\\Service\\Merge\\MergeService';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register and schema ids, and the object service.
	 * @param TermijnService  $termijnService  The one writer of a term instance.
	 * @param CaseMergeStore  $store           Where a merge reads and writes.
	 * @param CaseMergeRule   $rule            What the case schema declares about merging.
	 * @param CaseMergeRelink $relink          Moving the rows that hang off a merged case.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly TermijnService $termijnService,
		private readonly CaseMergeStore $store,
		private readonly CaseMergeRule $rule,
		private readonly CaseMergeRelink $relink,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The `x-openregister-merge` rule the `case` schema declares.
	 *
	 * @return array<string, mixed> The rule, empty when the register cannot be read.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	public function mergeRule(): array {
		return $this->rule->rule();
	}//end mergeRule()

	/**
	 * Follow `mergedInto` until it reaches a case that carries none.
	 *
	 * This is what makes an old case number keep working: mail matching and
	 * the public status page ask this question and act on the answer, so a
	 * reply to a merged case lands where the work actually is.
	 *
	 * An unreadable case, a cycle or a chain longer than {@see MAX_HOPS}
	 * answers the id it was given. That is the safe direction: filing a reply
	 * on the case it names is wrong only in the way it was already wrong.
	 *
	 * @param string $caseId The case uuid a caller matched.
	 *
	 * @return string The surviving case's uuid, or the input when there is none.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-the-old-number-still-finds-the-case-req-cm-38
	 */
	public function resolveSurvivor(string $caseId): string {
		$current = trim($caseId);
		if ($current === '') {
			return $caseId;
		}

		$seen = [];
		for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
			if (isset($seen[$current]) === true) {
				$this->logger->warning('Dossiq: a merged-case chain loops at "' . $current . '"');
				return $caseId;
			}

			$seen[$current] = true;

			$case = $this->store->readCase(caseId: $current);
			if ($case === null) {
				return $current;
			}

			$next = $this->store->referencedId(value: ($case['mergedInto'] ?? null));
			if ($next === '' || $next === $current) {
				return $current;
			}

			$current = $next;
		}

		$this->logger->warning('Dossiq: a merged-case chain is longer than ' . self::MAX_HOPS . ' hops');

		return $caseId;
	}//end resolveSurvivor()

	/**
	 * Whether this case may be merged away.
	 *
	 * The same two refusals a delete carries, for the same reason: a case that
	 * has been decided is a record of what was decided, and a merge would make
	 * that record point somewhere else.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return bool True when it may be merged away.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	public function isMergeable(array $case): bool {
		return $this->refusalFor(case: $case) === '';
	}//end isMergeable()

	/**
	 * Which rule refuses this case as a merge source, if any.
	 *
	 * The rule is named rather than counted, because a caseworker who is told
	 * no is owed the reason: a decided case and an already merged one are
	 * refused for different reasons and have different ways out.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return string The rule, empty when the case may be merged away.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	public function refusalFor(array $case): string {
		if (($case['isFinalStatus'] ?? false) === true) {
			return 'final-status';
		}

		if ($this->store->referencedId(value: ($case['besluitDocument'] ?? null)) !== '') {
			return 'signed-beschikking';
		}

		if ($this->store->referencedId(value: ($case['mergedInto'] ?? null)) !== '') {
			return 'already-merged';
		}

		return '';
	}//end refusalFor()

	/**
	 * Ask OpenRegister to merge one case into another.
	 *
	 * Dossiq decides whether this case may be merged away, because the rules
	 * that refuse it are case management's; OpenRegister does the merge,
	 * because the merge is the platform's (ADR-045). The refusal is written
	 * here and not only in the browser: an action the browser hides is still
	 * an endpoint anyone may call.
	 *
	 * @param string $mergedId   The case to merge away.
	 * @param string $survivorId The case it becomes part of.
	 * @param string $reason     What the caseworker typed.
	 * @param string $actor      The acting user's uid.
	 *
	 * @return array{refused?: string, operation?: array<string, mixed>} The outcome.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	public function requestMerge(string $mergedId, string $survivorId, string $reason, string $actor): array {
		$merger = $this->settingsService->getOpenRegisterClass(class: self::MERGE_SERVICE_CLASS);

		$refusal = $this->mergeRefusal(mergedId: $mergedId, survivorId: $survivorId, merger: $merger);
		if ($refusal !== '') {
			return ['refused' => $refusal];
		}

		try {
			$operation = $merger->executeMerge($mergedId, $survivorId, $reason, $actor);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: OpenRegister refused the merge of "' . $mergedId . '": ' . $e->getMessage()
			);
			return ['refused' => 'platform-refused'];
		}

		return ['operation' => (array)$operation];
	}//end requestMerge()

	/**
	 * The reason a merge is refused, or '' when it may go ahead.
	 *
	 * Every refusal is a slug rather than a sentence, because the caller turns
	 * it into the one a handler reads and the timeline stores the slug.
	 *
	 * @param string $mergedId   The case being merged away.
	 * @param string $survivorId The case it would merge into.
	 * @param mixed  $merger     OpenRegister's merge service, or null when it is absent.
	 *
	 * @return string The refusal slug, or ''.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	private function mergeRefusal(string $mergedId, string $survivorId, mixed $merger): string {
		if ($mergedId === '' || $survivorId === '' || $mergedId === $survivorId) {
			return 'same-case';
		}

		$source = $this->store->readCase(caseId: $mergedId);
		$survivor = $this->store->readCase(caseId: $survivorId);
		if ($source === null || $survivor === null) {
			return 'unknown-case';
		}

		$refusal = $this->refusalFor(case: $source);
		if ($refusal !== '') {
			return $refusal;
		}

		if ($this->store->referencedId(value: ($survivor['mergedInto'] ?? null)) !== '') {
			return 'survivor-already-merged';
		}

		if ($this->canExecuteMerge(merger: $merger) === false) {
			return 'platform-unavailable';
		}

		return '';
	}//end mergeRefusal()

	/**
	 * Whether OpenRegister's merge service is here and answers to `executeMerge`.
	 *
	 * A duck-typed lookup against a class that is not installed answers null,
	 * and one against a class that moved answers an object without the method.
	 * Both are the platform being unavailable, and neither is an error here.
	 *
	 * @param mixed $merger Whatever the class lookup answered.
	 *
	 * @return bool True when the merge can be handed over.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	private function canExecuteMerge(mixed $merger): bool {
		if ($merger === null) {
			return false;
		}

		return method_exists($merger, 'executeMerge');
	}//end canExecuteMerge()

	/**
	 * Apply dossiq's consequences of a merge.
	 *
	 * @param string $mergedId   The case that was merged away.
	 * @param string $survivorId The case it was merged into.
	 *
	 * @return bool True when the merged case was updated.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	public function applyMerge(string $mergedId, string $survivorId): bool {
		if ($mergedId === '' || $survivorId === '' || $mergedId === $survivorId) {
			return false;
		}

		$moved = $this->relink->move(fromId: $mergedId, toId: $survivorId);

		$written = $this->store->patchCase(
			caseId: $mergedId,
			changes: [
				'mergedInto' => $survivorId,
				'endingAct' => self::ENDING_ACT_MERGED,
				'mergeRelinked' => $moved,
			]
		);

		$this->closeTerm(caseId: $mergedId, survivorId: $survivorId);

		return $written;
	}//end applyMerge()

	/**
	 * Undo dossiq's consequences when the platform reverses the merge.
	 *
	 * @param string $mergedId   The case that was merged away.
	 * @param string $survivorId The case it had been merged into.
	 *
	 * @return bool True when the merged case was updated.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md#requirement-two-cases-merge-into-one-through-the-platform-req-cm-37
	 */
	public function applyReversal(string $mergedId, string $survivorId): bool {
		if ($mergedId === '') {
			return false;
		}

		$case = $this->store->readCase(caseId: $mergedId);
		$moved = (array)(($case['mergeRelinked'] ?? []));
		$this->relink->moveBack(moves: $moved, toId: $mergedId);

		$written = $this->store->patchCase(
			caseId: $mergedId,
			changes: [
				'mergedInto' => null,
				'endingAct' => null,
				'mergeRelinked' => [],
			]
		);

		$this->rearmTerm(caseId: $mergedId, survivorId: $survivorId);

		return $written;
	}//end applyReversal()

	/**
	 * Complete the merged case's running term, naming the merge as the reason.
	 *
	 * @param string $caseId     The merged case.
	 * @param string $survivorId The survivor, named in the term event.
	 *
	 * @return void
	 */
	private function closeTerm(string $caseId, string $survivorId): void {
		$instance = $this->termijnService->getTermijnInstanceForZaak(caseId: $caseId);
		if ($instance === null) {
			return;
		}

		if (in_array((string)($instance['status'] ?? ''), self::OPEN_TERM_STATUSES, true) === false) {
			return;
		}

		$this->termijnService->markTermijnCompleted(
			termInstanceId: (string)($instance['id'] ?? ''),
			rationale: 'Termijn voltooid: zaak samengevoegd met ' . $survivorId
		);
	}//end closeTerm()

	/**
	 * Re-arm the term the merge completed, from the completed instance's own
	 * dates. A reversal means the merge never should have happened, so the
	 * clock the applicant was owed runs again from where it stood.
	 *
	 * @param string $caseId     The case that is its own case again.
	 * @param string $survivorId The case it had been merged into.
	 *
	 * @return void
	 */
	private function rearmTerm(string $caseId, string $survivorId): void {
		$completed = null;
		foreach ($this->termijnService->instancesForCase(caseId: $caseId) as $instance) {
			if ((string)($instance['status'] ?? '') === 'completed') {
				$completed = $instance;
				break;
			}
		}

		if ($completed === null) {
			return;
		}

		unset($completed['id'], $completed['@self'], $completed['voltooiDatum']);
		$completed['status'] = 'lopend';
		$completed['case'] = $caseId;

		$this->termijnService->saveTermInstance(instance: $completed);

		$this->logger->info(
			'Dossiq: the term of case "' . $caseId . '" runs again after the merge into "'
			. $survivorId . '" was reversed'
		);
	}//end rearmTerm()

}//end class
