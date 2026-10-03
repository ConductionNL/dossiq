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
 * WHY THE SCHEMA CHECK GOES THROUGH {@see SchemaScopeResolver}. This guard
 * used to decide whether a write was its business by reading the
 * `milestone_definition_schema` appconfig key and returning false when the key
 * was empty. That key is written by the configuration load, so on an instance
 * whose setup never finished the guard stood aside on every write, silently.
 * Measured on 2026-09-19: a milestone that waits for itself was stored with a
 * 201 while the guard was wired and its cycle rule was correct. A missing
 * config key is now a missing answer rather than a negative one.
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
use OCA\Dossiq\Service\Settings\SchemaScopeResolver;
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
	 * The appconfig key holding the milestone definition schema id.
	 *
	 * @var string
	 */
	public const SCHEMA_CONFIG_KEY = 'milestone_definition_schema';

	/**
	 * The schema slug this guard watches.
	 *
	 * @var string
	 */
	public const SCHEMA_SLUG = 'milestoneDefinition';

	/**
	 * Constructor.
	 *
	 * @param SchemaScopeResolver $schemaScope Decides whether a write is ours.
	 * @param MilestoneRepository $repository Reads the case type's other definitions.
	 * @param MilestoneSchedule $schedule Owns the cycle rule.
	 * @param IL10N $l10n Translation service.
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly SchemaScopeResolver $schemaScope,
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

		$this->refuseCycle(event: $event, payload: $payload);
	}//end inspect()

	/**
	 * Refuse the save when the declaration closes a loop.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The stoppable event.
	 * @param array<string, mixed> $payload The milestone definition being written.
	 *
	 * @return void
	 */
	private function refuseCycle(ObjectCreatingEvent|ObjectUpdatingEvent $event, array $payload): void {

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
	}//end refuseCycle()

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
		$scope = $this->schemaScope->classify(
			payload: $object,
			configKey: self::SCHEMA_CONFIG_KEY,
			slug: self::SCHEMA_SLUG
		);

		if ($scope === SchemaScopeResolver::IN_SCOPE) {
			return true;
		}

		if ($scope === SchemaScopeResolver::OUT_OF_SCOPE) {
			return false;
		}

		// UNDECIDED: the configuration load never wrote the key AND the slug
		// does not resolve, so nothing outside the payload can name the schema.
		// The guard used to read that as "not mine" and stand aside, which is
		// how a milestone that waits for itself came to be stored with a 201.
		// It reads the payload instead. The three fields below are the
		// milestone definition's own shape: no other Dossiq schema carries
		// `dependsOn` beside an `identifier` and a `caseType`. A false positive
		// costs a write that declares a dependency loop, which is refused
		// whoever wrote it; a false negative costs the guard.
		if ($this->looksLikeMilestoneDefinition(object: $object) === false) {
			return false;
		}

		$this->logger->warning(
			'Dossiq: checking a milestone dependency cycle on the payload alone, '
			. 'because the milestone schema is not configured on this instance',
			['configKey' => self::SCHEMA_CONFIG_KEY, 'slug' => self::SCHEMA_SLUG]
		);

		return true;
	}//end isMilestoneSchema()

	/**
	 * Whether a payload has the shape of a milestone definition.
	 *
	 * Read ONLY when nothing else can name the schema. It is deliberately
	 * narrow: all three fields together, and `dependsOn` a list.
	 *
	 * @param array<string, mixed> $object The object payload.
	 *
	 * @return bool True when the payload declares milestone dependencies.
	 */
	private function looksLikeMilestoneDefinition(array $object): bool {
		if (is_array(($object['dependsOn'] ?? null)) === false) {
			return false;
		}

		return trim((string)($object['identifier'] ?? '')) !== ''
			&& trim((string)($object['caseType'] ?? '')) !== '';
	}//end looksLikeMilestoneDefinition()
}//end class
