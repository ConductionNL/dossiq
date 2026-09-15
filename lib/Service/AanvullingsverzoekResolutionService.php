<?php

/**
 * Dossiq AanvullingsverzoekResolutionService.
 *
 * What happens to a request after it is sent: the answer, and the silence.
 *
 * 🔴 AN ANSWER NAMES WHICH ITEMS ARRIVED, NOT WHETHER "AN ANSWER CAME".
 * A request for two documents that gets one is the ordinary case, not the
 * exception, and it is why a second request is so often needed. So an answer
 * marks each item, and a request stays OPEN with its outstanding items named
 * until the handler says it is complete (design D-2). A boolean here would
 * lose the one fact the handler needs on the day the second letter goes out.
 *
 * 🔴 THE HANDLER SAYS WHEN IT IS COMPLETE, AND THE CLOCK FOLLOWS THAT.
 * Closing on "every item is ticked" alone would resume a statutory term because
 * a checkbox was ticked, and the person who has to defend that date is the
 * handler. So completion is their word, and every item must be received before
 * the word is accepted: neither half can close the request on its own.
 *
 * 🔴 EXPIRY IS A STATE, NEVER A DELETION. A request nobody answered is the
 * evidence that the applicant was given the chance, and Awb 4:5 only permits
 * refusing an application for incompleteness where the file shows what was
 * asked. So expiring writes ONE field and rewrites nothing else: the items and
 * the dates stay exactly as they were sent (design D-4).
 *
 * THE CLOCK IS RESUMED THROUGH THE EXISTING CREDIT PATH, never recomputed here.
 * `InformationRequestService::receive()` hands it to
 * `DeadlinePauseService::resumeAfterPauze`, which credits back the unused part
 * of the suspension. Doing that arithmetic again in this file would be a second
 * answer to a date a handler is judged on.
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
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * The answer, the silence, and what each does to the clock.
 *
 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
 */
class AanvullingsverzoekResolutionService {

