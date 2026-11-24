<?php

/**
 * Drops the kept case type rights rows when what they are compiled from changes.
 *
 * The rows come from the case types and the organisation roles. A create,
 * update or delete of either drops the entry in
 * {@see \OCA\Dossiq\Service\Access\CaseTypeRightsRowsCache}, so the next
 * access question compiles the rows again from the new data.
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
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Access\CaseTypeRightsRowsCache;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;

/**
 * Forgets the case type rights rows on a case type or organisation role change.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
 */
class CaseTypeRightsSourcesListener implements IEventListener {
	/**
	 * The object events, by name: OpenRegister may be absent.
	 */
	public const EVENTS = [
		'OCA\\OpenRegister\\Event\\ObjectCreatedEvent',
		'OCA\\OpenRegister\\Event\\ObjectUpdatedEvent',
		'OCA\\OpenRegister\\Event\\ObjectDeletedEvent',
	];

	/**
	 * The settings naming the schemas the rows are compiled from.
	 */
	private const SOURCE_SCHEMA_KEYS = ['case_type_schema', 'organisatie_rol_schema'];

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settings The dossiq settings.
	 * @param CaseTypeRightsRowsCache  $cache    The kept rows.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settings,
		private readonly CaseTypeRightsRowsCache $cache,
	) {
	}//end __construct()

	/**
	 * Drop the kept rows when the changed object is a case type or an organisation role.
	 *
	 * @param Event $event The object event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-type-rights-enforced/specs/case-types/spec.md
	 */
	public function handle(Event $event): void {
		if ($this->isObjectEvent(event: $event) === false) {
			return;
		}

		$sources = $this->sourceSchemas();
		foreach ($this->schemasOf(event: $event) as $schema) {
			if (in_array($schema, $sources, true) === true) {
				$this->cache->forget();
				return;
			}
		}
	}//end handle()

	/**
	 * Whether the event is one of OpenRegister's object create, update or delete events.
	 *
	 * @param Event $event The event.
	 *
	 * @return bool
	 */
	private function isObjectEvent(Event $event): bool {
		foreach (self::EVENTS as $name) {
			if (is_a($event, $name) === true) {
				return true;
			}
		}

		return false;
	}//end isObjectEvent()

	/**
	 * The configured ids of the schemas the rows are compiled from.
	 *
	 * @return array<int, string>
	 */
	private function sourceSchemas(): array {
		$ids = [];
		foreach (self::SOURCE_SCHEMA_KEYS as $key) {
			$id = trim((string)$this->settings->getConfigValue(key: $key));
			if ($id !== '') {
				$ids[] = $id;
			}
		}

		return $ids;
	}//end sourceSchemas()

	/**
	 * The schema ids of the objects the event carries (before and after an update).
	 *
	 * @param Event $event The object event.
	 *
	 * @return array<int, string>
	 */
	private function schemasOf(Event $event): array {
		$schemas = [];
		foreach (['getObject', 'getNewObject', 'getOldObject'] as $getter) {
			if (method_exists($event, $getter) === false) {
				continue;
			}

			$object = $event->$getter();
			if (is_object($object) === true && method_exists($object, 'getSchema') === true) {
				$schemas[] = trim((string)($object->getSchema() ?? ''));
			}
		}

		return $schemas;
	}//end schemasOf()
}//end class
