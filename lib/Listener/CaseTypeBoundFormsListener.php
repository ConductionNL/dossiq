<?php

/**
 * Re-checks the published forms that open a case of a case type when that case type is saved.
 *
 * Before the save a form whose owning app says `refuse` stops it. After the save a broken form of an app that says `unpublish` is unpublished and its author is told.
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
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Intake\BoundFormsCheck;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Judge the forms bound to a case type on its save, and apply the outcome after it.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
 */
class CaseTypeBoundFormsListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param BoundFormsCheck $check  Judges the forms and applies the outcome.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly BoundFormsCheck $check,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refuse before the save, or unpublish after it.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-publishing-a-case-type-must-re-check-the-forms-bound-to-it
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectCreatingEvent === true) {
			$this->refuseWhenAsked(event: $event, entity: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->refuseWhenAsked(event: $event, entity: $event->getNewObject());
			return;
		}

		if ($event instanceof ObjectCreatedEvent === true) {
			$this->unpublishBroken(entity: $event->getObject());
			return;
		}

		if ($event instanceof ObjectUpdatedEvent === true) {
			$this->unpublishBroken(entity: $event->getNewObject());
		}
	}//end handle()

	/**
	 * Refuse the save when a broken form's owning app asks for a refusal.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-persist event.
	 * @param ObjectEntity                            $entity The object being saved.
	 *
	 * @return void
	 */
	private function refuseWhenAsked(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity): void {
		$caseType = $this->caseTypeOf(entity: $entity);
		if ($caseType === null) {
			return;
		}

		$assessment = $this->check->assess(caseType: $caseType);
		if ($assessment['refused'] === false) {
			return;
		}

		$event->setErrors(
			[
				'message' => $this->check->sentenceFor(assessment: $assessment),
				'error' => BoundFormsCheck::RULE,
				'code' => BoundFormsCheck::RULE,
				'forms' => $this->check->listed(assessment: $assessment),
			]
		);
		$event->stopPropagation();
	}//end refuseWhenAsked()

	/**
	 * Unpublish the forms the stored case type broke.
	 *
	 * @param ObjectEntity $entity The stored object.
	 *
	 * @return void
	 */
	private function unpublishBroken(ObjectEntity $entity): void {
		$caseType = $this->caseTypeOf(entity: $entity);
		if ($caseType === null) {
			return;
		}

		$assessment = $this->check->assess(caseType: $caseType);
		if ($assessment['affected'] === [] || $assessment['refused'] === true) {
			return;
		}

		$outcomes = $this->check->apply(assessment: $assessment);
		$this->logger->warning(
			'Dossiq intake: saving a case type left published forms that open no case of it; they were unpublished',
			['caseType' => ($caseType['id'] ?? ''), 'forms' => $outcomes]
		);
	}//end unpublishBroken()

	/**
	 * The object as a case type, or null when it is something else.
	 *
	 * @param ObjectEntity $entity The object.
	 *
	 * @return array<string, mixed>|null The case type payload.
	 */
	private function caseTypeOf(ObjectEntity $entity): ?array {
		try {
			$object = $entity->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug('Dossiq intake: a saved object could not be read: ' . $e->getMessage());
			return null;
		}

		if ($this->check->isCaseType(object: $object) === false) {
			return null;
		}

		return $object;
	}//end caseTypeOf()
}//end class
