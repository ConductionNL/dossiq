<?php

/**
 * Dossiq AanvullingsverzoekService.
 *
 * The request to complete a submission, as a record (Awb 4:5).
 *
 * 🔴 THIS DOES NOT REBUILD THE ACT, AND THAT IS THE POINT.
 * `InformationRequestService::ask()` already sends the letter and suspends the
 * term as ONE act, in that order, so a suspended clock can never exist without
 * a letter having gone out. Writing a second ask here would give the codebase
 * two paths to a statutory suspension, and the second one would be the one that
 * forgets something. So this delegates the act and adds what did not exist: an
 * OBJECT.
 *
 * WHY AN OBJECT AND NOT A PAUSE. A pause is what the clock does; a request is
 * what a person did. They do not have the same lifetime. A request answered
 * late is still evidence years afterwards, and the timer it suspended is long
 * gone. More plainly: "which of our cases are waiting on an applicant, and
 * since when, and for what" needs something to count, and a suspended timer
 * says the clock stopped, not what was asked (design D-1).
 *
 * THE ORDER OF THE TWO WRITES IS DELIBERATE. The act runs FIRST and the record
 * is written after it succeeds. A record written first would survive a send
 * that failed, and the case would show a request the applicant never got, on a
 * clock that never stopped — which is the worse of the two half-states,
 * because it reads as complete.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Portal\ResidentQuestionTask;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\Term\DayCount;
use OCA\Dossiq\Woo\WooRequestIntake;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Asking the applicant, written down as a record that outlives the pause.
 *
 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
 */
class AanvullingsverzoekService {

	use SearchesObjects;

	/**
	 * The schema the requests are objects of.
	 */
	public const SCHEMA = 'aanvullingsverzoek';

	/**
	 * A request for missing documents, the ordinary kind.
	 */
	public const KIND_DOCUMENTS = 'aanvulling';

	/**
	 * A request to make a Woo request more precise (Woo art. 4.1 lid 5).
	 */
	public const KIND_CLARIFICATION = 'verduidelijking';

	/**
	 * The states a request can be in, and the only one that is still waiting.
	 *
	 * @var array<int, string>
	 */
	public const STATES = ['open', 'answered', 'expired', 'withdrawn'];

	/**
	 * Who raised a request's portal task, on the task and on its close.
	 */
	public const PORTAL_TASK_SOURCE = 'dossiq.aanvullingsverzoek';

	/**
	 * Constructor.
	 *
	 * There is deliberately NO TermijnService here. Everything this class needs
	 * to know about the clock comes back on the instance the act already
	 * returned, so reaching for the term service again would be a second read
	 * of a fact it was just handed — and a second chance to disagree with it.
	 *
	 * @param InformationRequestService $act             The ask that sends and
	 *                                                   suspends as one act.
	 * @param SettingsService           $settingsService The OpenRegister seam.
	 * @param LoggerInterface           $logger          Structured logger.
	 * @param ResidentQuestionTask|null $portalTask      Turns the request into a task in the resident's
	 *                                                   portal (decision 169), or null in a test that is not about it.
	 * @param DayCount                  $days            Counts the days between two moments.
	 */
	public function __construct(
		private readonly InformationRequestService $act,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?ResidentQuestionTask $portalTask = null,
		private readonly DayCount $days = new DayCount(),
	) {
	}//end __construct()

