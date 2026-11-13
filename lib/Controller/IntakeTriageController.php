<?php

/**
 * Dossiq Intake Triage Controller.
 *
 * What a case type asks for before a case exists, who may hold it, and the
 * three acts an intake worker performs: refusing a case to a declared
 * destination, sleeping a triage item until a date, and fanning one submission
 * out into several cases.
 *
 * 🔴 THE THREE ACTS ARE GATED, THE READ IS NOT THE SAME GATE. Refusing a case
 * is a mutation of that case, so it goes through {@see CaseAccessGuard} like
 * every other case mutation. Sleeping an item and fanning a submission out
 * touch the intake log and the triage queue, which hold the original of
 * everything the mailbox received, so they are gated on the intake role the
 * same way {@see MailIntakeController} is. The declaration read is neither: it
 * answers what a case type asks for, which is what the create form needs to
 * draw itself, and a case type is configuration every handler already reads.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/specs/semantic-case-intake/spec.md
 * @spec openspec/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\Controller\Support\IntakeRoleGate;
use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\AdmissibilityJudgementInterface;
use OCA\Dossiq\Kernel\Contracts\AssigneeNarrowingInterface;
use OCA\Dossiq\Kernel\Contracts\CaseAccessGuardInterface;
use OCA\Dossiq\Kernel\Contracts\CaseClassificationInterface;
use OCA\Dossiq\Kernel\Contracts\CaseMatchSuggestionInterface;
use OCA\Dossiq\Kernel\Contracts\CaseTypeResolverInterface;
use OCA\Dossiq\Kernel\Contracts\ClassificationSchemesInterface;
use OCA\Dossiq\Kernel\Contracts\DuplicatePolicyInterface;
use OCA\Dossiq\Kernel\Contracts\IntakeFanOutInterface;
use OCA\Dossiq\Kernel\Contracts\IntakeLogInterface;
use OCA\Dossiq\Kernel\Contracts\IntakePolicyInterface;
use OCA\Dossiq\Kernel\Contracts\IntakeRequirementsInterface;
use OCA\Dossiq\Kernel\Contracts\RefusalOutcomeInterface;
use OCA\Dossiq\Kernel\Contracts\TriageChannelInterface;
use OCA\Dossiq\Kernel\Contracts\TriageSleepInterface;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The intake declaration, and the three acts an intake worker performs on it.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — one surface over six
 *  declarations and three acts; each collaborator is injected so a test can
 *  replace exactly one.
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) — same reason: resolving the
 *  collaborators inside the methods would make them untestable in exchange for a
 *  shorter signature.
 *
 * @spec openspec/specs/semantic-case-intake/spec.md
 * @spec openspec/specs/kcc-routing/spec.md
 */
class IntakeTriageController extends Controller {

	use TranslatesRefusals;

	/**
	 * How many triage items one page of the queue holds.
	 */
	private const PAGE_SIZE = 100;

