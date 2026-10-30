<?php

/**
 * Puts the instance's role types back on the case schema after anything rewrites it.
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
 * @spec openspec/changes/case-roles-survive-a-schema-reimport/specs/people-on-the-case/spec.md#requirement-the-case-schemas-role-vocabulary-survives-a-rewrite-of-the-schema
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\People\CaseRoleVocabulary;
use OCA\Dossiq\Service\SettingsService;
use OCA\OpenRegister\Event\SchemaUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Re-syncs the case role vocabulary when the case schema is updated.
 *
 * The shipped register declares only the generic party roles in the case
 * schema's `linkRoles`, so every import of it (an upgrade, a window refresh)
 * dropped the role types the sync had added, and a person linked in one showed
 * its uuid. The sync writes nothing when the schema already carries the
 * vocabulary, so its own update ends here.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/case-roles-survive-a-schema-reimport/specs/people-on-the-case/spec.md#requirement-the-case-schemas-role-vocabulary-survives-a-rewrite-of-the-schema
 */
class CaseSchemaVocabularyListener implements IEventListener {
	/**
	 * The case schema's slug, matched besides the configured id.
	 */
	private const CASE_SLUG = 'case';

	/**
	 * Constructor.
	 *
	 * @param SettingsService    $settingsService The configured case schema.
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
	 * Re-sync when the updated schema is the case schema.
	 *
	 * @param Event $event The dispatched event.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-roles-survive-a-schema-reimport/specs/people-on-the-case/spec.md#requirement-the-case-schemas-role-vocabulary-survives-a-rewrite-of-the-schema
	 */
	public function handle(Event $event): void {
		if ($event instanceof SchemaUpdatedEvent === false) {
			return;
		}

		$schema = $event->getNewSchema();
		$caseSchema = (string)$this->settingsService->getConfigValue('case_schema');
		$isCase = ($caseSchema !== '' && (string)$schema->getId() === $caseSchema)
			|| (string)$schema->getSlug() === self::CASE_SLUG;
		if ($isCase === false) {
			return;
		}

		try {
			$this->vocabulary->sync();
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq people: the case role vocabulary was not re-synced after the case schema changed: ' . $e->getMessage()
			);
		}
	}//end handle()
}//end class