	/**
	 * Ask the applicant for what is missing, and write the request down.
	 *
	 * @param string             $caseId       The case UUID.
	 * @param array<int, string> $items        What is missing, one line each.
	 * @param string             $recipient    Who to send it to.
	 * @param integer            $durationDays How long the applicant is given.
	 * @param string             $userId       Who is asking.
	 * @param string             $pauseReason  The administered reason that types it.
	 * @param string             $rationale    Why the case cannot be decided yet.
	 * @param string             $party        The party being asked.
	 * @param string|null        $question     The one question of a `verduidelijking` (Woo only), as the
	 *                                         requester reads it; null asks for documents.
	 *
	 * @return array<string, mixed> The written request.
	 *
	 * @throws RefusedException When the act refuses, or the record cannot be written.
	 *
	 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
	 */
	public function ask(
		string $caseId,
		array $items,
		string $recipient,
		int $durationDays,
		string $userId,
		string $pauseReason = '',
		string $rationale = '',
		string $party = '',
		?string $question = null,
	): array {
		$ask = compact('caseId', 'items', 'recipient', 'durationDays', 'userId', 'pauseReason', 'rationale', 'party');
		$ask += $this->kindOf(ask: $ask, question: $question);

		$instance = $this->sentInstance(ask: $ask);
		$ask['now'] = (new DateTimeImmutable())->format('c');

		$request = $this->requestRow(ask: $ask, instance: $instance);
		$written = $this->write(request: $request);
		$written = $this->raisePortalTask(written: $written, userId: $userId);
		$this->markCaseWaiting(caseId: $caseId, since: $ask['now']);

		$this->logger->info(
			'Dossiq: an aanvullingsverzoek was sent and written down',
			['app' => Application::APP_ID, 'case' => $caseId, 'items' => count($request['missingItems'])]
		);

		return $written;
	}//end ask()

