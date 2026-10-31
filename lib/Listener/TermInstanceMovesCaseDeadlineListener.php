<?php

/**
 * Dossiq term instance moves case deadline listener.
 *
 * A statutory term written through OpenRegister directly, rather than
 * through the term engine (an example set, an import, the API), moves its
 * case's deadline as soon as it is saved, as one the term engine writes
 * does. Without it the case keeps the calculated fallback (startDate plus the
 * case type's term) until something else saves it. The mirror decides which
 * of the case's terms counts and writes only when the case differs, so a term
 * the engine already followed costs a read.
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
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Termijn\CaseDeadlineMirror;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * After a term instance is saved, bring its case's deadline in line.
 *
 * @implements IEventListener<Event>
 *
 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
 */
class TermInstanceMovesCaseDeadlineListener implements IEventListener {
	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService Names the term instance schema.
	 * @param CaseDeadlineMirror $mirror          Follows a statutory term onto its case.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseDeadlineMirror $mirror,
	) {
	}//end __construct()

	/**
	 * Follow a created or updated term instance onto its case.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md#requirement-the-case-deadline-is-the-statutory-terms-current-end-req-ote-01
	 */
	public function handle(Event $event): void {
		$entity = null;
		if ($event instanceof ObjectCreatedEvent === true) {
			$entity = $event->getObject();
		}

		if ($event instanceof ObjectUpdatedEvent === true) {
			$entity = $event->getNewObject();
		}

		if ($entity === null || $this->isTermInstance(entity: $entity) === false) {
			return;
		}

		$this->mirror->followInstance(instance: (array)($entity->getObject() ?? []));
	}//end handle()

	/**
	 * Whether the entity is a term instance.
	 *
	 * @param ObjectEntity $entity The saved object.
	 *
	 * @return bool
	 */
	private function isTermInstance(ObjectEntity $entity): bool {
		$expected = (string)$this->settingsService->getConfigValue(key: 'termijn_instance_schema');

		return $expected !== '' && (string)$entity->getSchema() === $expected;
	}//end isTermInstance()
}//end class
