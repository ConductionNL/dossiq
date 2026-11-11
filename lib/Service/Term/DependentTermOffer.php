<?php

/**
 * A term that moved, offered to the cases waiting on it.
 *
 * A vergunning that cannot be decided until a bezwaar is has a link and, until
 * now, no consequence: when the bezwaar's term was extended, the vergunning's
 * handler found out when their own term breached.
 *
 * 🔴 NOTHING EXTENDS BY ITSELF, AND THAT IS THE DESIGN RATHER THAN A GAP. Awb
 * 4:14 requires the applicant to be told before a term moves, so the move is
 * offered to a person: one engine task per waiting case, naming the case that
 * moved and by how many days, with an accept that goes through the ordinary
 * extension and its ordinary refusals (the ceiling, the supervisor rule).
 *
 * The days are written onto the task by this service and read back off it on
 * accept. They are never taken from the request: a caller that could name its
 * own number could extend a statutory term by naming a large one.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Term
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
 * @spec openspec/specs/related-case-linking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Term;

use DateTimeImmutable;
use OCA\Dossiq\Service\CaseRelationService;
use OCA\Dossiq\Service\DeadlineExtensionService;
use OCA\Dossiq\Service\Relation\CaseRelationCodec;
use OCA\Dossiq\Service\Relation\CaseRelationStore;
use OCA\Dossiq\Service\Task\EngineTaskGateway;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Makes and settles the offer a moved term creates for its dependents.
 *
 * @spec openspec/specs/related-case-linking/spec.md#requirement-a-moved-term-is-offered-to-its-dependents-req-rcl-11
 */
class DependentTermOffer {
	/**
	 * The kind of task this service writes, and the only kind it settles.
	 */
	public const TASK_KIND = 'term-follow';

	/**
	 * The term events that move a date and therefore make an offer. A start,
	 * a completion and a notification move nothing, so they make none.
	 */
	public const MOVING_EVENTS = ['verleng', 'pauze'];

	/**
	 * How many waiting cases one moved term will write tasks for (ADR-058).
	 *
	 * A bounded query with a warning beyond it, rather than an unbounded one:
	 * a case that hundreds wait on is a data defect, and writing hundreds of
	 * tasks about it turns that defect into everybody's inbox.
	 */
	public const MAX_DEPENDENTS = 50;

	/**
	 * Term statuses a dependent must be in for an offer to mean anything.
	 */
	private const OPEN_TERM_STATUSES = ['lopend', 'verlengd', 'paused'];

	/**
	 * Constructor.
	 *
	 * @param CaseRelationStore        $store     The reverse relation index.
	 * @param TermijnService           $terms     Term instances and their events.
	 * @param DeadlineExtensionService $extension The one way a term moves.
	 * @param EngineTaskGateway        $tasks     The engine task the offer is.
	 * @param LoggerInterface          $logger    Logger.
	 * @param TermijnTimerService|null $timerService The working calendar the
	 *                                               proposed end date is rolled on.
	 */
	public function __construct(
		private readonly CaseRelationStore $store,
		private readonly TermijnService $terms,
		private readonly DeadlineExtensionService $extension,
		private readonly EngineTaskGateway $tasks,
		private readonly LoggerInterface $logger,
		private readonly ?TermijnTimerService $timerService = null,
	) {
	}//end __construct()

	/**
	 * The cases that wait on this one.
	 *
	 * Read from OpenRegister's reverse index rather than by filtering on the
	 * list field: the reverse index is the side that knows, and a filter over
	 * an array of references is a query whose empty answer looks exactly like
	 * "nobody waits on this case".
	 *
	 * @param string $sourceCaseId The case that moved.
	 *
	 * @return array<int, array{caseId: string, title: string}> The waiting cases.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md#requirement-a-moved-term-is-offered-to-its-dependents-req-rcl-11
	 */
	public function dependentsOf(string $sourceCaseId): array {
		if (trim($sourceCaseId) === '') {
			return [];
		}

		$dependents = [];
		foreach ($this->store->relationRows(caseUuid: $sourceCaseId, incoming: true) as $row) {
			$caseId = $this->waitingCaseId(row: $row, sourceCaseId: $sourceCaseId);
			if ($caseId !== '') {
				$dependents[$caseId] = ['caseId' => $caseId, 'title' => (string)($row['title'] ?? '')];
			}
		}

		return $this->bounded(sourceCaseId: $sourceCaseId, dependents: array_values($dependents));
	}//end dependentsOf()