	/**
	 * The kind of request and what its letter asks: the documents, or the one question of a clarification.
	 *
	 * A clarification the case cannot take is refused before any letter goes
	 * out: a `verduidelijking` asks one question on a Woo case and lists no
	 * documents (woo-dossier-shared-with-the-requester REQ-WDS-004).
	 *
	 * @param array{caseId: string, items: array<int, string>} $ask      The case and the documents asked for.
	 * @param string|null                                      $question The question of a clarification, or null.
	 *
	 * @return array{kind: string, asked: array<int, string>} The kind and the lines the letter asks.
	 *
	 * @throws RefusedException When a clarification is not one question on a Woo case.
	 *
	 * @spec openspec/changes/woo-dossier-shared-with-the-requester/specs/portal-contribution/spec.md#requirement-a-clarification-asks-one-question-in-plain-words-req-wds-004
	 */
	private function kindOf(array $ask, ?string $question): array {
		if ($question === null) {
			return ['kind' => self::KIND_DOCUMENTS, 'asked' => $ask['items']];
		}

		// A Woo request on any version of the case type: the first version by
		// its fixed id, a later one by the identifier the chain shares.
		$wooKeys = [WooRequestIntake::CASE_TYPE_ID, WooRequestIntake::CASE_TYPE_IDENTIFIER];
		if (in_array($this->caseTypeOf(caseId: $ask['caseId']), $wooKeys, true) === false) {
			throw new RefusedException(
				rule: 'aanvullingsverzoek-clarification-woo-only',
				sentence: 'A clarification can only be asked on a Woo request.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		if (trim($question) === '' || array_filter(array_map('trim', array_map('strval', $ask['items']))) !== []) {
			throw new RefusedException(
				rule: 'aanvullingsverzoek-clarification-one-question',
				sentence: 'A clarification asks one question and lists no documents.',
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		// The letter carries the question as the one thing asked; the
		// record keeps it as the summary and lists no documents.
		return ['kind' => self::KIND_CLARIFICATION, 'asked' => [trim($question)]];
	}//end kindOf()

	/**
	 * Refuse while a request is open, else send the letter and suspend the term, and answer the suspended term instance.
	 *
	 * THE ACT FIRST. It refuses when there is no running term, it sends the
	 * letter, and only a send that worked suspends the clock. A record
	 * written before this would survive a failed send.
	 *
	 * @param array{caseId: string, asked: array, recipient: string, durationDays: int, rationale: string, pauseReason: string} $ask The ask.
	 *
	 * @return array<string, mixed> The term instance the act suspended.
	 *
	 * @throws RefusedException When a request is open, the act refuses or the letter was not sent.
	 */
	private function sentInstance(array $ask): array {
		if ($this->openFor(caseId: $ask['caseId']) !== null) {
			throw new RefusedException(
				rule: 'aanvullingsverzoek-already-open',
				sentence: 'This case is already waiting on the applicant. Record the answer to the open request, or withdraw it, before asking again.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		$outcome = $this->act->ask(
			caseId: $ask['caseId'],
			items: $ask['asked'],
			recipient: $ask['recipient'],
			durationDays: $ask['durationDays'],
			rationale: $ask['rationale'],
			pauseReason: $ask['pauseReason'],
		);

		if (($outcome['sent'] ?? false) !== true) {
			throw new RefusedException(
				rule: 'aanvullingsverzoek-not-sent',
				sentence: 'The request could not be sent, so the term was not suspended and nothing was recorded. The failure is on the term.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		return (array)($outcome['instance'] ?? []);
	}//end sentInstance()

	/**
	 * The request as it is written down, after the letter went out.
	 *
	 * @param array<string, mixed> $ask      The ask, its kind, what was asked, who asked and when.
	 * @param array<string, mixed> $instance The suspended term instance.
	 *
	 * @return array<string, mixed> The request row.
	 */
	private function requestRow(array $ask, array $instance): array {
		$request = [
			'case' => $ask['caseId'],
			'summary' => $this->summaryOf(items: $ask['asked']),
			'party' => trim($ask['party']),
			'recipient' => trim($ask['recipient']),
			'pauseReason' => trim($ask['pauseReason']),
			'rationale' => trim($ask['rationale']),
			'missingItems' => $this->itemRows(items: $ask['items']),
			'kind' => $ask['kind'],
			'requestedBy' => $ask['userId'],
			'requestedAt' => $ask['now'],
			// The date the applicant is held to is the one the ACT computed and
			// suspended the term to, read back off the instance. Computing it
			// again here would be a second arithmetic that can disagree with
			// the clock, which is the whole defect REQ-TOT-002 guards.
			'hersteltermijn' => (string)($instance['pauseDeadline'] ?? ''),
			'state' => 'open',
			'deadlineInstance' => (string)($instance['id'] ?? ($instance['@self']['id'] ?? '')),
			'pauseDays' => max(1, $ask['durationDays']),
		];

		// WHOSE PORTAL ACCOUNT MAY READ THIS. Copied from the case, because
		// the portal scopes a read by one field on the row it reads and cannot
		// follow `case` to find it (site-resident-portal-design D1). A case
		// without a portal subject leaves this empty, and the request then
		// reaches nobody rather than everybody.
		return $request + array_diff(['portalSubject' => $this->portalSubjectOf(caseId: $ask['caseId'])], ['']);
	}//end requestRow()

	/**
	 * Raise the resident's portal task for a request just written, and
	 * remember it on the request so the request can close it (decision 169).
	 *
	 * A task that cannot be raised, or remembered, leaves the request as it
	 * is: the letter went out and the term is suspended, and the question still
	 * shows under "Vragen aan u".
	 *
	 * @param array<string, mixed> $written The stored request.
	 * @param string               $userId  The handler who asked.
	 *
	 * @return array<string, mixed> The request, with `portalTask` when one was raised.
	 *
	 * @spec openspec/changes/an-aanvullingsverzoek-is-a-portal-task/specs/termijn-pause-extension/spec.md#requirement-an-aanvullingsverzoek-is-a-task-in-the-residents-portal-req-avr-06
	 */
	private function raisePortalTask(array $written, string $userId): array {
		if ($this->portalTask === null) {
			return $written;
		}

		$id = $this->requestId(written: $written);
		$task = $this->portalTask->raise(question: $this->portalQuestion(written: $written, id: $id), actor: $userId);
		if ($task === null || $id === '') {
			return $written;
		}

		return $this->rememberTask(written: $written, task: $task);
	}//end raisePortalTask()

	/**
	 * The trimmed id of a stored request, '' when it has none.
	 *
	 * @param array<string, mixed> $written The stored request.
	 *
	 * @return string The id.
	 */
	private function requestId(array $written): string {
		return trim((string)($written['id'] ?? ($written['uuid'] ?? '')));
	}//end requestId()

	/**
	 * The question the resident's portal task asks: what is missing, on which case, by when.
	 *
	 * @param array<string, mixed> $written The stored request.
	 * @param string               $id      Its id.
	 *
	 * @return array<string, mixed> The question.
	 */
	private function portalQuestion(array $written, string $id): array {
		$items = array_map(
			static fn (mixed $row): string => (string)(((array)$row)['item'] ?? (((array)$row)[0] ?? '')),
			(array)($written['missingItems'] ?? [])
		);

		return [
			'id' => $id,
			'case' => (string)($written['case'] ?? ''),
			'portalSubject' => (string)($written['portalSubject'] ?? ''),
			'title' => 'Vul uw aanvraag aan',
			'items' => $items,
			'due' => (string)($written['hersteltermijn'] ?? ''),
			'source' => self::PORTAL_TASK_SOURCE,
		];
	}//end portalQuestion()

	/**
	 * Remember the raised task on the request; a request that cannot remember it is left as written.
	 *
	 * @param array<string, mixed> $written The stored request.
	 * @param string               $task    The raised task's uuid.
	 *
	 * @return array<string, mixed> The request, with `portalTask` when it was remembered.
	 */
	private function rememberTask(array $written, string $task): array {
		$id = $this->requestId(written: $written);
		try {
			$this->write(request: ['portalTask' => $task], id: $id);
		} catch (RefusedException $e) {
			$this->logger->warning(
				'Dossiq: the portal task was raised, but the request could not remember it',
				['app' => Application::APP_ID, 'request' => $id, 'task' => $task]
			);
			return $written;
		}

		return array_merge($written, ['portalTask' => $task]);
	}//end rememberTask()

	/**
	 * The case's type: the identifier its version chain shares when the case
	 * carries one, else the uuid of its version, or '' when it cannot be read.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return string The case type identifier or id.
	 */
	protected function caseTypeOf(string $caseId): string {
		try {
			[$objectService, $register] = $this->openRegister();
			$schema = (string)$this->settingsService->getConfigValue('case_schema');
			if ($schema === '') {
				return '';
			}

			$case = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $caseId);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the case type could not be read for a request',
				['app' => Application::APP_ID, 'case' => $caseId, 'error' => $e->getMessage()]
			);
			return '';
		}

		// The first of the two the case carries: the shared identifier, else the version's uuid.
		$wanted = ['caseType' => true, 'caseTypeIdentifier' => true];
		$keys = array_filter(array_map('trim', array_map('strval', array_intersect_key(($case ?? []), $wanted))));
		krsort($keys);

		return (string)(reset($keys));
	}//end caseTypeOf()

	/**
	 * The case's portal subject, or '' when it has none or cannot be read.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return string The portal subject.
	 *
	 * Protected rather than private so a test can hand the case's subject in
	 * without an OpenRegister, the way `write()` and `markCaseWaiting()` are
	 * already seams in this class.
	 *
	 * @spec openspec/specs/portal-contribution/spec.md#requirement-the-resident-sees-what-the-organisation-still-needs-from-them-req-srpd-001
	 */
	protected function portalSubjectOf(string $caseId): string {
		try {
			// 🔑 THE REGISTER LOOKUP IS INSIDE THE TRY, and it has to be:
			// `openRegister()` REFUSES (aanvullingsverzoek_no_register) when
			// the instance has none configured, so reading the subject outside
			// this block turned a working ask() into a refusal — the existing
			// tests of ask() caught exactly that.
			[$objectService, $register] = $this->openRegister();
			$schema = (string)$this->settingsService->getConfigValue('case_schema');
			if ($schema === '') {
				return '';
			}

			$case = $this->findObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId
			);
		} catch (Throwable $e) {
			// A request that cannot name its reader is still a request. It is
			// written without one and stays invisible in the portal, which is
			// the safe half of the choice.
			$this->logger->warning(
				'Dossiq: the case portal subject could not be read, so the request is not shown in the portal',
				['app' => Application::APP_ID, 'case' => $caseId, 'error' => $e->getMessage()]
			);
			return '';
		}

		return trim((string)(($case ?? [])['portalSubject'] ?? ''));
	}//end portalSubjectOf()

	/**
	 * The open request on a case, or null when nothing is outstanding.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<string, mixed>|null The request, or null.
	 *
	 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
	 */
	public function openFor(string $caseId): ?array {
		foreach ($this->forCase(caseId: $caseId) as $request) {
			if ((string)($request['state'] ?? '') === 'open') {
				return $request;
			}
		}

		return null;
	}//end openFor()

	/**
	 * Every request ever sent on a case, newest last.
	 *
	 * @param string $caseId The case UUID.
	 *
	 * @return array<int, array<string, mixed>> The requests.
	 *
	 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
	 */
	public function forCase(string $caseId): array {
		$caseId = trim($caseId);
		if ($caseId === '') {
			return [];
		}

		$rows = $this->requestRows(caseId: $caseId);
		usort(
			$rows,
			static fn (array $a, array $b): int => strcmp(
				(string)($a['requestedAt'] ?? ''),
				(string)($b['requestedAt'] ?? '')
			)
		);

		return $rows;
	}//end forCase()

	/**
	 * The requests stored on a case, in the store's order.
	 *
	 * @param string $caseId The trimmed case UUID.
	 *
	 * @return array<int, array<string, mixed>> The requests.
	 *
	 * @throws RefusedException When the register is not configured or the requests cannot be read.
	 */
	private function requestRows(string $caseId): array {
		[$objectService, $register] = $this->openRegister();

		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: self::SCHEMA,
				filters: ['case' => $caseId, '_limit' => 100]
			);
		} catch (Throwable $e) {
			throw $this->unreadable(caseId: $caseId, e: $e);
		}
	}//end requestRows()

	/**
	 * Log a case whose requests cannot be read, as the refusal to throw.
	 *
	 * @param string    $caseId The case.
	 * @param Throwable $e      What the store threw.
	 *
	 * @return RefusedException The refusal.
	 */
	private function unreadable(string $caseId, Throwable $e): RefusedException {
		$this->logger->warning(
			'Dossiq: the aanvullingsverzoeken on a case could not be read',
			['app' => Application::APP_ID, 'case' => $caseId, 'error' => $e->getMessage()]
		);

		return new RefusedException(
			rule: 'aanvullingsverzoek-unreadable',
			sentence: 'The requests on this case could not be read, so nothing was changed.',
			status: RefusedException::STATUS_INDETERMINATE,
		);
	}//end unreadable()

	/**
	 * How long a request has been open, in days.
	 *
	 * @param array<string, mixed>   $request The request.
	 * @param DateTimeImmutable|null $now     Today.
	 *
	 * @return integer The days, 0 when the moment cannot be read.
	 *
	 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
	 */
	public function daysOpen(array $request, ?DateTimeImmutable $now = null): int {
		$sent = trim((string)($request['requestedAt'] ?? ''));
		if ($sent === '') {
			return 0;
		}

		try {
			$from = new DateTimeImmutable($sent);
		} catch (Throwable) {
			return 0;
		}

		return $this->days->between(from: $from, to: ($now ?? new DateTimeImmutable()));
	}//end daysOpen()

	/**
	 * Write the derived waiting flag onto the case.
	 *
	 * A READ CONVENIENCE, NEVER A SECOND TRUTH. The requests are the count; the
	 * case carries the boolean only so the work list can facet on it without
	 * reading every request of every case (design D-5). It is written by the
	 * acts that change the answer rather than derived on save, because the
	 * requests are separate objects and a save of the case knows nothing about
	 * them.
	 *
	 * @param string      $caseId The case UUID.
	 * @param string|null $since  When the open request was sent, or null to clear.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
	 */
	public function markCaseWaiting(string $caseId, ?string $since): void {
		[$objectService, $register] = $this->openRegister();
		$schema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($schema === '') {
			return;
		}

		try {
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $caseId,
				changes: [
					'waitingOnApplicant' => ($since !== null),
					'waitingOnApplicantSince' => $since,
				]
			);
		} catch (Throwable $e) {
			// The flag is a convenience over rows that are already correct. A
			// failure here must not undo an act that has already sent a letter
			// and suspended a statutory term, so it is logged and the request
			// stands. The list is briefly stale; the requests are not.
			$this->logger->warning(
				'Dossiq: the waiting-on-applicant flag could not be written; the requests themselves are correct',
				['app' => Application::APP_ID, 'case' => $caseId, 'error' => $e->getMessage()]
			);
		}
	}//end markCaseWaiting()

