<?php

/**
 * Refuses a case type save whose intake form would open a case the schema refuses.
 *
 * A case type binds an intake form in `intakeFormRef` and maps its answers in
 * `intakeFormMapping`. The binding is judged when the case type is saved, on
 * the pre-persist events, so a wrong binding is refused before it is stored
 * (decision 181: refuse at once, no report-only release). A case type without
 * a bound form is left alone.
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
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Intake\IntakeFormBindingCheck;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Judge the intake form binding of a case type before it is stored.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
 */
class IntakeFormBindingListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SettingsService        $settingsService Schema slug bridge.
	 * @param IntakeFormBindingCheck $check           Judges the binding.
	 * @param LoggerInterface        $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IntakeFormBindingCheck $check,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Inspect a pre-persist case type save.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/a-request-form-opens-the-case-at-once/specs/case-intake-destination/spec.md#requirement-a-case-types-intake-form-must-be-checked-against-the-case-schema-when-it-is-bound
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
	 * Refuse the save when the binding has findings.
	 *
	 * @param ObjectCreatingEvent|ObjectUpdatingEvent $event  The pre-persist event.
	 * @param ObjectEntity                            $entity The object being saved.
	 *
	 * @return void
	 */
	private function inspect(ObjectCreatingEvent|ObjectUpdatingEvent $event, ObjectEntity $entity): void {
		try {
			$caseType = $entity->jsonSerialize();
		} catch (Throwable $e) {
			$this->logger->debug('Dossiq intake: the case type payload could not be read: ' . $e->getMessage());
			return;
		}

		if ($this->isCaseTypeSchema(object: $caseType) === false) {
			return;
		}

		$findings = $this->check->findingsFor(caseType: $caseType);
		if ($findings === []) {
			return;
		}

		$event->setErrors(
			[
				'message' => $this->check->sentenceFor(findings: $findings),
				'error' => IntakeFormBindingCheck::RULE,
				'code' => IntakeFormBindingCheck::RULE,
				'findings' => $findings,
			]
		);
		$event->stopPropagation();
	}//end inspect()

	/**
	 * Whether this payload is a case type.
	 *
	 * @param array<string, mixed> $object The object payload, `@self` included.
	 *
	 * @return boolean True when the payload belongs to the case type schema.
	 */
	private function isCaseTypeSchema(array $object): bool {
		$expected = $this->settingsService->getConfigValue('case_type_schema');
		if ($expected === '') {
			return false;
		}

		$candidate = (string)($object['@self']['schema'] ?? ($object['schema'] ?? ''));

		return ($candidate !== '' && (
			$candidate === $expected
			|| str_ends_with($candidate, '/' . $expected)
		));
	}//end isCaseTypeSchema()
}//end class
