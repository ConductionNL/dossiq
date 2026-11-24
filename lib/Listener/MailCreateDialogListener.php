<?php

/**
 * Dossiq on Mail's page: offer dossiq's own New case dialog to OpenRegister's Mail panel.
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
 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

use OCA\Dossiq\Kernel\AppIdentity;
use OCP\AppFramework\Http\Events\BeforeTemplateRenderedEvent;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\Util;

/**
 * Loads the small script that offers dossiq's New case dialog on Mail's page.
 *
 * OpenRegister's Mail panel opens an app's own create dialog for a schema when
 * the app offers one (window.OCA.OpenRegister.mailCreateDialogs). The script
 * only registers; the dialog's code loads when the person presses
 * "+ New case from this mail". The translations go along so the dialog reads
 * in the person's language there too.
 *
 * @template-implements IEventListener<Event>
 *
 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
 */
class MailCreateDialogListener implements IEventListener {

	/**
	 * Add the script to Mail's logged-in page.
	 *
	 * Guarded on the server's script pipeline: `Util::addScript` reaches into
	 * `OC\AppScriptDependency`, which a unit test process does not autoload.
	 *
	 * @param Event $event The event.
	 *
	 * @return void
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) — Nextcloud Util API is static by design
	 *
	 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
	 */
	public function handle(Event $event): void {
		if ($this->rendersMailForASignedInUser(event: $event) === false
			|| class_exists('OC\\AppScriptDependency') === false
		) {
			return;
		}

		Util::addTranslations(application: AppIdentity::APP_ID);
		Util::addScript(application: AppIdentity::APP_ID, file: AppIdentity::APP_ID.'-mail-create-dialog');
	}//end handle()

	/**
	 * Whether the event renders Mail's page for a signed-in user.
	 *
	 * @param Event $event The event.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/friendly-case-create-form/spec.md#requirement-req-fcf-011-the-same-new-case-dialog-from-a-mail
	 */
	public function rendersMailForASignedInUser(Event $event): bool {
		return $event instanceof BeforeTemplateRenderedEvent
			&& $event->isLoggedIn() === true
			&& $event->getResponse()->getApp() === 'mail';
	}//end rendersMailForASignedInUser()
}//end class