	/**
	 * Write a request, new or changed.
	 *
	 * @param array<string, mixed> $request The request.
	 * @param string               $id      Its id, or the empty string to create.
	 *
	 * @return array<string, mixed> The stored request.
	 *
	 * @throws RefusedException When the write fails.
	 *
	 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
	 */
	public function write(array $request, string $id = ''): array {
		[$objectService, $register] = $this->openRegister();
		$write = new RequestWrite(objectService: $objectService, register: $register);
		$write->request = $request;
		$write->id = trim($id);

		try {
			if ($write->id !== '') {
				return $this->patchRequest(write: $write);
			}

			$stored = $this->createRequest(write: $write);
		} catch (Throwable $e) {
			throw $this->notWritten(e: $e);
		}

		return ($stored ?? $request);
	}//end write()

	/**
	 * Save a new request.
	 *
	 * @param RequestWrite $write The request to create.
	 *
	 * @return array<string, mixed>|null The stored request, or null when the store answered none.
	 *
	 * @throws Throwable When the save fails.
	 */
	private function createRequest(RequestWrite $write): ?array {
		return $this->saveObjectAsArray(
			objectService: $write->objectService,
			register: $write->register,
			schema: self::SCHEMA,
			object: $write->request
		);
	}//end createRequest()

	/**
	 * Patch an existing request, closing its portal task when it leaves `open`.
	 *
	 * A request that leaves `open` takes its portal task with it (decision
	 * 169). Every change of state is written here, so this is the one place
	 * that sees all of them.
	 *
	 * @param RequestWrite $write The changes and the request they change.
	 *
	 * @return array<string, mixed> The stored request.
	 *
	 * @throws Throwable When the patch or the closing of the task fails.
	 */
	private function patchRequest(RequestWrite $write): array {
		$patched = ($this->patchObjectAsArray(
			objectService: $write->objectService,
			register: $write->register,
			schema: self::SCHEMA,
			id: $write->id,
			changes: $write->request
		) ?? $write->request);

		$request = $write->request;
		if (array_key_exists('state', $request) === true && $request['state'] !== 'open') {
			$this->portalTask?->close(
				taskUuid: (string)($patched['portalTask'] ?? ''),
				reason: sprintf('The aanvullingsverzoek is %s in dossiq.', (string)$request['state']),
				source: self::PORTAL_TASK_SOURCE
			);
		}

		return $patched;
	}//end patchRequest()

