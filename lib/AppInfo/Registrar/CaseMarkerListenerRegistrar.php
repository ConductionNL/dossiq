<?php

/**
 * Dossiq case marker listener registrar.
 *
 * One listener, on both pre-persist events, so the risk mirror and the marker
 * set of a case are derived in the same save as the change that made them
 * true. ADR-078 asks that raising a marker never slow the write that caused
 * it; computing it into the write that is already happening spends nothing,
 * where a post-persist listener would spend a second write.
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
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\CaseMarkerDerivationListener;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the case marker derivation listener.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
 */
class CaseMarkerListenerRegistrar {

	/**
	 * Where the derivation runs relative to the rest.
	 *
	 * BELOW the priority derivation at -200, because a case type that reads
	 * its impact from the risk assessment needs the priority derived from the
	 * assessment as it is being saved, and the priority listener reads the
	 * payload rather than anything this one writes. Running last also means a
	 * listener added later cannot overwrite the marker set with a stale one it
	 * read before this ran.
	 */
	public const DERIVATION_PRIORITY = -300;

	/**
	 * Register the case marker derivation listener.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/markers-and-assessments-on-the-case/specs/case-management/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$context->registerEventListener(
				event: $event,
				listener: CaseMarkerDerivationListener::class,
				priority: self::DERIVATION_PRIORITY
			);
		}
	}//end register()
}//end class