	/**
	 * The case a reverse-index row says waits on the source, or '' when it is no such wait.
	 *
	 * @param array<string, mixed> $row          One reverse-index row.
	 * @param string               $sourceCaseId The case that moved.
	 *
	 * @return string The waiting case id, or ''.
	 */
	private function waitingCaseId(array $row, string $sourceCaseId): string {
		if ($this->isWait(row: $row) === false) {
			return '';
		}

		$caseId = (string)($row['id'] ?? ($row['uuid'] ?? ''));
		if ($caseId === $sourceCaseId) {
			return '';
		}

		return $caseId;
	}//end waitingCaseId()

	/**
	 * Whether a reverse-index row is a waits-on link.
	 *
	 * @param array<string, mixed> $row One reverse-index row.
	 *
	 * @return bool True for a waits-on link.
	 */
	private function isWait(array $row): bool {
		// No `?? ''`: the key is a constant of the same codec, so the offset
		// always exists and the coalesce reads as a guard against something
		// that cannot happen.
		$property = CaseRelationCodec::TYPED_PROPERTIES[CaseRelationService::RELATION_WAITS_ON];
		$relation = ($row['relation'] ?? null);

		return is_array($relation) === true && (string)($relation['property'] ?? '') === $property;
	}//end isWait()

	/**
	 * The dependents up to MAX_DEPENDENTS, with a warning when there are more.
	 *
	 * @param string                                           $sourceCaseId The case that moved.
	 * @param array<int, array{caseId: string, title: string}> $dependents   Every waiting case.
	 *
	 * @return array<int, array{caseId: string, title: string}> The waiting cases offered the move.
	 */
	private function bounded(string $sourceCaseId, array $dependents): array {
		if (count($dependents) <= self::MAX_DEPENDENTS) {
			return $dependents;
		}

		$this->logger->warning(
			'Dossiq: case "' . $sourceCaseId . '" is waited on by ' . count($dependents)
			. ' cases; only the first ' . self::MAX_DEPENDENTS . ' are offered the move'
		);

		return array_slice($dependents, 0, self::MAX_DEPENDENTS);
	}//end bounded()

	/**
	 * Offer a moved term to every case waiting on it.
	 *
	 * @param string $sourceCaseId The case whose term moved.
	 * @param string $sourceTitle  What that case is called, for the task text.
	 * @param int    $daysImpact   How many days it moved by.
	 *
	 * @return int How many offers were written.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md#requirement-a-moved-term-is-offered-to-its-dependents-req-rcl-11
	 */
	public function offer(string $sourceCaseId, string $sourceTitle, int $daysImpact): int {
		if ($daysImpact <= 0) {
			return 0;
		}

		$move    = new TermMove(sourceCaseId: $sourceCaseId, sourceTitle: $sourceTitle, daysImpact: $daysImpact);
		$written = 0;
		foreach ($this->dependentsOf(sourceCaseId: $sourceCaseId) as $dependent) {
			if ($this->offerTo(move: $move, dependent: $dependent) === true) {
				$written++;
			}
		}

		return $written;
	}//end offer()

	/**
	 * Offer the move to one waiting case, when that case has a running term.
	 *
	 * @param TermMove                             $move      The moved term.
	 * @param array{caseId: string, title: string} $dependent The waiting case.
	 *
	 * @return bool True when an offer was written.
	 */
	private function offerTo(TermMove $move, array $dependent): bool {
		$instance = $this->runningTerm(caseId: $dependent['caseId']);
		if ($instance === null) {
			// A case with no running term has nothing to extend, so there
			// is nothing to decide and no task worth anyone's attention.
			return false;
		}

		$dependent['instance'] = (string)($instance['id'] ?? '');
		return $this->writeOffer(move: $move, dependent: $dependent) !== '';
	}//end offerTo()

	/**
	 * The term instance of a case when it is open, null otherwise.
	 *
	 * @param string $caseId The waiting case.
	 *
	 * @return array<string, mixed>|null The open term instance.
	 */
	private function runningTerm(string $caseId): ?array {
		$instance = $this->terms->getTermijnInstanceForZaak(caseId: $caseId);
		if ($instance === null
			|| in_array((string)($instance['status'] ?? ''), self::OPEN_TERM_STATUSES, true) === false
		) {
			return null;
		}

		return $instance;
	}//end runningTerm()