	/**
	 * Constructor.
	 *
	 * @param IRequest              $request          Inbound request.
	 * @param CaseTypeResolverInterface      $caseTypeResolver The effective case type.
	 * @param IntakeRequirementsInterface    $requirements     What must be answered, and when.
	 * @param CaseClassificationInterface    $classification   The facets and the access rule.
	 * @param ClassificationSchemesInterface $schemes          The schemes this instance knows.
	 * @param AssigneeNarrowingInterface     $narrowing        Who may hold the case.
	 * @param DuplicatePolicyInterface       $duplicates       What this case type does about a case that already exists.
	 * @param RefusalOutcomeInterface        $refusal          Refusal as an outcome of routing.
	 * @param AdmissibilityJudgementInterface $admissibility   The ontvankelijkheid verdict at intake.
	 * @param TriageSleepInterface           $sleep            The triage sleep.
	 * @param IntakeFanOutInterface          $fanOut           One submission, several cases.
	 * @param IntakeLogInterface             $log              The triage queue.
	 * @param IntakePolicyInterface          $policy           Who may run intake.
	 * @param CaseAccessGuardInterface       $caseAccessGuard  Per-case authorization.
	 * @param IUserSession          $userSession      The caller.
	 * @param LoggerInterface       $logger           Logger.
	 * @param CaseMatchSuggestionInterface   $suggestions      The case each queued message probably belongs to.
	 * @param TriageChannelInterface         $channels         The channel each queued message came in on.
	 */
	public function __construct(
		IRequest $request,
		private readonly CaseTypeResolverInterface $caseTypeResolver,
		private readonly IntakeRequirementsInterface $requirements,
		private readonly CaseClassificationInterface $classification,
		private readonly ClassificationSchemesInterface $schemes,
		private readonly AssigneeNarrowingInterface $narrowing,
		private readonly DuplicatePolicyInterface $duplicates,
		private readonly RefusalOutcomeInterface $refusal,
		private readonly AdmissibilityJudgementInterface $admissibility,
		private readonly TriageSleepInterface $sleep,
		private readonly IntakeFanOutInterface $fanOut,
		private readonly IntakeLogInterface $log,
		private readonly IntakePolicyInterface $policy,
		private readonly CaseAccessGuardInterface $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly CaseMatchSuggestionInterface $suggestions,
		private readonly TriageChannelInterface $channels,
	) {
		parent::__construct(appName: AppIdentity::APP_ID, request: $request);
	}//end __construct()

