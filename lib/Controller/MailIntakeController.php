<?php

/**
 * Dossiq Mail Intake Controller
 *
 * The intake log as a surface: what the mailbox processed, why, and the two
 * acts a handler can perform on a message that should not have come to us.
 *
 * 🔴 EVERY METHOD HERE IS GATED ON THE INTAKE ROLE, NOT ON BEING LOGGED IN. The
 * log holds the original source of every message the mailbox received, which is
 * personal data about people who never agreed to it being readable by the whole
 * instance. `#[NoAdminRequired]` opens an endpoint to every authenticated user,
 * so each method calls {@see self::requireIntakeRole()} first and answers 403
 * when the caller is neither an administrator nor in the configured group. An
 * instance that has configured no group has not said "anyone may read it".
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
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Email\BounceAction;
use OCA\Dossiq\Service\Email\Filters\FilterPipeline;
use OCA\Dossiq\Service\Email\Filters\FilterVerdict;
use OCA\Dossiq\Service\Email\InboundMailIntake;
use OCA\Dossiq\Service\Email\InboundMessage;
use OCA\Dossiq\Service\Email\IntakeLog;
use OCA\Dossiq\Service\Email\IntakePolicy;
use OCA\Dossiq\Service\Email\JunkRules;
use OCA\Dossiq\Service\Email\MoveAction;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IRequest;
use OCP\IUserSession;

/**
 * Reads the intake log and performs the two named acts on a message.
 *
 * @psalm-suppress UnusedClass
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — the surface reaches every
 *  part of the intake path by design; each collaborator knows only its own.
 *
 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
 */
class MailIntakeController extends Controller {

	/**
	 * How many entries one page of the log holds.
	 */
	private const PAGE_SIZE = 100;

	/**
	 * Constructor.
	 *
	 * @param IRequest          $request     Inbound request.
	 * @param IntakeLog         $log         The intake log.
	 * @param IntakePolicy      $policy      Who may run intake, and the per-case-type policy.
	 * @param FilterPipeline    $pipeline    The declared filter order.
	 * @param JunkRules         $junkRules   The readable junk rules.
	 * @param BounceAction      $bounce      The doorzendplicht act.
	 * @param MoveAction        $move        The mailbox act.
	 * @param InboundMailIntake $intake      The intake path, for a released message.
	 * @param IUserSession      $userSession The caller.
	 * @param ITimeFactory      $time        Clock.
	 */
	public function __construct(
		IRequest $request,
		private readonly IntakeLog $log,
		private readonly IntakePolicy $policy,
		private readonly FilterPipeline $pipeline,
		private readonly JunkRules $junkRules,
		private readonly BounceAction $bounce,
		private readonly MoveAction $move,
		private readonly InboundMailIntake $intake,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $time,
	) {
		parent::__construct(appName: Application::APP_ID, request: $request);
	}//end __construct()