	/**
	 * Accept one offer: extend the waiting case's term by the days the task
	 * carries, for the reason the task carries, and complete the task.
	 *
	 * @param string      $taskId The engine task the handler accepted.
	 * @param string|null $actor  The acting user.
	 *
	 * @return array{refused?: string, event?: array<string, mixed>} The outcome.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md#requirement-a-moved-term-is-offered-to-its-dependents-req-rcl-11
	 */
	public function accept(string $taskId, ?string $actor): array {
		$offer = $this->offerOn(taskId: $taskId);
		if ($offer === null) {
			return ['refused' => 'not-a-term-follow-task'];
		}

		$outcome = $this->followedExtension(offer: $offer);
		if (array_key_exists('event', $outcome) === true) {
			$this->tasks->complete($taskId, [], 'accepted', $actor);
		}

		return $outcome;
	}//end accept()

	/**
	 * Extend the waiting case's term as the offer says, or say why not.
	 *
	 * @param array{case: string, source: string, days: int, instance: string} $offer The offer.
	 *
	 * @return array{refused?: string, event?: array<string, mixed>} The outcome.
	 */
	private function followedExtension(array $offer): array {
		$instance = $this->terms->getTermijnInstance(termInstanceId: $offer['instance']);
		if ($instance === null) {
			return ['refused' => 'term-gone'];
		}

		$current = (string)($instance['endDateCurrent'] ?? '');
		if ($current === '') {
			return ['refused' => 'term-has-no-end-date'];
		}

		return $this->extendFrom(offer: $offer, current: $current);
	}//end followedExtension()

	/**
	 * Extend from the current end date, or refuse when it cannot be read as a date.
	 *
	 * @param array{case: string, source: string, days: int, instance: string} $offer   The offer.
	 * @param string                                                            $current The term's current end date.
	 *
	 * @return array{refused?: string, event?: array<string, mixed>} The outcome.
	 */
	private function extendFrom(array $offer, string $current): array {
		try {
			$newEnd = $this->movedEnd(current: $current, days: $offer['days']);
		} catch (Throwable $e) {
			return ['refused' => 'term-has-no-end-date'];
		}

		return $this->requestFollowed(offer: $offer, newEnd: $newEnd);
	}//end extendFrom()

	/**
	 * The current end date moved by the offered days.
	 *
	 * @param string $current The term's current end date.
	 * @param int    $days    The offered days.
	 *
	 * @return string The proposed end date as Y-m-d.
	 *
	 * @throws Throwable When the current end date is no date.
	 */
	private function movedEnd(string $current, int $days): string {
		// A statutory end date: rolled onto the working calendar, so an
		// offer never proposes a Saturday.
		$end = (new DateTimeImmutable($current))->modify('+' . $days . ' days');
		return ($this->timerService?->rollTermEndFor(date: $end) ?? $end)->format('Y-m-d');
	}//end movedEnd()

	/**
	 * Ask for the followed extension; a refusal leaves the task open.
	 *
	 * @param array{case: string, source: string, days: int, instance: string} $offer  The offer.
	 * @param string                                                            $newEnd The proposed end date.
	 *
	 * @return array{refused?: string, event?: array<string, mixed>} The outcome.
	 */
	private function requestFollowed(array $offer, string $newEnd): array {
		try {
			$event = $this->extension->requestExtension(termInstanceId: $offer['instance'], rationale: 'follows ' . $offer['source'], newEndDate: $newEnd);
		} catch (Throwable $e) {
			// The ceiling, the supervisor rule and the case type's maximum
			// all refuse here, and they refuse an offered extension exactly
			// as they refuse a typed one. The task stays open, because the
			// handler still has a decision to make.
			$this->logger->info(
				'Dossiq: a followed extension was refused on "' . $offer['case'] . '": ' . $e->getMessage()
			);

			return ['refused' => 'extension-refused'];
		}

		return ['event' => $event];
	}//end requestFollowed()

