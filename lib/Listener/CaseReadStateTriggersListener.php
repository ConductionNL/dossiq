<?php

/**
 * Narrows what makes a case unread to what its case type declares.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
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
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\Contracts\CaseTypeResolverInterface;
use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\UnreadTriggerService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Enforces `caseType.unreadTriggers` per case (unread-state-on-the-case 3.1b).
 *
 * The case schema's `x-openregister-read-state` block names the whole
 * vocabulary a case type may name (D-6). OpenRegister asks, per object,
 * whether a leaf app narrows that list (ObjectReadStatePropertiesEvent); for a
 * case this keeps only the properties its case type's triggers map to, so a
 * type that leaves `assignee` out no longer makes its cases unread when the
 * assignee changes. The event can only narrow, so the schema stays the most a
 * write can count. A case whose type cannot be read keeps the schema's list:
 * too eager a badge, never a silently dark one.
 *
 * Named by string, because the event exists only on an OpenRegister that
 * carries the per-object hook; on an older one nothing dispatches it.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
 */
class CaseReadStateTriggersListener implements IEventListener {

	/**
	 * The OpenRegister event this listener narrows.
	 *
	 * @var string
	 */
	public const EVENT = 'OCA\OpenRegister\Event\ObjectReadStatePropertiesEvent';

	/**
	 * The case schema's slug, when the configured id is not set.
	 *
	 * @var string
	 */
	private const CASE_SLUG = 'case';

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface  $settingsService Names the case schema's id.
	 * @param CaseTypeResolverInterface $caseTypes       Reads the case's effective case type.
	 * @param UnreadTriggerService      $triggers        Maps the type's triggers onto case properties.
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settingsService,
		private readonly CaseTypeResolverInterface $caseTypes,
		private readonly UnreadTriggerService $triggers,
	) {
	}//end __construct()

	/**
	 * Narrow a case's read-state properties to its case type's triggers.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/unread-state-on-the-case/specs/case-management/spec.md
	 */
	public function handle(Event $event): void {
		if (is_a($event, self::EVENT) === false || $this->isCase(schema: $event->getSchema()) === false) {
			return;
		}

		$caseTypeId = ($event->getObject()->getObject()['caseType'] ?? null);
		if (is_string($caseTypeId) === false) {
			$caseTypeId = '';
		}

		if ($caseTypeId === '') {
			return;
		}

		$caseType = $this->caseTypes->effectiveCaseType(caseTypeId: $caseTypeId);
		if ($caseType === []) {
			return;
		}

		$event->narrowTo(
			properties: $this->triggers->propertiesFor(triggers: $this->triggers->triggersFor(caseType: $caseType))
		);
	}//end handle()

	/**
	 * Whether the schema is dossiq's case schema.
	 *
	 * @param object $schema The event's schema.
	 *
	 * @return bool True for the case schema.
	 */
	private function isCase(object $schema): bool {
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($caseSchema !== '' && (string)$schema->getId() === $caseSchema) {
			return true;
		}

		return (string)$schema->getSlug() === self::CASE_SLUG;
	}//end isCase()
}//end class