	/**
	 * The intake log, optionally narrowed by sender or outcome.
	 *
	 * @param string $sender  Narrow to one sender address.
	 * @param string $outcome Narrow to one outcome.
	 *
	 * @return JSONResponse The entries, or 403 when the caller lacks the intake role.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	#[NoAdminRequired]
	public function index(string $sender = '', string $outcome = ''): JSONResponse {
		$refusal = $this->requireIntakeRole();
		if ($refusal !== null) {
			return $refusal;
		}

		$filters = ['_limit' => self::PAGE_SIZE];
		if ($sender !== '') {
			$filters['sender'] = InboundMessage::addressIn(value: $sender);
		}

		if ($outcome !== '') {
			$filters['outcome'] = $outcome;
		}

		return new JSONResponse(
			[
				'results' => $this->log->search(filters: $filters),
				'filterOrder' => $this->pipeline->declaredOrder(),
				'junkRules' => $this->junkRules->rules(),
			]
		);
	}//end index()

	/**
	 * One entry, original included.
	 *
	 * @param string $entryId The entry.
	 *
	 * @return JSONResponse The entry, 403 without the intake role, 404 when it is gone.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	#[NoAdminRequired]
	public function show(string $entryId): JSONResponse {
		$refusal = $this->requireIntakeRole();
		if ($refusal !== null) {
			return $refusal;
		}

		$entry = $this->log->find(entryId: $entryId);
		if ($entry === null) {
			return new JSONResponse(['message' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		return new JSONResponse($entry);
	}//end show()

	/**
	 * Release a quarantined message into a case.
	 *
	 * The release is recorded with the person who made it, because a message
	 * that became a case after a human overruled a failing verdict is a
	 * different fact from one that was accepted outright.
	 *
	 * @param string $entryId The entry.
	 *
	 * @return JSONResponse What the released message became.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	#[NoAdminRequired]
	public function release(string $entryId): JSONResponse {
		$refusal = $this->requireIntakeRole();
		if ($refusal !== null) {
			return $refusal;
		}

		$entry = $this->log->find(entryId: $entryId);
		if ($entry === null) {
			return new JSONResponse(['message' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		if ((string)($entry['outcome'] ?? '') !== IntakeLog::OUTCOME_QUARANTINED) {
			return new JSONResponse(['message' => 'not_quarantined'], Http::STATUS_BAD_REQUEST);
		}

		$message = $this->messageOf(entry: $entry);
		$outcome = $this->intake->fileOnCase(
			message: $message,
			verdict: FilterVerdict::accept(
				filterName: 'release',
				reason: 'Released by hand after a failing sender-authentication verdict.'
			),
			results: $this->resultsOf(entry: $entry),
			caseId: (string)($entry['case'] ?? '')
		);

		$this->log->amend(
			entryId: $entryId,
			changes: [
				'outcome' => IntakeLog::OUTCOME_RELEASED,
				'releasedBy' => $this->callerId(),
				'releasedAt' => $this->time->getDateTime()->format(DATE_ATOM),
			]
		);

		return new JSONResponse(['released' => true, 'outcome' => $outcome]);
	}//end release()

	/**
	 * Correct a junk verdict, in either direction.
	 *
	 * A message marked not junk re-enters the pipeline, because the point of a
	 * correction is that the message gets the handling it should have had. The
	 * correction itself is recorded either way.
	 *
	 * @param string  $entryId The entry.
	 * @param boolean $junk    True to mark it junk, false to mark it not junk.
	 *
	 * @return JSONResponse What the corrected message became.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	#[NoAdminRequired]
	public function junk(string $entryId, bool $junk = false): JSONResponse {
		$refusal = $this->requireIntakeRole();
		if ($refusal !== null) {
			return $refusal;
		}

		$entry = $this->log->find(entryId: $entryId);
		if ($entry === null) {
			return new JSONResponse(['message' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		if ($junk === true) {
			$this->log->amend(
				entryId: $entryId,
				changes: [
					'outcome' => IntakeLog::OUTCOME_QUARANTINED,
					'junkRule' => 'corrected-by-' . $this->callerId(),
					'reason' => 'Marked as junk by hand.',
				]
			);

			return new JSONResponse(['junk' => true]);
		}

		$outcome = $this->intake->process(message: $this->messageOf(entry: $entry));
		$this->log->amend(
			entryId: $entryId,
			changes: [
				'junkRule' => '',
				'reason' => 'Marked as not junk by ' . $this->callerId() . ' and sent back through the pipeline.',
			]
		);

		return new JSONResponse(['junk' => false, 'outcome' => $outcome]);
	}//end junk()

	/**
	 * Send a misdirected message on to the body it was meant for.
	 *
	 * @param string $entryId The entry.
	 * @param string $address The address it goes to.
	 * @param string $reason  Why.
	 *
	 * @return JSONResponse Whether it was sent.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	#[NoAdminRequired]
	public function bounce(string $entryId, string $address = '', string $reason = ''): JSONResponse {
		$refusal = $this->requireIntakeRole();
		if ($refusal !== null) {
			return $refusal;
		}

		$entry = $this->log->find(entryId: $entryId);
		if ($entry === null) {
			return new JSONResponse(['message' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$result = $this->bounce->bounce(
			message: $this->messageOf(entry: $entry),
			toAddress: $address,
			reason: $reason,
			actorId: $this->callerId(),
			results: $this->resultsOf(entry: $entry)
		);
		if ($result['sent'] === false) {
			return new JSONResponse(['message' => 'bounce_failed'], Http::STATUS_BAD_REQUEST);
		}

		$this->log->amend(
			entryId: $entryId,
			changes: [
				'outcome' => IntakeLog::OUTCOME_FORWARDED,
				'forwardedTo' => InboundMessage::addressIn(value: $address),
				'forwardedBy' => $this->callerId(),
				'forwardedReason' => $reason,
			]
		);

		return new JSONResponse(['sent' => true, 'entryId' => $result['entryId']]);
	}//end bounce()

	/**
	 * File a message in another folder of the same account.
	 *
	 * @param string $entryId The entry.
	 * @param string $target  The folder it goes to.
	 * @param string $note    Why.
	 *
	 * @return JSONResponse Whether the mail server confirmed it.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	#[NoAdminRequired]
	public function move(string $entryId, string $target = '', string $note = ''): JSONResponse {
		$refusal = $this->requireIntakeRole();
		if ($refusal !== null) {
			return $refusal;
		}

		$entry = $this->log->find(entryId: $entryId);
		if ($entry === null) {
			return new JSONResponse(['message' => 'not_found'], Http::STATUS_NOT_FOUND);
		}

		$result = $this->move->move(
			message: $this->messageOf(entry: $entry),
			target: $target,
			note: $note,
			actorId: $this->callerId(),
			results: $this->resultsOf(entry: $entry)
		);
		if ($result['moved'] === false) {
			return new JSONResponse(['message' => 'move_failed'], Http::STATUS_BAD_REQUEST);
		}

		$this->log->amend(
			entryId: $entryId,
			changes: [
				'outcome' => IntakeLog::OUTCOME_MOVED,
				'movedTo' => $target,
				'movedBy' => $this->callerId(),
			]
		);

		return new JSONResponse(['moved' => true, 'entryId' => $result['entryId']]);
	}//end move()

	/**
	 * Refuse a caller who is not the intake role.
	 *
	 * @return JSONResponse|null A refusal, or null when the caller may proceed.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
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
	 * spoofable, and this string goes into an audit record.
	 *
	 * @return string The uid, or '' when there is no session.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function callerId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			return '';
		}

		return $user->getUID();
	}//end callerId()

	/**
	 * Rebuild the message one log entry recorded.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return InboundMessage The message.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function messageOf(array $entry): InboundMessage {
		$message = InboundMessage::fromRow(
			row: [
				'accountId' => (int)($entry['accountId'] ?? 0),
				'mailbox' => (string)($entry['mailbox'] ?? ''),
				'uid' => (int)($entry['uid'] ?? 0),
				'messageId' => (string)($entry['mailMessageId'] ?? ''),
				'subject' => (string)($entry['subject'] ?? ''),
				'from' => (string)($entry['sender'] ?? ''),
				'to' => (string)($entry['recipient'] ?? ''),
				'sentAt' => (string)($entry['sentAt'] ?? ''),
			]
		);

		$original = (string)($entry['original'] ?? '');
		if ($original === '') {
			return $message;
		}

		return $message->withSource(source: $original);
	}//end messageOf()

	/**
	 * The four authentication results one log entry recorded.
	 *
	 * @param array<string, mixed> $entry The entry.
	 *
	 * @return array<string, string> The results.
	 *
	 * @spec openspec/changes/inbound-mail-filters/specs/inbound-mail-filters/spec.md
	 */
	private function resultsOf(array $entry): array {
		return [
			'spf' => (string)($entry['spfResult'] ?? ''),
			'dkim' => (string)($entry['dkimResult'] ?? ''),
			'dmarc' => (string)($entry['dmarcResult'] ?? ''),
			'threading' => (string)($entry['threadingResult'] ?? ''),
		];
	}//end resultsOf()
}//end class