	/**
	 * What one case type asks for before a case of it can exist.
	 *
	 * The create form draws itself from this: which fields it must ask for,
	 * which facets it must offer and out of which values, which teams and
	 * people the assignee picker may list, and what happens when the case being
	 * filed looks like one that already exists.
	 *
	 * `duplicatePolicy` answers the second question the form has to ask before
	 * it can draw the warning panel. The matches come from OpenRegister and say
	 * what looks the same; only dossiq can say whether THIS account may file the
	 * case anyway, because the override group is a group membership the browser
	 * cannot read.
	 *
	 * @param string $caseTypeId The case type.
	 *
	 * @return JSONResponse The declaration, or 401 without a session.
	 *
	 * @spec openspec/specs/semantic-case-intake/spec.md
	 * @spec openspec/specs/friendly-case-create-form/spec.md
	 */
	#[NoAdminRequired]
	public function requirements(string $caseTypeId): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$caseType = $this->caseTypeResolver->effectiveCaseType(caseTypeId: $caseTypeId);
		if ($caseType === []) {
			return new JSONResponse(['message' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$classification = $this->classification->declarationFor(caseType: $caseType);

		return new JSONResponse(
			[
				'caseType' => $caseTypeId,
				'intakeRequirements' => $this->requirements->declarationFor(caseType: $caseType),
				'classification' => $classification,
				'classificationValues' => $this->schemes->valuesOf(scheme: $classification['scheme']),
				'schemeResolves' => $this->schemes->resolves(scheme: $classification['scheme']),
				'assigneeNarrowing' => $this->narrowing->declarationFor(caseType: $caseType),
				'narrowsNothing' => $this->narrowing->narrowsNothing(caseType: $caseType),
				'refusalDestination' => $this->refusal->destinationFor(caseType: $caseType),
				'canRefuse' => $this->refusal->canRefuse(caseType: $caseType),
				'duplicatePolicy' => $this->duplicates->declarationFor(caseType: $caseType, user: $user),
			]
		);
	}//end requirements()

	/**
	 * Refuse a case at intake, to the destination its case type declares.
	 *
	 * @param string $caseId The case.
	 * @param string $reason Why it is refused.
	 *
	 * @return JSONResponse The refusal record, or the refusal of the refusal.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	#[NoAdminRequired]
	public function refuse(string $caseId, string $reason = ''): JSONResponse {
		$user = $this->handlerOf(caseId: $caseId);
		if ($user instanceof JSONResponse) {
			return $user;
		}

		try {
			$record = $this->refusal->refuse(
				caseId: $caseId,
				reason: $reason,
				refusedBy: $user->getUID()
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'refuse case ' . $caseId, e: $e);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: refusing case ' . $caseId . ' failed: ' . $e->getMessage()
			);

			return new JSONResponse(
				['message' => 'The case was not refused.', 'error' => 'refusal-failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		return new JSONResponse($record);
	}//end refuse()

	/**
	 * Record the admissibility verdict at intake (REQ-DEC-02).
	 *
	 * An inadmissible verdict closes the case through the ordinary close act
	 * with the result its case type names, records who judged it, and tells the
	 * applicant through the declared moment. An admissible one is recorded and
	 * the case moves on through the transition its phase already offers: moving
	 * it here would be a second mover of the same case.
	 *
	 * @param string $caseId  The case.
	 * @param string $verdict `admissible` or `inadmissible`.
	 * @param string $reason  Why. The applicant may object to the verdict.
	 *
	 * @return JSONResponse What was recorded, or the refusal.
	 *
	 * @spec openspec/specs/besluitvorming-leaf/spec.md
	 */
	#[NoAdminRequired]
	public function judgeAdmissibility(string $caseId, string $verdict = '', string $reason = ''): JSONResponse {
		$user = $this->handlerOf(caseId: $caseId);
		if ($user instanceof JSONResponse) {
			return $user;
		}

		try {
			$recorded = $this->admissibility->judge(
				caseId: $caseId,
				verdict: $verdict,
				reason: $reason,
				judgedBy: $user->getUID(),
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'judge admissibility of case ' . $caseId, e: $e);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: judging admissibility of case ' . $caseId . ' failed: ' . $e->getMessage()
			);

			return new JSONResponse(
				['message' => 'The verdict was not recorded.', 'error' => 'admissibility-failed'],
				Http::STATUS_INTERNAL_SERVER_ERROR
			);
		}//end try

		return new JSONResponse($recorded);
	}//end judgeAdmissibility()

	/**
	 * The triage queue, with its sleeping items taken out and each item's likely case suggested.
	 *
	 * Every item names the channel it came in on (`email` for the mailbox).
	 * `channels` counts the channels within the asked lens before the channel
	 * narrows, so the channel facet keeps offering the others while one is
	 * chosen.
	 *
	 * @param string $suggestion `IS NOT NULL` for the items that look like a case, `IS NULL` for the rest, empty for all.
	 * @param string $channel    One channel, a comma list, or empty or `all` for every channel.
	 *
	 * @return JSONResponse The items a handler should see.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 * @spec openspec/specs/intake-from-a-channel/spec.md
	 */
	#[NoAdminRequired]
	public function queue(string $suggestion = '', string $channel = ''): JSONResponse {
		$refusal = $this->requireIntakeRole();
		if ($refusal !== null) {
			return $refusal;
		}

		$items = $this->sleepableItems();
		$awake = $this->sleep->awake(queue: $items);
		$lens = $this->channels->annotate(
			items: $this->suggestions->narrow(
				items: $this->suggestions->annotate(items: array_values($awake)),
				suggestion: $suggestion
			)
		);
		$results = $this->channels->narrow(items: $lens, channel: $channel);

		return new JSONResponse(
			[
				'results' => $results,
				'total' => count($results),
				'sleeping' => (count($items) - count($awake)),
				'channels' => $this->channels->facet(items: $lens),
			]
		);
	}//end queue()

	/**
	 * The signed-in caller when they may act on the case's intake, or the 401/403 refusal.
	 *
	 * @param string $caseId The case.
	 *
	 * @return IUser|JSONResponse
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	private function handlerOf(string $caseId): IUser|JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$forbidden = $this->refuseUnauthorized(caseId: $caseId, user: $user);
		if ($forbidden !== null) {
			return $forbidden;
		}

		return $user;
	}//end handlerOf()

	/**
	 * Every triage log item whose outcome can sleep, a page per outcome.
	 *
	 * @return array<int, array<string, mixed>>
	 *
	 * @spec openspec/specs/intake-from-a-channel/spec.md
	 */
	private function sleepableItems(): array {
		$items = [];
		foreach (TriageSleepInterface::SLEEPABLE_OUTCOMES as $outcome) {
			$items = array_merge(
				$items,
				$this->log->search(filters: ['outcome' => $outcome, '_limit' => self::PAGE_SIZE])
			);
		}

		return $items;
	}//end sleepableItems()

	/**
	 * Put a triage item to sleep until a date.
	 *
	 * @param string $entryId The intake log entry.
	 * @param string $until   The date it comes back.
	 * @param string $reason  Why nothing is done until then.
	 *
	 * @return JSONResponse The sleep record, or the refusal.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	#[NoAdminRequired]
	public function sleepItem(string $entryId, string $until = '', string $reason = ''): JSONResponse {
		$refusal = $this->requireIntakeRole();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$record = $this->sleep->sleep(
				entryId: $entryId,
				until: $until,
				reason: $reason,
				actorId: $this->callerId()
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'sleep triage item ' . $entryId, e: $e);
		}

		return new JSONResponse($record);
	}//end sleepItem()

	/**
	 * Open one case per destination an intake form declares.
	 *
	 * @param string               $caseTypeId   The intake case type the form maps to.
	 * @param array<string, mixed> $submission   The submitted values.
	 * @param string               $submissionId The submission's own identifier.
	 *
	 * @return JSONResponse What was created, and what was not.
	 *
	 * @spec openspec/specs/kcc-routing/spec.md
	 */
	#[NoAdminRequired]
	public function fanOut(
		string $caseTypeId,
		array $submission = [],
		string $submissionId = '',
	): JSONResponse {
		$refusal = $this->requireIntakeRole();
		if ($refusal !== null) {
			return $refusal;
		}

		try {
			$result = $this->fanOut->submit(
				formCaseTypeId: $caseTypeId,
				submission: $submission,
				submissionId: $submissionId
			);
		} catch (RefusedException $e) {
			return $this->refused(op: 'fan out submission ' . $submissionId, e: $e);
		}

		return new JSONResponse($result);
	}//end fanOut()

	/**
	 * Refuse a caller who may not mutate this case.
	 *
	 * @param string $caseId The case.
	 * @param IUser  $user   The caller.
	 *
	 * @return JSONResponse|null A refusal, or null when the caller may proceed.
	 */
	private function refuseUnauthorized(string $caseId, IUser $user): ?JSONResponse {
		try {
			$this->caseAccessGuard->assertCaseMutationAccess(caseId: $caseId, user: $user);
		} catch (OCSForbiddenException $e) {
			return new JSONResponse(
				['message' => 'You may not refuse this case.', 'error' => 'not-authorized'],
				Http::STATUS_FORBIDDEN
			);
		}

		return null;
	}//end refuseUnauthorized()

	/**
	 * Refuse a caller who is not the intake role.
	 *
	 * @return JSONResponse|null A refusal, or null when the caller may proceed.
	 */
	private function requireIntakeRole(): ?JSONResponse {
		return (new IntakeRoleGate(userSession: $this->userSession, policy: $this->policy))->refusal();
	}//end requireIntakeRole()

	/**
	 * The caller's user id.
	 *
	 * Always the uid, never the display name: a display name is mutable and
	 * this string goes into a record somebody reads back later.
	 *
	 * @return string The uid, or '' when there is no session.
	 */
	private function callerId(): string {
		return (new IntakeRoleGate(userSession: $this->userSession, policy: $this->policy))->callerId();
	}//end callerId()
}//end class
