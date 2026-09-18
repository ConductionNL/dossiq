<?php

/**
 * Dossiq milestone dependency cycle listener.
 *
 * REQ-MST-01: three milestone definitions that depend on each other in a circle
 * SHALL be refused, and the refusal SHALL name the items in the cycle.
 *
 * WHY THE GUARD IS ON THE MILESTONE DEFINITION AND NOT ON THE CASE TYPE, which
 * is how the requirement words it. A cycle is created by ONE edit: somebody
 * opens a milestone, points its `dependsOn` at something upstream of it, and
 * saves. That save goes through OpenRegister's generic object API and never
 * touches the case type, so a check on the case type would be reached only on
 * the next unrelated edit of the type, if ever. This refuses the edit that
 * creates the loop, at the moment the person who can fix it is looking at it,
 * which is what D-3 is actually for. It is the same lesson
 * `CaseTypeParentCycleListener` records for `parentCaseType`: the refusal
 * existed on the publish path and the Edit dialog walked straight past it.
 *
 * WHAT A CYCLE COSTS IF IT IS STORED. `MilestoneSchedule` caps its recursion,
 * so nothing hangs; what happens instead is that the dates inside the loop
 * resolve from whichever item the walk reached first, which is an arbitrary
 * timeline presented as a plan. A loop that does not hang is worse than one
 * that does, because nobody goes looking for it.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Milestone\MilestoneRepository;
use OCA\Dossiq\Service\Milestone\MilestoneSchedule;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IL10N;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuse a milestone definition whose `dependsOn` closes a loop.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
 */
class MilestoneDependencyCycleListener implements IEventListener {

	/**
	 * The error code a refused save carries back to the client.
	 *
	 * @var string
	 */
	public const ERROR_CODE = 'milestoneDefinition.dependencyCycle';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema slug bridge.
	 * @param MilestoneRepository $repository Reads the case type's other definitions.
	 * @param MilestoneSchedule $schedule Owns the cycle rule.
	 * @param IL10N $l10n Translation service.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly MilestoneRepository $repository,
		private readonly MilestoneSchedule $schedule,
		private readonly IL10N $l10n,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Inspect a pre-persist milestone definition save.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/task-dependencies-and-the-next-planned-action/specs/milestone-tracking/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->inspect(event: $event, entity: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->inspect(event: $event, entity: $event->getNewObject());
		}
	}//end handle()

	/**
	 * Refuse the save when the incoming declaration closes a loop.
	 *
	 * The set checked is the case type's STORED definitions with the incoming
	 * one SUBSTITUTED for its old self, which is exactly the set the save
	 * would leave behind. Checking the stored set alone would pass every save,
	 * and checking the incoming definition alone would miss every cycle longer
	 * than one link.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The stoppable event.
	 * @param ObjectEntity $entity The entity being written.
	 *
	 * @return void
	 */
	private function inspect(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity): void {
		$payload = $this->payload(entity: $entity);
		if ($payload === null || $this->isMilestoneSchema(object: $payload) === false) {
			return;
		}

		$identifier = trim((string)($payload['identifier'] ?? ''));
		$caseTypeId = trim((string)($payload['caseType'] ?? ''));
		if ($identifier === '' || $caseTypeId === '') {
			return;
		}

		try {
			$stored = $this->repository->findDefinitions(caseTypeId: $caseTypeId);
		} catch (Throwable $e) {
			// The guard cannot read the rest of the case type. Refusing the
			// save on that basis would block authoring whenever the register
			// hiccups, and this is a correctness aid rather than an access
			// decision, so it stands aside.
			$this->logger->debug(
				'Dossiq: milestone cycle check could not read the case type: ' . $e->getMessage()
			);
			return;
		}

		$definitions = [];
		foreach ($stored as $definition) {
			if (trim((string)($definition['identifier'] ?? '')) !== $identifier) {
				$definitions[] = $definition;
			}
		}

		$definitions[] = $payload;

		$cycle = $this->schedule->cycle($definitions);
		if ($cycle === []) {
			return;
		}

		$event->setErrors(
			[
				'message' => $this->l10n->t(
					'A milestone cannot wait for itself: %s',
					[implode(' -> ', $cycle)]
				),
				// An ARRAY and not a bare string, for the reason the case type
				// cycle listener records: the shared form dialog joins every
				// string value of `errors` into the sentence a person reads,
				// so a string code is printed after the message.
				'codes' => [self::ERROR_CODE],
				'cycle' => $cycle,
			]
		);
		$event->stopPropagation();
		$this->logger->info(
			'Dossiq: refused a milestone definition whose dependencies return to itself',
			['caseType' => $caseTypeId, 'milestone' => $identifier, 'cycle' => $cycle]
		);
	}//end inspect()

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
				'Dossiq: milestone cycle check could not read the payload: ' . $e->getMessage()
			);
			return null;
		}
	}//end payload()

	/**
	 * Whether the supplied payload belongs to the `milestoneDefinition` schema.
	 *
	 * @param array<string, mixed> $object Object payload (incl. `@self`).
	 *
	 * @return bool True when this is a milestone definition.
	 */
	private function isMilestoneSchema(array $object): bool {
		$expected = $this->settingsService->getConfigValue('milestone_definition_schema');
		if ($expected === '') {
			return false;
		}

		$candidate = (string)($object['@self']['schema'] ?? ($object['schema'] ?? ''));

		return $candidate !== '' && (
			$candidate === $expected
			|| str_ends_with($candidate, '/' . $expected)
		);
	}//end isMilestoneSchema()
}//end class