	/**
	 * Log a write the store refused, as the refusal to throw.
	 *
	 * @param Throwable $e What the store threw.
	 *
	 * @return RefusedException The refusal.
	 */
	private function notWritten(Throwable $e): RefusedException {
		$this->logger->error(
			'Dossiq: an aanvullingsverzoek could not be written',
			['app' => Application::APP_ID, 'error' => $e->getMessage()]
		);

		return new RefusedException(
			rule: 'aanvullingsverzoek-not-written',
			sentence: 'The request could not be written down.',
			status: RefusedException::STATUS_INDETERMINATE,
		);
	}//end notWritten()

	/**
	 * The item rows a list of asked-for things becomes.
	 *
	 * @param array<int, string> $items What was asked for.
	 *
	 * @return array<int, array<string, mixed>> The rows, none received yet.
	 */
	private function itemRows(array $items): array {
		return array_map(
			static fn (string $text): array => ['item' => $text, 'received' => false],
			$this->cleanLines(items: $items)
		);
	}//end itemRows()

	/**
	 * The asked-for lines, trimmed, without the blank ones.
	 *
	 * @param array<int, string> $items What was asked for.
	 *
	 * @return array<int, string> The non-blank lines, in order.
	 */
	private function cleanLines(array $items): array {
		$clean = [];
		foreach ($items as $item) {
			$text = trim((string)$item);
			if ($text !== '') {
				$clean[] = $text;
			}
		}

		return $clean;
	}//end cleanLines()

	/**
	 * One line naming what a request is about.
	 *
	 * @param array<int, string> $items What was asked for.
	 *
	 * @return string The summary.
	 */
	private function summaryOf(array $items): string {
		$clean = $this->cleanLines(items: $items);
		if ($clean === []) {
			return 'Aanvullingsverzoek';
		}

		if (count($clean) === 1) {
			return $clean[0];
		}

		return sprintf('%s and %d more', $clean[0], (count($clean) - 1));
	}//end summaryOf()

	/**
	 * The OpenRegister seam and the register, or a refusal.
	 *
	 * @return array{0: object, 1: string} The object service and the register.
	 *
	 * @throws RefusedException When OpenRegister or the register is absent.
	 */
	private function openRegister(): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');

		if ($objectService === null || $register === '') {
			throw new RefusedException(
				rule: 'aanvullingsverzoek-no-register',
				sentence: 'The register that holds these requests is not configured, so nothing was changed.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		return [$objectService, $register];
	}//end openRegister()
}//end class
