<?php

/**
 * Keeps the case schema's link vocabulary in step with the role types.
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
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\People\CaseRoleVocabulary;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectDeletedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-syncs the case role vocabulary when a role type is created, changed or
 * deleted.
 *
 * The upgrade's repair step writes the vocabulary once; a role type an admin
 * or an example set adds afterwards would otherwise reach the case schema only
 * at the next upgrade, and until then a person linked in it shows its uuid.
 * An example set saves each row through OpenRegister's object service, so
 * its role types arrive here one by one as well.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
 */
class RoleTypeVocabularyListener implements IEventListener {
	/**
	 * The role type schema's slug, matched besides the configured id.
	 */
	private const ROLE_TYPE_SLUG = 'roleType';

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService The configured role type schema.
	 * @param CaseRoleVocabulary $vocabulary      Writes the roles onto the case schema.
	 * @param LoggerInterface    $logger          Says why a sync failed.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseRoleVocabulary $vocabulary,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Re-sync when the saved or deleted object is a role type.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
	 */
	public function handle(Event $event): void {
		$entity = $this->entityOf(event: $event);
		if ($entity === null || $this->isRoleType(entity: $entity) === false) {
			return;
		}

		try {
			$this->vocabulary->sync();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq people: the case role vocabulary was not re-synced after a role type change: ' . $e->getMessage()
			);
		}
	}//end handle()

	/**
	 * The object the event carries, null for any other event.
	 *
	 * @param Event $event The event.
	 *
	 * @return ObjectEntity|null The object.
	 */
	private function entityOf(Event $event): ?ObjectEntity {
		if ($event instanceof ObjectCreatedEvent === true || $event instanceof ObjectDeletedEvent === true) {
			return $event->getObject();
		}

		if ($event instanceof ObjectUpdatedEvent === true) {
			return $event->getNewObject();
		}

		return null;
	}//end entityOf()

	/**
	 * Whether the object belongs to the role type schema.
	 *
	 * @param ObjectEntity $entity The object.
	 *
	 * @return bool True for a role type.
	 */
	private function isRoleType(ObjectEntity $entity): bool {
		$schema = (string)($entity->getSchema() ?? '');
		if ($schema === '') {
			return false;
		}

		foreach ([(string)$this->settingsService->getConfigValue('role_type_schema'), self::ROLE_TYPE_SLUG] as $name) {
			if ($name !== '' && ($schema === $name || str_ends_with($schema, '/' . $name) === true)) {
				return true;
			}
		}

		return false;
	}//end isRoleType()
}//end class
