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
 * the same mechanism for the BAG claim; this is the same shape with four
 * declarations behind it.
 *
 * WHY THE UPDATE EVENT ENFORCES ONLY ONE OF THE FOUR. What must be answered
 * before the case EXISTS is exactly that, a creation rule. Refusing later
 * updates on the same list would make a case that is already valid unsavable
 * the moment an administrator adds a field to the declaration, and it would
 * refuse the very edit that fills the field in. Who may HOLD a case is a rule
 * about the case at rest, so the narrowing is checked on the update too.
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
 * @spec openspec/specs/semantic-case-intake/spec.md
 * @spec openspec/specs/friendly-case-create-form/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\Intake\ReceiptMoment;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Refuse a case save that breaks what its case type declared.
 *
 * @implements IEventListener<Event>
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) — four declarations decide
 *  one save, and each of them is injected so a test can drive exactly one.
 *
 * @spec openspec/specs/semantic-case-intake/spec.md
 */
class IntakeRequirementsListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService  Schema slug bridge.
	 * @param CaseTypeResolver   $caseTypeResolver The effective case type.
	 * @param IntakeDeclarations $declarations The intake requirements, classification, assignee narrowing and duplicate policy of a case type.
	 * @param IUserSession       $userSession      Who is filing the case.
	 * @param LoggerInterface    $logger           Logger.
	 * @param ReceiptMoment      $receipt          Tells a receipt from a draft save (decision 180).
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTypeResolver $caseTypeResolver,
		private readonly IntakeDeclarations $declarations,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ReceiptMoment $receipt = new ReceiptMoment(),
	) {
	}//end __construct()

	/**
	 * Inspect a pre-persist case save.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/semantic-case-intake/spec.md
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-the-case-create-must-carry-its-term-fields
	 */
	public function handle(Event $event): void {
		// What must be answered before a case exists is checked when the case
		// is RECEIVED: on the create of a case that is not a draft, or on the
		// save that sends a draft. A draft may miss required data (decision 180).
		if ($event instanceof ObjectCreatingEvent === true) {
			$entity = $event->getObject();
			$this->inspect(event: $event, creating: $this->receipt->isDraft(object: $entity) === false);
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$entity = $event->getNewObject();
			$this->inspect(
				event: $event,
				creating: $this->receipt->leavesDraft(before: $event->getOldObject(), after: $entity)
			);
		}
	}//end handle()

	/**
	 * Refuse the save when a declaration refuses it.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event    The pre-persist event.
	 * @param boolean                                 $creating Whether this is a creation.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/friendly-case-create-form/spec.md
	 */
	private function inspect(ObjectCreatingEvent|ObjectUpdatingEvent $event, bool $creating): void {
		$payload = $this->payloadOf(entity: $this->savedCase(event: $event));
		if ($payload === null) {
			return;
		}

		$caseType = $this->caseTypeOfCase(payload: $payload);
		if ($caseType === []) {
			return;
		}

		try {
			if ($creating === true) {
				$this->assertCreatable(payload: $payload, caseType: $caseType);
			}

			$this->assertWritable(payload: $payload, caseType: $caseType);
		} catch (RefusedException $e) {
			$this->refuse(event: $event, refusal: $e);
		}
	}//end inspect()

	/**
	 * The case the event is about to persist.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event The pre-persist event.
	 *
	 * @return ObjectEntity The case being saved.
	 */
	private function savedCase(ObjectCreatingEvent|ObjectUpdatingEvent $event): ObjectEntity {
		if ($event instanceof ObjectCreatingEvent === true) {
			return $event->getObject();
		}

		return $event->getNewObject();
	}//end savedCase()

	/**
	 * Read the case payload; an unreadable payload is logged and leaves the save alone.
	 *
	 * @param ObjectEntity $entity The case being saved.
	 *
	 * @return array<string, mixed>|null The payload, or null when it cannot be read.
	 */
	private function payloadOf(ObjectEntity $entity): ?array {
		try {
			return $entity->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug(
				'Dossiq intake: the case payload could not be read: ' . $e->getMessage()
			);
			return null;
		}
	}//end payloadOf()

	/**
	 * The effective case type of a case payload, or none.
	 *
	 * A payload of another schema, or a case without a case type, has none.
	 *
	 * @param array<string, mixed> $payload The case payload.
	 *
	 * @return array<string, mixed> The effective case type, or [] when there is none.
	 */
	private function caseTypeOfCase(array $payload): array {
		if ($this->isCaseSchema(object: $payload) === false) {
			return [];
		}

		$caseTypeId = $this->caseTypeIdOf(case: $payload);
		if ($caseTypeId === '') {
			return [];
		}

		// 🔴 AN UNRESOLVABLE CASE TYPE LEAVES THE SAVE ALONE, ON PURPOSE.
		// `CaseTypeResolver` answers `[]` both for "there is no such case type"
		// and for "the store could not be read", because `CaseTypeStore` never
		// throws. Refusing on `[]` would stop every case creation on the
		// instance the moment OpenRegister hiccups, which is a worse failure
		// than the one it would prevent. Telling the two apart is an
		// openregister change; `IntakeRequirementsListenerTest` pins the
		// behaviour so it stays a decision rather than an accident.
		return $this->caseTypeResolver->effectiveCaseType(caseTypeId: $caseTypeId);
	}//end caseTypeOfCase()

	/**
	 * Assert what only a received case must satisfy: its requirements, its
	 * classification and the duplicate policy, in that order.
	 *
	 * @param array<string, mixed> $payload  The case payload.
	 * @param array<string, mixed> $caseType Its effective case type.
	 *
	 * @return void
	 *
	 * @throws RefusedException When a declaration refuses the case.
	 */
	private function assertCreatable(array $payload, array $caseType): void {
		$this->declarations->requirements->assertCreatable(case: $payload, caseType: $caseType);
		$this->declarations->classification->assertCreatable(case: $payload, caseType: $caseType);
		$this->declarations->duplicates->assertCreatable(
			case: $payload,
			caseType: $caseType,
			user: $this->userSession->getUser()
		);
	}//end assertCreatable()

	/**
	 * Assert what every save of a case must satisfy: the assignee narrowing.
	 *
	 * @param array<string, mixed> $payload  The case payload.
	 * @param array<string, mixed> $caseType Its effective case type.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the narrowing refuses the case.
	 */
	private function assertWritable(array $payload, array $caseType): void {
		$this->declarations->narrowing->assertWritable(case: $payload, caseType: $caseType);
	}//end assertWritable()

	/**
	 * Stop the save with the refusal's sentence, rule and code.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event   The pre-persist event.
	 * @param RefusedException                        $refusal The refusal.
	 *
	 * @return void
	 */
	private function refuse(ObjectCreatingEvent|ObjectUpdatingEvent $event, RefusedException $refusal): void {
		$event->setErrors(
			[
				'message' => $refusal->getSentence(),
				'error' => $refusal->getRule(),
				'code' => $refusal->getMessage(),
			]
		);
		$event->stopPropagation();
	}//end refuse()

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
