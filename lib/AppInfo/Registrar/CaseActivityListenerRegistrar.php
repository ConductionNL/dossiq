<?php

/**
 * Dossiq case activity listener registrar.
 *
 * The one listener that keeps a case's `lastActivityAt` on the last work done
 * on it, subscribed to every seam that work crosses.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-last-activity-at/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\CaseLastActivityListener;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\TaskTerminalEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\Comments\CommentsEvent;

/**
 * Registers the case activity listener.
 *
 * @spec openspec/specs/case-last-activity-at/spec.md
 */
class CaseActivityListenerRegistrar {

	/**
	 * Nextcloud 33 and later dispatch a new note as this subclass, earlier
	 * versions as the CommentsEvent itself; listeners are matched on the exact
	 * class, so both are named.
	 *
	 * @var string
	 */
	private const COMMENT_ADDED_EVENT = 'OCP\Comments\Events\CommentAddedEvent';

	/**
	 * Register the listener on every seam work on a case crosses.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-last-activity-at/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$events = [
			ObjectCreatedEvent::class,
			ObjectUpdatedEvent::class,
			TaskTerminalEvent::class,
			CommentsEvent::class,
			self::COMMENT_ADDED_EVENT,
		];
		foreach ($events as $event) {
			$context->registerEventListener(event: $event, listener: CaseLastActivityListener::class);
		}
	}//end register()
}//end class
