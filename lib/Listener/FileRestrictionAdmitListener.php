<?php

/**
 * Dossiq answer to OpenRegister's question "may this person read this restricted file"
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
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-every-path-that-returns-the-document-or-its-file-honours-the-exception
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Service\Zaakdossier\DocumentAccessExceptionRule;
use OCA\Dossiq\Service\Zaakdossier\FileDocumentLookup;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\IUserManager;
use Throwable;

/**
 * Admits the handler of a case the document is on (and administrators, whom OpenRegister admits already).
 *
 * OpenRegister has already admitted the listed people and groups before it asks; this only adds the case rule
 * OpenRegister cannot know. It never refuses anybody.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-every-path-that-returns-the-document-or-its-file-honours-the-exception
 */
class FileRestrictionAdmitListener implements IEventListener {

	/**
	 * OpenRegister's event, named as a string: OpenRegister is an optional dependency.
	 */
	public const EVENT = 'OCA\OpenRegister\Event\FileRestrictionAdmitEvent';

	/**
	 * Constructor.
	 *
	 * @param FileDocumentLookup      $files       Which document names the file.
	 * @param DocumentAccessExceptionRule $rule        Whether the person handles a case of the document.
	 * @param IUserManager            $userManager Resolves the person asking.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly FileDocumentLookup $files,
		private readonly DocumentAccessExceptionRule $rule,
		private readonly IUserManager $userManager,
	) {
	}//end __construct()

	/**
	 * Admit the person when they may change a case the file's document is on.
	 *
	 * @param Event $event OpenRegister's FileRestrictionAdmitEvent.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-every-path-that-returns-the-document-or-its-file-honours-the-exception
	 */
	public function handle(Event $event): void {
		if (is_a($event, self::EVENT) === false
			|| method_exists($event, 'getFileId') === false
			|| method_exists($event, 'getUserId') === false
			|| method_exists($event, 'admit') === false
		) {
			return;
		}

		try {
			$user = $this->userManager->get((string)$event->getUserId());
			if ($user === null) {
				return;
			}

			$documentId = $this->files->recordIdOfFile(fileId: (int)$event->getFileId());
			if ($documentId !== '' && $this->rule->handlesACaseOf(user: $user, documentId: $documentId) === true) {
				$event->admit();
			}
		} catch (Throwable $e) {
			return;
		}
	}//end handle()
}//end class
