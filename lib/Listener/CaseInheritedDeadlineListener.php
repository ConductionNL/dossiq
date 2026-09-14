<?php

/**
 * Dossiq inherited case deadline listener.
 *
 * REQ-CT-20: a child case type inherits its parent's deadlines. The case
 * schema computes `deadline` declaratively, as `startDate` plus
 * `@ref.caseType.processingDeadline`, and `statutoryTerm` as that same
 * term. A `@ref` reads the ONE row the case points at, so for a child that
 * sets no processing deadline of its own both came out empty: a case of
 * Bezwaar (verkort) filed under a twelve-week parent had no deadline at all,
 * and so no countdown, no overdue flag and no place in the deadline lists.
 *
 * OpenRegister has no declarative merge over a reference, which is the reason
 * `CaseTypeResolver` exists. This listener reuses it: when the case type is
 * silent on its term, it asks the resolver for the effective one and writes
 * the two fields the declarative calculation could not. A type that sets its
 * own term is left to the calculation, so the two never disagree.
 *
 * It runs on OpenRegister's PRE-persist events, after the calculation
 * listener (see `CaseTypeListenerRegistrar` for the priority), so the
 * `startDate` it reads is the one the calculation just filled in. Its values
 * go back through `setModifiedData`, which OpenRegister merges after every
 * listener has run.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Give a case of a child type the deadline its parent's term implies.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/case-types/spec.md
 */
class CaseInheritedDeadlineListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SettingsService  $settingsService Schema slug bridge.
	 * @param CaseTypeResolver $resolver        The effective blueprint of a case type.
	 * @param LoggerInterface  $logger          Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeResolver $resolver,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Fill in an inherited deadline on a case about to be written.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->apply(event: $event, entity: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->apply(event: $event, entity: $event->getNewObject());
		}
	}//end handle()

	/**
	 * Write `deadline` and `statutoryTerm` from the inherited term.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-persist event.
	 * @param ObjectEntity                            $entity The case being written.
	 *
	 * @return void
	 */
	private function apply(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity): void {
		$payload = $this->payload(entity: $entity);
		if ($payload === null || $this->isCaseSchema(object: $payload) === false) {
			return;
		}

		$term = $this->inheritedTerm(caseTypeId: $this->referenceId(value: ($payload['caseType'] ?? null)));
		if ($term === '') {
			return;
		}

		$deadline = $this->deadlineFrom(startDate: (string)($payload['startDate'] ?? ''), term: $term);
		if ($deadline === null) {
			return;
		}

		$event->setModifiedData(
			array_merge(
				$event->getModifiedData(),
				['deadline' => $deadline, 'statutoryTerm' => $term]
			)
		);
	}//end apply()

	/**
	 * The processing deadline a case type inherits and does not set itself.
	 *
	 * Empty when the type sets its own term (the declarative calculation
	 * already wrote the right deadline), when it has no parent, or when no
	 * ancestor sets one either.
	 *
	 * @param string $caseTypeId The case's case type.
	 *
	 * @return string The inherited ISO 8601 duration, or the empty string.
	 */
	private function inheritedTerm(string $caseTypeId): string {
		if ($caseTypeId === '') {
			return '';
		}

		$chain = $this->resolver->chainFor(caseTypeId: $caseTypeId);
		if (count($chain) < 2) {
			return '';
		}

		if (trim((string)($chain[0]['processingDeadline'] ?? '')) !== '') {
			return '';
		}

		$effective = $this->resolver->effectiveCaseType(caseTypeId: $caseTypeId);

		return trim((string)($effective['processingDeadline'] ?? ''));
	}//end inheritedTerm()

	/**
	 * The start date plus the term, as a date.
	 *
	 * An empty start date means today, which is what the declarative
	 * `startDate` calculation fills in on create.
	 *
	 * @param string $startDate The case's start date, or the empty string.
	 * @param string $term      An ISO 8601 duration such as P12W.
	 *
	 * @return string|null The `Y-m-d` deadline, or null when either is unusable.
	 */
	private function deadlineFrom(string $startDate, string $term): ?string {
		try {
			$start = new DateTimeImmutable('today');
			if (trim($startDate) !== '') {
				$start = new DateTimeImmutable(substr(trim($startDate), 0, 10));
			}

			return $start->add(new DateInterval($term))->format('Y-m-d');
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not derive an inherited case deadline',
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
				'Dossiq: inherited deadline listener could not read the payload: ' . $e->getMessage()
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
