<?php

/**
 * Dossiq case term listener registrar.
 *
 * The two listeners that follow a case's statutory term into its save: the
 * deadline the term sets, and the applicant's note about that deadline.
 * Wired by `CaseTypeListenerRegistrar`, after its own save listeners, at the
 * priorities that registrar declares beside the inherited deadline's, so the
 * three deadline priorities stay readable in one place.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\Listener\Registrar
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
 * @spec openspec/specs/termijn-binding/spec.md
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener\Registrar;

use OCA\Dossiq\Listener\ApplicantTermNoteListener;
use OCA\Dossiq\Listener\CaseDeadlineFollowsTermListener;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the listeners that write the statutory term's deadline and the applicant's term note.
 *
 * @spec openspec/specs/termijn-binding/spec.md
 */
class CaseTermListenerRegistrar {
	/**
	 * Register the term deadline and the applicant's term note listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/termijn-binding/spec.md
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// UPDATE ONLY. No term exists before the case does; the bind that
		// follows the create writes the date back through the mirror.
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: CaseDeadlineFollowsTermListener::class,
			priority: CaseTypeListenerRegistrar::TERM_DEADLINE_PRIORITY
		);

		// UPDATE ONLY, for the same reason: a new case has no open request
		// and no extension, and its first update writes the date.
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: ApplicantTermNoteListener::class,
			priority: CaseTypeListenerRegistrar::TERM_NOTE_PRIORITY
		);
	}//end register()
}//end class
