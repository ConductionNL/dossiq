<?php

/**
 * Registers the derived-status listener.
 *
 * @category AppInfo
 * @package  OCA\Dossiq\AppInfo\Registrar
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\DerivedStatusListener;
use OCA\Dossiq\Listener\DerivedStatusTimelineListener;
use OCA\Dossiq\Service\Status\DerivedStatusJournal;
use OCA\OpenRegister\Event\ObjectUpdatedEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Wires the listener that moves a case into a status that has become true.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
 */
class DerivedStatusListenerRegistrar {

	/**
	 * Where the derivation runs relative to the rest.
	 *
	 * BELOW the priority derivation at -200, so the status is settled last.
	 * The order matters in one direction only: a listener that reads the
	 * status must not read the one the case had before the derivation, and
	 * every such listener today sits above this number.
	 *
	 * @var int
	 */
	public const DERIVATION_PRIORITY = -300;

	/**
	 * Register the derived-status listener.
	 *
	 * ONLY on update. A case being created has no documents and no form
	 * answers, so every condition is false; its status comes from the case
	 * type's `initialStatus` through OpenRegister's own prefill, and racing
	 * that prefill for a verdict of "nothing derives" would be work with a
	 * chance of harm and none of benefit.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/what-a-status-declares/specs/status-transition-engine/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		// ONE journal for the two listeners. An autowired class is built again
		// for every consumer, and two empty journals would stage into one and
		// take from the other, so the derived move would never be recorded and
		// nothing would say why. `registerService()` memoises; that is the
		// whole reason this registration is explicit.
		$context->registerService(
			DerivedStatusJournal::class,
			static function (): DerivedStatusJournal {
				return new DerivedStatusJournal();
			}
		);

		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: DerivedStatusListener::class,
			priority: self::DERIVATION_PRIORITY
		);

		// The post-persist half: it records the staged move on the case
		// timeline once the save that carried it has actually landed.
		$context->registerEventListener(
			event: ObjectUpdatedEvent::class,
			listener: DerivedStatusTimelineListener::class
		);
	}//end register()
}//end class
