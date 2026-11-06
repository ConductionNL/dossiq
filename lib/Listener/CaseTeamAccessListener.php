<?php

/**
 * A saved case type writes its handling teams onto the case schema's rules.
 *
 * The handling teams of a case type read and update its cases (REQ-ACC-10 as
 * amended on 2026-10-11). An administrator changes them on the case type form,
 * and a publish writes the case type row as well, so the save of a case type
 * row is the one moment every change passes through. This listener hands that
 * moment to {@see CaseTeamAccessProjector}, which computes the rules from every
 * case type and writes them only when they changed.
 *
 * After the save, not before: the projector reads the case types from the
 * store, and a rule computed from the row as it was before the save would be
 * one save behind.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/case-access-control/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Access\CaseTeamAccessProjector;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Re-projects the handling team rules when a case type row is saved.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/case-access-control/spec.md
 */
class CaseTeamAccessListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SettingsService         $settingsService Knows which schema holds the case types.
	 * @param CaseTeamAccessProjector $projector       Writes the rules onto the case schema.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseTeamAccessProjector $projector,
	) {
	}//end __construct()

	/**
	 * Re-project when the saved object is a case type.
	 *
	 * @param Event $event The OpenRegister event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-access-control/spec.md
	 */
	public function handle(Event $event): void {
		$entity = null;
		if ($event instanceof ObjectCreatedEvent === true) {
			$entity = $event->getObject();
		}

		if ($event instanceof ObjectUpdatedEvent === true) {
			$entity = $event->getNewObject();
		}

		if ($entity === null || $this->isCaseType(entity: $entity) === false) {
			return;
		}

		$this->projector->reapply();
	}//end handle()

	/**
	 * Whether the entity is a row of the configured case type schema.
	 *
	 * @param ObjectEntity $entity The saved object.
	 *
	 * @return bool True for a case type.
	 */
	private function isCaseType(ObjectEntity $entity): bool {
		$expected = $this->settingsService->getConfigValue(key: 'case_type_schema');
		if ($expected === '') {
			return false;
		}

		return (string)$entity->getSchema() === $expected;
	}//end isCaseType()
}//end class
