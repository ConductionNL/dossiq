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

use OCA\Dossiq\Kernel\Contracts\CaseTypeResolverInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Kernel\Contracts\TermijnTimerServiceInterface;
use OCA\Dossiq\Listener\Support\InheritedCaseDeadline;
use OCA\Dossiq\Listener\Support\PreSaveCheck;
use OCA\Dossiq\Listener\Support\ReadsCaseWrites;
use OCA\Dossiq\Service\Support\ReferenceId;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Give a case of a child type the deadline its parent's term implies.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/specs/case-types/spec.md
 */
class CaseInheritedDeadlineListener implements IEventListener {
	use ReadsCaseWrites;

	/**
	 * The check named in the log when a payload cannot be read.
	 */
	private const PAYLOAD_CHECK = 'inherited deadline listener';

	/**
	 * The inherited term and the deadline it implies.
	 *
	 * @var InheritedCaseDeadline
	 */
	private readonly InheritedCaseDeadline $deadlines;

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface  $settingsService Schema slug bridge.
	 * @param CaseTypeResolverInterface $resolver        The effective blueprint of a case type.
	 * @param LoggerInterface  $logger          Structured logger.
	 * @param TermijnTimerServiceInterface|null $timerService The engine calendar bridge; a
	 *        statutory term end lands on a day the administered calendar works.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		CaseTypeResolverInterface $resolver,
		private readonly LoggerInterface $logger,
		?TermijnTimerServiceInterface $timerService = null,
	) {
		$this->deadlines = new InheritedCaseDeadline(resolver: $resolver, logger: $logger, timerService: $timerService);
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
		(new PreSaveCheck(logger: $this->logger))->inspect(event: $event, inspect: $this->apply(...));
	}//end handle()

	/**
	 * Write `deadline` and `statutoryTerm` from the inherited term.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-persist event.
	 * @param ObjectEntity                            $entity The case being written.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function apply(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity): void {
		$payload = $this->payload(entity: $entity);
		if ($payload === null || $this->isCaseSchema(object: $payload) === false) {
			return;
		}

		$term = $this->deadlines->inheritedTerm(caseTypeId: $this->referenceId(value: ($payload['caseType'] ?? null)));
		if ($term === '') {
			return;
		}

		$deadline = $this->deadlines->deadlineFrom(startDate: (string)($payload['startDate'] ?? ''), term: $term);
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
	 * The id a reference carries, whether it arrived as a uuid or as a row.
	 *
	 * @param mixed $value A uuid string, or an array carrying `id`/`uuid`.
	 *
	 * @return string The id, or the empty string.
	 */
	private function referenceId(mixed $value): string {
		return ReferenceId::of(value: $value);
	}//end referenceId()
}//end class
