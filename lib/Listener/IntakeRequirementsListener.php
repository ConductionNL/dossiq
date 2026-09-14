<?php

/**
 * Dossiq intake requirements enforcement.
 *
 * The one place the intake declarations are enforced on the WRITE. Cases are
 * created through OpenRegister's object API, from the create form, from the
 * mail intake, from an import and from any integration a gemeente builds. A
 * rule that lives in the form is a rule four of those five walk past.
 *
 * `ObjectCreatingEvent` is pre-persist and stoppable, so this refuses the save
 * itself rather than cleaning up after it. `LocationBagValidationListener` uses
 * the same mechanism for the BAG claim; this is the same shape with three
 * declarations behind it.
 *
 * WHY THE UPDATE EVENT IS NOT LISTENED TO. What must be answered before the
 * case EXISTS is exactly that, a creation rule. Refusing later updates on the
 * same list would make a case that is already valid unsavable the moment an
 * administrator adds a field to the declaration, and it would refuse the very
 * edit that fills the field in. The narrowing is the exception: who may hold a
 * case is a rule about the case at rest, so it is checked on the update too.
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
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Intake\AssigneeNarrowing;
use OCA\Dossiq\Service\Intake\CaseClassification;
use OCA\Dossiq\Service\Intake\IntakeRequirements;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuse a case save that breaks what its case type declared.
 *
 * @implements IEventListener<Event>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — three declarations decide
 *  one save, and each of them is injected so a test can drive exactly one.
 *
 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
 */
class IntakeRequirementsListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService  Schema slug bridge.
	 * @param CaseTypeResolver   $caseTypeResolver The effective case type.
	 * @param IntakeRequirements $requirements     What must be answered, and when.
	 * @param CaseClassification $classification   The facets and the access rule.
	 * @param AssigneeNarrowing  $narrowing        Who may hold the case.
	 * @param LoggerInterface    $logger           Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeResolver $caseTypeResolver,
		private readonly IntakeRequirements $requirements,
		private readonly CaseClassification $classification,
		private readonly AssigneeNarrowing $narrowing,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Inspect a pre-persist case save.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/intake-triage-and-refusal/specs/semantic-case-intake/spec.md
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->inspect(event: $event, entity: $event->getObject(), creating: true);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->inspect(event: $event, entity: $event->getNewObject(), creating: false);
		}
	}//end handle()

	/**
	 * Refuse the save when a declaration refuses it.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event    The pre-persist event.
	 * @param ObjectEntity                            $entity   The case being saved.
	 * @param boolean                                 $creating Whether this is a creation.
	 *
	 * @return void
	 */
	private function inspect(
		ObjectCreatingEvent|ObjectUpdatingEvent $event,
		ObjectEntity $entity,
		bool $creating,
	): void {
		try {
			$payload = $entity->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq intake: the case payload could not be read: ' . $e->getMessage()
			);
			return;
		}

		if (is_array($payload) === false || $this->isCaseSchema(object: $payload) === false) {
			return;
		}

		$caseTypeId = $this->caseTypeIdOf(case: $payload);
		if ($caseTypeId === '') {
			return;
		}

		$caseType = $this->caseTypeResolver->effectiveCaseType(caseTypeId: $caseTypeId);
		if ($caseType === []) {
			return;
		}

		try {
			if ($creating === true) {
				$this->requirements->assertCreatable(case: $payload, caseType: $caseType);
				$this->classification->assertCreatable(case: $payload, caseType: $caseType);
			}

			$this->narrowing->assertWritable(case: $payload, caseType: $caseType);
		} catch (RefusedException $e) {
			$event->setErrors(
				[
					'message' => $e->getSentence(),
					'error' => $e->getRule(),
					'code' => $e->getMessage(),
				]
			);
			$event->stopPropagation();
		}//end try
	}//end inspect()

	/**
	 * Whether this payload is a `case`.
	 *
	 * @param array<string, mixed> $object The object payload, `@self` included.
	 *
	 * @return boolean True when the payload belongs to the case schema.
	 */
	private function isCaseSchema(array $object): bool {
		$expected = $this->settingsService->getConfigValue('case_schema');
		if ($expected === '') {
			return false;
		}

		$candidate = (string)($object['@self']['schema'] ?? ($object['schema'] ?? ''));

		return ($candidate !== '' && (
			$candidate === $expected
			|| str_ends_with($candidate, '/' . $expected)
		));
	}//end isCaseSchema()

	/**
	 * The case type this case names.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return string The case type UUID, or ''.
	 */
	private function caseTypeIdOf(array $case): string {
		$value = ($case['caseType'] ?? '');
		if (is_array($value) === true) {
			return trim((string)($value['id'] ?? ($value['@self']['id'] ?? '')));
		}

		return trim((string)$value);
	}//end caseTypeIdOf()
}//end class
