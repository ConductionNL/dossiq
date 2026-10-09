<?php

/**
 * Dossiq case completed satisfaction listener.
 *
 * REQ-PLQ-07 of parties-and-contact-moments-consume-pipelinq: when a case
 * reaches a terminal status, dossiq tells pipelinq the interaction completed,
 * and pipelinq's dispatch rules decide whether a survey goes out. Dossiq holds
 * no survey, invitation, token, cooldown or opt-out, and decides nothing about
 * sending.
 *
 * It listens AFTER the save (ObjectUpdatedEvent), so the hand-off can never
 * block or roll back the case: a case closing is the handler's work and the
 * survey is pipelinq's. Every failure is a log line, never an exception.
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
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Pipelinq\ProgrammeConsumer;
use OCA\Dossiq\Service\Transitions\CaseTypeReader;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Hand a case that just closed to pipelinq's satisfaction loop.
 *
 * 🔑 ONLY THE MOVE INTO A TERMINAL STATUS. A save of a case that was already
 * closed (a note, a correction, an archive stamp) is not a second completion,
 * so the listener compares the status before and after and asks only when it
 * moved onto a final one. Without that, every later edit of a closed case
 * would hand pipelinq another completion, and its cooldown is the only thing
 * that would keep a resident from a second survey.
 *
 * 🔴 THE STATUS PIPELINQ READS IS `closed`, NOT THE UUID. pipelinq matches a
 * dispatch rule on `trigger.entityType` and `trigger.statusEquals`
 * (`SurveyDispatchService::matchingRules()`), and a dossiq case's `status` is
 * a statusType uuid that belongs to one instance, so no rule could ever name
 * it. The case is handed over with `status: closed` and the uuid kept as
 * `statusType`, so a rule written as `{entityType: case, statusEquals: closed}`
 * matches every closing case.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md
 */
class CaseCompletedSatisfactionListener implements IEventListener {

	/**
	 * The status every closing case is handed to pipelinq under.
	 *
	 * @var string
	 */
	public const COMPLETED_STATUS = 'closed';

	/**
	 * Constructor.
	 *
	 * @param ObjectSchemaSlugResolver $slugResolver Tells a case from any other object.
	 * @param CaseTypeReader           $caseTypes    Whether a status closes the case.
	 * @param ProgrammeConsumer        $pipelinq     The hand-off through the pipelinq seam.
	 * @param LoggerInterface          $logger       Structured logger.
	 */
	public function __construct(
		private readonly ObjectSchemaSlugResolver $slugResolver,
		private readonly CaseTypeReader $caseTypes,
		private readonly ProgrammeConsumer $pipelinq,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Hand the case off when this save closed it.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parties-and-contact-moments-consume-pipelinq/specs/pipelinq-consumption/spec.md
	 */
	public function handle(Event $event): void {
		if (($event instanceof ObjectUpdatedEvent) === false) {
			return;
		}

		try {
			$case = $this->payload(entity: $event->getNewObject());
			if ($case === null || $this->slugResolver->resolveFromPayload(payload: $case) !== 'case') {
				return;
			}

			$after = $this->closedOnto(case: $case, old: $event->getOldObject());
			if ($after === '') {
				return;
			}

			$outcome = $this->pipelinq->caseCompleted(
				case: array_merge($case, ['status' => self::COMPLETED_STATUS, 'statusType' => $after]),
				party: $this->party(case: $case)
			);
			if ($outcome['handedOff'] === false) {
				$this->logger->debug(
					'Dossiq: a closed case was not handed to pipelinq',
					['case' => ($case['id'] ?? null), 'reason' => $outcome['reason']]
				);
			}
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the satisfaction hand-off of a closed case failed; the case itself is saved',
				['error' => $e->getMessage()]
			);
		}//end try
	}//end handle()

	/**
	 * The final status this save moved the case onto, or '' when it did not close it.
	 *
	 * @param array<string, mixed> $case The case after the save.
	 * @param ObjectEntity|null    $old  The case before it.
	 *
	 * @return string The statusType id, or the empty string.
	 */
	private function closedOnto(array $case, ?ObjectEntity $old): string {
		$after = $this->reference(value: ($case['status'] ?? ''));
		$before = '';
		if ($old !== null) {
			$before = $this->reference(value: (($this->payload(entity: $old) ?? [])['status'] ?? ''));
		}

		if ($after === '' || $after === $before || $this->caseTypes->isFinalStatus(statusTypeId: $after) === false) {
			return '';
		}

		return $after;
	}//end closedOnto()

	/**
	 * The party pipelinq would ask, when the case names its initiator.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, string> The party, or an empty array when none is known.
	 */
	private function party(array $case): array {
		$id = trim((string)($case['initiatorSourceId'] ?? ''));
		if ($id === '') {
			return [];
		}

		return [
			'id' => $id,
			'displayName' => trim((string)($case['initiatorDisplayName'] ?? '')),
			'kind' => trim((string)($case['initiatorType'] ?? '')),
		];
	}//end party()

	/**
	 * The id a reference carries, whether a uuid or a row.
	 *
	 * @param mixed $value The reference.
	 *
	 * @return string The id, or the empty string.
	 */
	private function reference(mixed $value): string {
		if (is_array($value) === true) {
			$value = ($value['id'] ?? ($value['uuid'] ?? ''));
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end reference()

	/**
	 * An entity's payload, or null when it cannot be read.
	 *
	 * @param ObjectEntity $entity The entity.
	 *
	 * @return array<string, mixed>|null The payload.
	 */
	private function payload(ObjectEntity $entity): ?array {
		try {
			return $entity->jsonSerialize();
		} catch (Throwable $e) {
			return null;
		}
	}//end payload()
}//end class