	/**
	 * Constructor.
	 *
	 * @param AanvullingsverzoekService $requests The requests themselves.
	 * @param InformationRequestService $act      The receive that resumes the
	 *                                            clock through the existing
	 *                                            credit path.
	 * @param LoggerInterface           $logger   Structured logger.
	 */
	public function __construct(
		private readonly AanvullingsverzoekService $requests,
		private readonly InformationRequestService $act,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record what arrived, and close the request when the handler says so.
	 *
	 * @param string                 $caseId   The case UUID.
	 * @param array<int, string>     $received The items that arrived, by their text.
	 * @param boolean                $complete Whether the handler says the
	 *                                         request is now answered in full.
	 * @param string                 $userId   Who recorded it.
	 * @param DateTimeImmutable|null $when     When it arrived.
	 *
	 * @return array<string, mixed> The request as it now stands.
	 *
	 * @throws RefusedException When nothing is open, or completion is claimed
	 *                          with items still outstanding.
	 *
	 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
	 */
	public function recordAnswer(
		string $caseId,
		array $received,
		bool $complete,
		string $userId,
		?DateTimeImmutable $when = null,
	): array {
		$moment = ($when ?? new DateTimeImmutable());
		$request = $this->requests->openFor(caseId: $caseId);

		if ($request === null) {
			throw new RefusedException(
				rule: 'aanvullingsverzoek-none-open',
				sentence: 'This case is not waiting on the applicant, so there is no request to answer.',
				status: RefusedException::STATUS_REFUSED,
			);
		}

		$items = $this->markReceived(
			items: (array)($request['missingItems'] ?? []),
			received: $received,
			moment: $moment
		);
		$outstanding = $this->outstanding(items: $items);

		if ($complete === true && $outstanding !== []) {
			throw new RefusedException(
				rule: 'aanvullingsverzoek-still-outstanding',
				sentence: sprintf(
					'This cannot be closed yet. Still missing: %s.',
					implode(', ', $outstanding)
				),
				status: RefusedException::STATUS_UNPROCESSABLE,
			);
		}

		$changes = ['missingItems' => $items];

		if ($complete === false) {
			// A PARTIAL ANSWER CHANGES THE RECORD AND NOT THE CLOCK. The term
			// stays suspended, because the applicant has not supplied what was
			// asked and the case still cannot be decided.
			$this->requests->write(request: $changes, id: $this->idOf(request: $request));
			$this->logger->info(
				'Dossiq: part of an aanvullingsverzoek arrived; the term stays suspended',
				[
					'app' => Application::APP_ID,
					'case' => $caseId,
					'outstanding' => count($outstanding),
				]
			);

			return array_merge($request, $changes);
		}

		// THE ACT FIRST, again. `receive()` resumes the clock through the
		// credit path that already exists, and a request marked answered over
		// a term that did not resume would be the visible half of a broken
		// pair.
		$this->act->receive(caseId: $caseId, items: $received, when: $moment);

		$changes = array_merge(
			$changes,
			[
				'state' => 'answered',
				'answeredAt' => $moment->format('c'),
				'answeredBy' => $userId,
			]
		);

		$this->requests->write(request: $changes, id: $this->idOf(request: $request));
		$this->requests->markCaseWaiting(caseId: $caseId, since: null);

		$this->logger->info(
			'Dossiq: an aanvullingsverzoek was answered in full and the term resumed',
			['app' => Application::APP_ID, 'case' => $caseId]
		);

		return array_merge($request, $changes);
	}//end recordAnswer()

	/**
	 * Whether a request's hersteltermijn has passed without an answer.
	 *
	 * The day named is the last day the applicant has, so a request whose
	 * hersteltermijn is TODAY has not expired. That is the same reading every
	 * other term in this app uses, and getting it wrong here would refuse an
	 * application a day early.
	 *
	 * @param array<string, mixed>   $request The request.
	 * @param DateTimeImmutable|null $now     Today.
	 *
	 * @return boolean True when it has run out.
	 *
	 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
	 */
	public function hasRunOut(array $request, ?DateTimeImmutable $now = null): bool {
		if ((string)($request['state'] ?? '') !== 'open') {
			return false;
		}

		$due = trim((string)($request['hersteltermijn'] ?? ''));
		if ($due === '') {
			return false;
		}

		try {
			$deadline = new DateTimeImmutable($due);
		} catch (Throwable) {
			// An unreadable date is not evidence that an applicant failed to
			// answer, and expiring on it would destroy the very defence the
			// record exists to provide.
			return false;
		}

		// 🔴 PARSING IS NOT THE SAME AS READING. `0000-00-00` does NOT throw:
		// PHP rolls it over to a date in the year -1, which is comfortably in
		// the past, so a request carrying one from a bad import would expire
		// itself on the next timer fire and take the file's evidence with it.
		// So the parse has to ROUND-TRIP before it counts as a date.
		$asStored = substr(trim($due), 0, 10);
		if ($deadline->format('Y-m-d') !== $asStored) {
			return false;
		}

		return ($deadline->format('Y-m-d') < ($now ?? new DateTimeImmutable())->format('Y-m-d'));
	}//end hasRunOut()

	/**
	 * Expire the open request on a case, leaving everything else as it was.
	 *
	 * Writes ONE field. The items, the dates and the reason stay exactly as
	 * they were sent, because they are the evidence that the applicant was
	 * given the chance.
	 *
	 * @param string                 $caseId The case UUID.
	 * @param DateTimeImmutable|null $now    Today.
	 *
	 * @return array<string, mixed>|null The expired request, or null when there
	 *                                   was nothing open or it has not run out.
	 *
	 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
	 */
	public function expireIfRunOut(string $caseId, ?DateTimeImmutable $now = null): ?array {
		$request = $this->requests->openFor(caseId: $caseId);
		if ($request === null || $this->hasRunOut(request: $request, now: $now) === false) {
			return null;
		}

		$this->requests->write(request: ['state' => 'expired'], id: $this->idOf(request: $request));
		$this->requests->markCaseWaiting(caseId: $caseId, since: null);

		$this->logger->info(
			'Dossiq: an aanvullingsverzoek expired unanswered and stays on the file',
			['app' => Application::APP_ID, 'case' => $caseId]
		);

		return array_merge($request, ['state' => 'expired']);
	}//end expireIfRunOut()

	/**
	 * Mark the items that arrived, leaving the rest exactly as they were.
	 *
	 * Matching is on the item TEXT, because that is what the applicant was
	 * shown and what the handler ticks. An arrival naming something that was
	 * never asked for is ignored rather than added: a request is the record of
	 * what WAS asked, and growing it afterwards would rewrite the ask.
	 *
	 * @param array<int, mixed>  $items    The declared items.
	 * @param array<int, string> $received What arrived.
	 * @param DateTimeImmutable  $moment   When.
	 *
	 * @return array<int, array<string, mixed>> The items.
	 *
	 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
	 */
	public function markReceived(array $items, array $received, DateTimeImmutable $moment): array {
		$arrived = [];
		foreach ($received as $one) {
			$text = mb_strtolower(trim((string)$one));
			if ($text !== '') {
				$arrived[] = $text;
			}
		}

		$marked = [];
		foreach ($items as $item) {
			if (is_array($item) === false) {
				continue;
			}

			$text = trim((string)($item['item'] ?? ''));
			if ($text === '') {
				continue;
			}

			$already = (($item['received'] ?? false) === true);
			$nowHere = in_array(mb_strtolower($text), $arrived, true);

			$row = $item;
			$row['item'] = $text;
			$row['received'] = ($already || $nowHere);

			if ($already === false && $nowHere === true) {
				$row['receivedAt'] = $moment->format('c');
			}

			$marked[] = $row;
		}//end foreach

		return $marked;
	}//end markReceived()

	/**
	 * The items still missing, by their text.
	 *
	 * @param array<int, array<string, mixed>> $items The items.
	 *
	 * @return array<int, string> What is still outstanding.
	 *
	 * @spec openspec/changes/aanvullingsverzoek-as-a-record/specs/termijn-pause-extension/spec.md
	 */
	public function outstanding(array $items): array {
		$missing = [];
		foreach ($items as $item) {
			if (is_array($item) === true && ($item['received'] ?? false) !== true) {
				$missing[] = trim((string)($item['item'] ?? ''));
			}
		}

		return array_values(array_filter($missing, static fn (string $t): bool => $t !== ''));
	}//end outstanding()

	/**
	 * The id of a stored request, wherever the store put it.
	 *
	 * @param array<string, mixed> $request The request.
	 *
	 * @return string The id.
	 */
	private function idOf(array $request): string {
		$id = (string)($request['id'] ?? '');
		if ($id !== '') {
			return $id;
		}

		$self = ($request['@self'] ?? []);
		if (is_array($self) === true) {
			return (string)($self['id'] ?? ($self['uuid'] ?? ''));
		}

		return '';
	}//end idOf()
}//end class
