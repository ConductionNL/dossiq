<?php

/**
 * Dossiq case priority listener registrar.
 *
 * One listener, on both pre-persist events, so a case's priority is derived in
 * the same save as the impact or the urgency that changed it. A post-persist
 * derivation would be a second write, and the case would sit in the queue at
 * the wrong priority for however long that took.
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
 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\CasePriorityDerivationListener;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the case priority derivation listener.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
 */
class CasePriorityListenerRegistrar {
	/**
	 * Where the derivation runs relative to the rest.
	 *
	 * BELOW the inherited deadline listener, which sits at -100. The
	 * derivation reads nothing either of the earlier listeners writes, but it
	 * writes the priority LAST so that a listener added later cannot quietly
	 * overwrite the derived answer with a stale one it read before the
	 * derivation ran.
	 */
	public const DERIVATION_PRIORITY = -200;

	/**
	 * Register the case priority derivation listener.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-priority-impact-urgency/specs/case-priority/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$context->registerEventListener(
				event: $event,
				listener: CasePriorityDerivationListener::class,
				priority: self::DERIVATION_PRIORITY
			);
		}

		// The marker derivation registers HERE rather than beside this class in
		// ListenerRegistrar, because the two are one ordering decision. Both run
		// pre-persist on the same two events, and the marker derivation sits
		// BELOW this one deliberately (-300 against -200) so a case type that
		// reads its impact from the risk assessment has the priority derived
		// from the assessment in the same save. Registering them in two places
		// would let somebody change one priority without seeing the other.
		(new CaseMarkerListenerRegistrar())->register(context: $context);
	}//end register()
}//end class
