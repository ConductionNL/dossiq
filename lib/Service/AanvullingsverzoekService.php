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
use OCA\Dossiq\Service\Support\SearchesObjects;
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
	 * The states a request can be in, and the only one that is still waiting.
	 *
	 * @var array<int, string>
	 */
	public const STATES = ['open', 'answered', 'expired', 'withdrawn'];

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
	 */
	public function __construct(
		private readonly InformationRequestService $act,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
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
	): array {
		if ($this->openFor(caseId: $caseId) !== null) {
			throw new RefusedException(
				rule: 'aanvullingsverzoek-already-open',
				sentence: 'This case is already waiting on the applicant. Record the answer to the open request, or withdraw it, before asking again.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		// THE ACT FIRST. It refuses when there is no running term, it sends the
		// letter, and only a send that worked suspends the clock. A record
		// written before this would survive a failed send.
		$outcome = $this->act->ask(
			caseId: $caseId,
			items: $items,
			recipient: $recipient,
			durationDays: $durationDays,
			rationale: $rationale,
		);

		if (($outcome['sent'] ?? false) !== true) {
			throw new RefusedException(
				rule: 'aanvullingsverzoek-not-sent',
				sentence: 'The request could not be sent, so the term was not suspended and nothing was recorded. The failure is on the term.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

		$instance = (array)($outcome['instance'] ?? []);
		$now = new DateTimeImmutable();

		$request = [
			'case' => $caseId,
			'summary' => $this->summaryOf(items: $items),
			'party' => trim($party),
			'recipient' => trim($recipient),
			'pauseReason' => trim($pauseReason),
			'rationale' => trim($rationale),
			'missingItems' => $this->itemRows(items: $items),
			'requestedBy' => $userId,
			'requestedAt' => $now->format('c'),
			// The date the applicant is held to is the one the ACT computed and
			// suspended the term to, read back off the instance. Computing it
			// again here would be a second arithmetic that can disagree with
			// the clock, which is the whole defect REQ-TOT-002 guards.
			'hersteltermijn' => (string)($instance['pauseDeadline'] ?? ''),
			'state' => 'open',
			'deadlineInstance' => (string)($instance['id'] ?? ($instance['@self']['id'] ?? '')),
			'pauseDays' => max(1, $durationDays),
		];

		$written = $this->write(request: $request);
		$this->markCaseWaiting(caseId: $caseId, since: $now->format('c'));

		$this->logger->info(
			'Dossiq: an aanvullingsverzoek was sent and written down',
			['app' => Application::APP_ID, 'case' => $caseId, 'items' => count($request['missingItems'])]
		);

		return $written;
	}//end ask()

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

		[$objectService, $register] = $this->openRegister();

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: self::SCHEMA,
				filters: ['case' => $caseId, '_limit' => 100]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the aanvullingsverzoeken on a case could not be read',
				['app' => Application::APP_ID, 'case' => $caseId, 'error' => $e->getMessage()]
			);
			throw new RefusedException(
				rule: 'aanvullingsverzoek-unreadable',
				sentence: 'The requests on this case could not be read, so nothing was changed.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}

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

		return (int)$from->diff(($now ?? new DateTimeImmutable()))->days;
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

		try {
			if (trim($id) !== '') {
				$stored = $this->patchObjectAsArray(
					objectService: $objectService,
					register: $register,
					schema: self::SCHEMA,
					id: trim($id),
					changes: $request
				);
			} else {
				$stored = $this->saveObjectAsArray(
					objectService: $objectService,
					register: $register,
					schema: self::SCHEMA,
					object: $request
				);
			}
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: an aanvullingsverzoek could not be written',
				['app' => Application::APP_ID, 'error' => $e->getMessage()]
			);
			throw new RefusedException(
				rule: 'aanvullingsverzoek-not-written',
				sentence: 'The request could not be written down.',
				status: RefusedException::STATUS_INDETERMINATE,
			);
		}//end try

		return ($stored ?? $request);
	}//end write()

	/**
	 * The item rows a list of asked-for things becomes.
	 *
	 * @param array<int, string> $items What was asked for.
	 *
	 * @return array<int, array<string, mixed>> The rows, none received yet.
	 */
	private function itemRows(array $items): array {
		$rows = [];
		foreach ($items as $item) {
			$text = trim((string)$item);
			if ($text === '') {
				continue;
			}

			$rows[] = ['item' => $text, 'received' => false];
		}

		return $rows;
	}//end itemRows()

	/**
	 * One line naming what a request is about.
	 *
	 * @param array<int, string> $items What was asked for.
	 *
	 * @return string The summary.
	 */
	private function summaryOf(array $items): string {
		$clean = [];
		foreach ($items as $item) {
			$text = trim((string)$item);
			if ($text !== '') {
				$clean[] = $text;
			}
		}

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
