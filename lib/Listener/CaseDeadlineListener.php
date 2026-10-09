<?php

/**
 * Dossiq case deadline listener.
 *
 * A case's `deadline` is its start date plus the effective case type's
 * `processingDeadline`, rolled to the first working day on the organisation's
 * calendar, as the Algemene termijnenwet art. 1 requires (REQ-WTR-001). The
 * date before the roll is stored beside it as `deadlineBeforeRoll`, so a
 * reader can see the roll happened. A term definition that sets
 * `rollToWorkingDay: false` keeps the unrolled date.
 *
 * The case schema used to compute `deadline` declaratively, as
 * `dateAdd(startDate, @ref.caseType.processingDeadline)`. OpenRegister's
 * calculation engine has no working-day roll, so a Woo case whose fourth week
 * ended on Christmas Day carried a deadline the law does not use. That
 * calculation is gone; this listener is the one writer. It still reads the
 * effective case type through `CaseTypeResolver`, so a child type that sets
 * no term of its own inherits its parent's (REQ-CT-20).
 *
 * On an update the stored deadline is kept unless the start date or the case
 * type changed, because an extended or paused term moved it on purpose. When
 * the term write path recorded a new date in {@see CaseDeadlineMirror}, that
 * date wins: the case follows its statutory term instance.
 *
 * It runs on OpenRegister's PRE-persist events, after the calculation
 * listener (see `CaseTypeListenerRegistrar` for the priority), so the
 * `startDate` it reads is the one the calculation just filled in. Its values
 * go back through `setModifiedData`, which OpenRegister merges after every
 * listener has run and after the readOnly check.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/specs/case-types/spec.md
 * @spec openspec/specs/woo-case-type/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\Dossiq\Service\Termijn\TermDefinitions;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Give every case the rolled deadline its (effective) case type's term implies.
 *
 * @implements IEventListener<Event>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) It listens to two
 * OpenRegister events and reads the case type, its parent chain and its term
 * definition before rolling the date on the calendar; the types over the limit
 * are those events and the date value types, not further collaborators.
 *
 * @spec openspec/specs/woo-case-type/spec.md
 */
class CaseDeadlineListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SettingsService           $settingsService Schema slug bridge.
	 * @param CaseTypeResolver          $resolver        The effective blueprint of a case type.
	 * @param LoggerInterface           $logger          Structured logger.
	 * @param TermijnTimerService|null  $timerService    The engine calendar bridge; a
	 *        statutory term end lands on a day the administered calendar works.
	 * @param CaseDeadlineMirror|null   $mirror          The date the term write path
	 *        recorded for this case's next save.
	 * @param TermDefinitions|null      $definitions     The case type's term definition,
	 *        read for its `rollToWorkingDay` switch.
	 * @param CaseTypeSlugResolver|null $slugs           A case carries its case type as a
	 *        uuid; term definitions are keyed by slug.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeResolver $resolver,
		private readonly LoggerInterface $logger,
		private readonly ?TermijnTimerService $timerService = null,
		private readonly ?CaseDeadlineMirror $mirror = null,
		private readonly ?TermDefinitions $definitions = null,
		private readonly ?CaseTypeSlugResolver $slugs = null,
	) {
	}//end __construct()

	/**
	 * Fill in the deadline on a case about to be written.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-case-type/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->apply(event: $event, entity: $event->getObject(), old: null);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->apply(event: $event, entity: $event->getNewObject(), old: $event->getOldObject());
		}
	}//end handle()

	/**
	 * Write `deadline`, `deadlineBeforeRoll` and `statutoryTerm`.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-persist event.
	 * @param ObjectEntity                            $entity The case being written.
	 * @param ObjectEntity|null                       $old    The stored case, on an update.
	 *
	 * @return void
	 */
	private function apply(
		ObjectCreatingEvent|ObjectUpdatingEvent $event,
		ObjectEntity $entity,
		?ObjectEntity $old,
	): void {
		$payload = $this->payload(entity: $entity);
		if ($payload === null || $this->isCaseSchema(object: $payload) === false) {
			return;
		}

		$fields = $this->fieldsFor(payload: $payload, old: $old, caseId: (string)($entity->getUuid() ?? ''));
		if ($fields === []) {
			return;
		}

		$event->setModifiedData(array_merge($event->getModifiedData(), $fields));
	}//end apply()

	/**
	 * The deadline fields this save must carry, or none.
	 *
	 * @param array<string, mixed>       $payload The case as it is being written.
	 * @param ObjectEntity|null          $old     The stored case, on an update.
	 * @param string                     $caseId  The case id.
	 *
	 * @return array<string, string> The fields to merge, or an empty array.
	 */
	private function fieldsFor(array $payload, ?ObjectEntity $old, string $caseId): array {
		// The case follows its statutory term instance: the term write path
		// recorded the date, and it outranks anything computed here.
		$mirrored = $this->mirror?->take(caseId: $caseId);
		if ($mirrored !== null) {
			return array_filter($mirrored, static fn (string $value): bool => $value !== '');
		}

		$stored = $this->storedDeadline(payload: $payload, old: $old);
		if ($stored !== null) {
			return $stored;
		}

		$caseTypeId = $this->referenceId(value: ($payload['caseType'] ?? null));
		$term = $this->effectiveTerm(caseTypeId: $caseTypeId);
		if ($term === '') {
			return [];
		}

		$dates = $this->deadlineFrom(
			startDate: (string)($payload['startDate'] ?? ''),
			term: $term,
			definitie: $this->definitionFor(caseTypeId: $caseTypeId)
		);
		if ($dates === null) {
			return [];
		}

		return array_merge($dates, ['statutoryTerm' => $term]);
	}//end fieldsFor()

	/**
	 * The stored deadline an update keeps, or null when it must be recomputed.
	 *
	 * An extended or paused term moved the deadline on purpose, and recomputing
	 * it from the start date on every save would undo that. It is recomputed
	 * only when the start date or the case type changed, or when nothing is
	 * stored yet. The stored values are written back explicitly, so a save
	 * whose payload left them out does not blank them.
	 *
	 * @param array<string, mixed> $payload The case as it is being written.
	 * @param ObjectEntity|null    $old     The stored case, on an update.
	 *
	 * @return array<string, string>|null The fields to keep, or null.
	 */
	private function storedDeadline(array $payload, ?ObjectEntity $old): ?array {
		if ($old === null) {
			return null;
		}

		$before = $this->payload(entity: $old);
		if ($before === null || trim((string)($before['deadline'] ?? '')) === '') {
			return null;
		}

		$sameStart = substr((string)($before['startDate'] ?? ''), 0, 10) === substr((string)($payload['startDate'] ?? ''), 0, 10);
		$sameType = $this->referenceId(value: ($before['caseType'] ?? null)) === $this->referenceId(value: ($payload['caseType'] ?? null));
		if ($sameStart === false || $sameType === false) {
			return null;
		}

		return array_filter(
			[
				'deadline' => (string)$before['deadline'],
				'deadlineBeforeRoll' => (string)($before['deadlineBeforeRoll'] ?? ''),
			],
			static fn (string $value): bool => $value !== ''
		);
	}//end storedDeadline()

	/**
	 * The processing deadline a case type sets, or inherits from an ancestor.
	 *
	 * @param string $caseTypeId The case's case type.
	 *
	 * @return string The ISO 8601 duration, or the empty string.
	 */
	private function effectiveTerm(string $caseTypeId): string {
		if ($caseTypeId === '') {
			return '';
		}

		$effective = $this->resolver->effectiveCaseType(caseTypeId: $caseTypeId);

		return trim((string)($effective['processingDeadline'] ?? ''));
	}//end effectiveTerm()

	/**
	 * The case type's active term definition, for its roll switch.
	 *
	 * An unreadable definition answers an empty array, and an empty definition
	 * gets the roll: the Awt applies by law, not by configuration.
	 *
	 * @param string $caseTypeId The case's case type (uuid or slug).
	 *
	 * @return array<string, mixed> The definition, or an empty array.
	 */
	private function definitionFor(string $caseTypeId): array {
		if ($this->definitions === null || $caseTypeId === '') {
			return [];
		}

		try {
			$slug = ($this->slugs?->toSlug(reference: $caseTypeId) ?? $caseTypeId);
			if ($slug === '') {
				return [];
			}

			return ($this->definitions->activeFor(caseType: $slug) ?? []);
		} catch (Throwable $e) {
			$this->logger->debug('Dossiq: case deadline listener could not read the term definition: ' . $e->getMessage());
			return [];
		}
	}//end definitionFor()

	/**
	 * The start date plus the term, rolled, and the date before the roll.
	 *
	 * An empty start date means today, which is what the declarative
	 * `startDate` calculation fills in on create.
	 *
	 * @param string               $startDate The case's start date, or the empty string.
	 * @param string               $term      An ISO 8601 duration such as P28D.
	 * @param array<string, mixed> $definitie The term definition, for `rollToWorkingDay`.
	 *
	 * @return array{deadline: string, deadlineBeforeRoll: string}|null The dates, or null when unusable.
	 */
	private function deadlineFrom(string $startDate, string $term, array $definitie): ?array {
		try {
			$start = new DateTimeImmutable('today');
			if (trim($startDate) !== '') {
				$start = new DateTimeImmutable(substr(trim($startDate), 0, 10));
			}

			$end = $start->add(new DateInterval($term));
			$rolled = ($this->timerService?->rollTermEndFor(date: $end, definitie: $definitie) ?? $end);

			return ['deadline' => $rolled->format('Y-m-d'), 'deadlineBeforeRoll' => $end->format('Y-m-d')];
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not derive a case deadline',
				['startDate' => $startDate, 'term' => $term, 'error' => $e->getMessage()]
			);
			return null;
		}
	}//end deadlineFrom()

	/**
	 * Read an entity's payload, or null when it cannot be read.
	 *
	 * @param ObjectEntity $entity The entity carried by the event.
	 *
	 * @return array<string, mixed>|null The payload, or null.
	 */
	private function payload(ObjectEntity $entity): ?array {
		try {
			return $entity->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq: case deadline listener could not read the payload: ' . $e->getMessage()
			);
			return null;
		}
	}//end payload()

	/**
	 * The id a reference carries, whether it arrived as a uuid or as a row.
	 *
	 * @param mixed $value A uuid string, or an array carrying `id`/`uuid`.
	 *
	 * @return string The id, or the empty string.
	 */
	private function referenceId(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? ''));
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end referenceId()

	/**
	 * Whether the supplied payload belongs to the `case` schema.
	 *
	 * @param array<string, mixed> $object Object payload (incl. `@self`).
	 *
	 * @return bool True when this is a case.
	 */
	private function isCaseSchema(array $object): bool {
		$expected = $this->settingsService->getConfigValue('case_schema');
		if ($expected === '') {
			return false;
		}

		$candidate = (string)($object['@self']['schema'] ?? ($object['schema'] ?? ''));

		return $candidate !== '' && (
			$candidate === $expected
			|| str_ends_with($candidate, '/' . $expected)
		);
	}//end isCaseSchema()
}//end class
