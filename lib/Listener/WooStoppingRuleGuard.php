<?php

/**
 * Dossiq Woo stopping rule guard: no change to the rule through OpenRegister once review started.
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
 * @spec openspec/changes/woo-review-recall-and-stopping/specs/woo-review-recall/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Woo\WooStoppingRules;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectDeletingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;

/**
 * Refuses an update or delete of a `wooStoppingRule` once any document of its case is marked.
 *
 * The declare route refuses the same; this covers writes that go to
 * OpenRegister directly.
 *
 * @spec openspec/changes/woo-review-recall-and-stopping/specs/woo-review-recall/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
 *
 * @template-implements IEventListener<Event>
 */
class WooStoppingRuleGuard implements IEventListener {

	/**
	 * The error code OpenRegister answers with.
	 */
	public const ERROR_CODE = 'woo.stopping-rule-locked';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings, for the rule schema.
	 * @param WooStoppingRules $rules Whether review started on the rule's case.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly WooStoppingRules $rules,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Inspect a rule update or delete.
	 *
	 * @param Event $event The OpenRegister event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/woo-review-recall-and-stopping/specs/woo-review-recall/spec.md#requirement-a-stopping-rule-is-declared-before-review-and-then-fixed-req-wrs-001
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatingEvent === true) {
			$this->inspect(event: $event, stored: ($event->getOldObject() ?? $event->getNewObject()));
			return;
		}

		if ($event instanceof ObjectDeletingEvent === true) {
			$this->inspect(event: $event, stored: $event->getObject());
		}
	}//end handle()

	/**
	 * Refuse when the object is a stopping rule whose case has a marked document.
	 *
	 * @param ObjectUpdatingEvent|ObjectDeletingEvent $event The event.
	 * @param ObjectEntity $stored The stored rule.
	 *
	 * @return void
	 */
	private function inspect(ObjectUpdatingEvent|ObjectDeletingEvent $event, ObjectEntity $stored): void {
		$expected = $this->settingsService->getConfigValue(WooStoppingRules::SCHEMA_KEY);
		$schema = (string)$stored->getSchema();
		if ($expected === '' || ($schema !== $expected && str_ends_with($schema, '/'.$expected) === false)) {
			return;
		}

		$caseId = (string)($stored->getObject()['case'] ?? '');
		if ($this->rules->reviewStarted(caseId: $caseId) === false) {
			return;
		}

		$event->setErrors(['message' => WooStoppingRules::LOCKED, 'code' => self::ERROR_CODE]);
		$event->stopPropagation();
		$this->logger->info('Dossiq: refused a change to a Woo stopping rule after review started', ['case' => $caseId]);
	}//end inspect()
}//end class
