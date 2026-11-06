<?php

/**
 * Dossiq: when a case's handler changes, its restricted documents name the new handler as a reader
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
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-openregisters-own-api-applies-the-same-rule-to-the-record
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\RestrictedDocumentReaders;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * OpenRegister admits a restricted document's readers from `accessUsers`, which carries the handlers of its cases.
 * Without this a reassigned case's new handler would be refused its own documents by OpenRegister.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-openregisters-own-api-applies-the-same-rule-to-the-record
 */
class RestrictedDocumentHandlerListener implements IEventListener {

	/**
	 * Constructor.
	 *
	 * @param SettingsService           $settingsService Which schema is the case schema.
	 * @param RestrictedDocumentReaders $readers         Refreshes the readers of the case's restricted documents.
	 * @param LoggerInterface           $logger          Where a failed refresh is reported.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly RestrictedDocumentReaders $readers,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Refresh when a case's `assignee` changed.
	 *
	 * @param Event $event OpenRegister's ObjectUpdatedEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-openregisters-own-api-applies-the-same-rule-to-the-record
	 */
	public function handle(Event $event): void {
		if ($event instanceof ObjectUpdatedEvent === false) {
			return;
		}

		$new = $event->getNewObject();
		$caseSchema = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($caseSchema === '' || (string)$new->getSchema() !== $caseSchema) {
			return;
		}

		$before = (string)($event->getOldObject()?->getObject()['assignee'] ?? '');
		$after = (string)($new->getObject()['assignee'] ?? '');
		if ($before === $after) {
			return;
		}

		try {
			$this->readers->refreshCase(caseId: (string)$new->getUuid());
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: the readers of a reassigned case\'s restricted documents could not be refreshed',
				['app' => Application::APP_ID, 'caseId' => (string)$new->getUuid(), 'exception' => $e->getMessage()]
			);
		}
	}//end handle()
}//end class
