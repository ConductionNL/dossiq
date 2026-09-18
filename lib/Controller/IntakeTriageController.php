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
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Controller\Support\TranslatesRefusals;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Email\IntakePolicy;
use OCA\Dossiq\Service\Intake\AssigneeNarrowing;
use OCA\Dossiq\Service\Intake\CaseClassification;
use OCA\Dossiq\Service\Intake\ClassificationSchemes;
use OCA\Dossiq\Service\Intake\DuplicatePolicy;
use OCA\Dossiq\Service\Intake\IntakeFanOut;
use OCA\Dossiq\Service\Intake\IntakeRequirements;
use OCA\Dossiq\Service\Intake\TriageSleep;
use OCA\Dossiq\Service\Routing\RefusalOutcome;
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
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
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
	 * @param CaseTypeResolver      $caseTypeResolver The effective case type.
	 * @param IntakeRequirements    $requirements     What must be answered, and when.
	 * @param CaseClassification    $classification   The facets and the access rule.
	 * @param ClassificationSchemes $schemes          The schemes this instance knows.
	 * @param AssigneeNarrowing     $narrowing        Who may hold the case.
	 * @param DuplicatePolicy       $duplicates       What this case type does about a case that already exists.
	 * @param RefusalOutcome        $refusal          Refusal as an outcome of routing.
	 * @param TriageSleep           $sleep            The triage sleep.
	 * @param IntakeFanOut          $fanOut           One submission, several cases.
	 * @param IntakeLog             $log              The triage queue.
	 * @param IntakePolicy          $policy           Who may run intake.
	 * @param CaseAccessGuard       $caseAccessGuard  Per-case authorization.
	 * @param IUserSession          $userSession      The caller.
	 * @param LoggerInterface       $logger           Logger.
	 */
	public function __construct(
		IRequest $request,
		private readonly CaseTypeResolver $caseTypeResolver,
		private readonly IntakeRequirements $requirements,
		private readonly CaseClassification $classification,
		private readonly ClassificationSchemes $schemes,
		private readonly AssigneeNarrowing $narrowing,
		private readonly DuplicatePolicy $duplicates,
		private readonly RefusalOutcome $refusal,
		private readonly TriageSleep $sleep,
		private readonly IntakeFanOut $fanOut,
		private readonly IntakeLog $log,
		private readonly IntakePolicy $policy,
		private readonly CaseAccessGuard $caseAccessGuard,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
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
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 * @spec openspec/changes/duplicate-warning-at-intake/specs/friendly-case-create-form/spec.md
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
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	#[NoAdminRequired]
	public function refuse(string $caseId, string $reason = ''): JSONResponse {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		$forbidden = $this->refuseUnauthorized(caseId: $caseId, user: $user);
		if ($forbidden !== null) {
			return $forbidden;
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
	 * The triage queue, with its sleeping items taken out.
	 *
	 * @return JSONResponse The items a handler should see.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
	 */
	#[NoAdminRequired]
	public function queue(): JSONResponse {
		$refusal = $this->requireIntakeRole();
		if ($refusal !== null) {
			return $refusal;
		}

		$items = [];
		foreach (TriageSleep::SLEEPABLE_OUTCOMES as $outcome) {
			$items = array_merge(
				$items,
				$this->log->search(filters: ['outcome' => $outcome, '_limit' => self::PAGE_SIZE])
			);
		}

		$awake = $this->sleep->awake(queue: $items);

		return new JSONResponse(
			[
				'results' => $awake,
				'sleeping' => (count($items) - count($awake)),
			]
		);
	}//end queue()

	/**
	 * Put a triage item to sleep until a date.
	 *
	 * @param string $entryId The intake log entry.
	 * @param string $until   The date it comes back.
	 * @param string $reason  Why nothing is done until then.
	 *
	 * @return JSONResponse The sleep record, or the refusal.
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
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
	 * @spec openspec/changes/intake-triage-and-refusal/specs/kcc-routing/spec.md
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
		$user = $this->userSession->getUser();
		if ($user === null) {
			return new JSONResponse(['message' => 'unauthenticated'], Http::STATUS_UNAUTHORIZED);
		}

		if ($this->policy->mayRunIntake(userId: $user->getUID()) === false) {
			return new JSONResponse(['message' => 'Not authorized'], Http::STATUS_FORBIDDEN);
		}

		return null;
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
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end callerId()
}//end class