	/**
	 * Decline one offer: nothing moves, and the task is done.
	 *
	 * @param string      $taskId The engine task.
	 * @param string|null $actor  The acting user.
	 *
	 * @return bool True when the task was completed.
	 *
	 * @spec openspec/specs/related-case-linking/spec.md#requirement-a-moved-term-is-offered-to-its-dependents-req-rcl-11
	 */
	public function decline(string $taskId, ?string $actor): bool {
		if ($this->offerOn(taskId: $taskId) === null) {
			return false;
		}

		return $this->tasks->complete($taskId, [], 'declined', $actor);
	}//end decline()

	/**
	 * What one task declares about the offer it is, or null when it is not
	 * one of ours.
	 *
	 * @param string $taskId The engine task.
	 *
	 * @return array{case: string, source: string, days: int, instance: string}|null The offer.
	 */
	private function offerOn(string $taskId): ?array {
		$task     = $this->tasks->find(taskId: $taskId);
		$declared = $this->declaration(task: $task);
		$days     = (int)($declared['daysImpact'] ?? 0);
		$instance = (string)($declared['deadlineInstance'] ?? '');
		if ($days <= 0 || $instance === '') {
			return null;
		}

		return [
			'case' => (string)($task['objectUuid'] ?? ''),
			'source' => (string)($declared['sourceCase'] ?? ''),
			'days' => $days,
			'instance' => $instance,
		];
	}//end offerOn()

	/**
	 * What a task declares under metadata.dossiq when it is one of ours, empty otherwise.
	 *
	 * @param array<string, mixed>|null $task The engine task, null when not found.
	 *
	 * @return array<string, mixed> The declaration.
	 */
	private function declaration(?array $task): array {
		$declared = ($task['metadata']['dossiq'] ?? []);
		if (is_array($declared) === false
			|| (string)($declared['kind'] ?? '') !== self::TASK_KIND
		) {
			return [];
		}

		return $declared;
	}//end declaration()

	/**
	 * Write one offer as an engine task for the waiting case's handler.
	 *
	 * @param TermMove                                               $move      The moved term.
	 * @param array{caseId: string, title: string, instance: string} $dependent The waiting case and its term instance.
	 *
	 * @return string The engine task uuid, or an empty string.
	 */
	private function writeOffer(TermMove $move, array $dependent): string {
		$task = $this->followTask(move: $move, dependent: $dependent);
		try {
			return $this->tasks->mirrorImport(task: $task, caseId: $dependent['caseId'], actor: null);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the moved term of "' . $move->sourceCaseId . '" could not be offered to "'
				. $dependent['caseId'] . '": ' . $e->getMessage()
			);

			return '';
		}
	}//end writeOffer()

	/**
	 * The engine task one offer is.
	 *
	 * @param TermMove                                               $move      The moved term.
	 * @param array{caseId: string, title: string, instance: string} $dependent The waiting case and its term instance.
	 *
	 * @return array<string, mixed> The task.
	 */
	private function followTask(TermMove $move, array $dependent): array {
		$named = $move->named();
		return [
			'id' => self::TASK_KIND . ':' . $dependent['caseId'] . ':' . $move->sourceCaseId . ':' . $dependent['instance'],
			'title' => 'De termijn van ' . $named . ' is met ' . $move->daysImpact . ' dagen verschoven',
			'description' => 'Deze zaak wacht op ' . $named . '. Die termijn is met ' . $move->daysImpact
				. ' dagen verschoven. Wilt u de termijn van deze zaak net zo verschuiven? '
				. 'De aanvrager wordt daarover geinformeerd, zoals Awb 4:14 voorschrijft.',
			'status' => 'available',
			'metadata' => ['dossiq' => $this->followDeclaration(move: $move, instanceId: $dependent['instance'])],
		];
	}//end followTask()

	/**
	 * What an offer task declares, so it can be settled later.
	 *
	 * @param TermMove $move       The moved term.
	 * @param string   $instanceId The waiting case's term instance.
	 *
	 * @return array<string, mixed> The declaration.
	 */
	private function followDeclaration(TermMove $move, string $instanceId): array {
		return [
			'kind' => self::TASK_KIND,
			'sourceCase' => $move->sourceCaseId,
			'daysImpact' => $move->daysImpact,
			'deadlineInstance' => $instanceId,
		];
	}//end followDeclaration()
}//end class
