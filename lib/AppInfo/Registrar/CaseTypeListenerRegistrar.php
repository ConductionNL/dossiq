<?php

/**
 * Dossiq case type listener registrar.
 *
 * The two save-time rules REQ-CT-20 gives a case type that derives from a
 * parent: a parent chain may not return to itself, and a case of a child
 * that sets no processing deadline gets its parent's. Both need the resolved
 * chain from `CaseTypeResolver`, which no declarative OpenRegister rule can
 * reach, so both are pre-persist listeners.
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
 * @spec openspec/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\CaseDeadlineFollowsTermListener;
use OCA\Dossiq\Listener\CaseInheritedDeadlineListener;
use OCA\Dossiq\Listener\CaseTypeParentCycleListener;
use OCA\Dossiq\Listener\CaseTypePortalWithdrawalListener;
use OCA\Dossiq\Listener\MilestoneDependencyCycleListener;
use OCA\OpenRegister\Event\ObjectCreatingEvent;
use OCA\OpenRegister\Event\ObjectUpdatingEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the case type inheritance listeners.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/specs/case-types/spec.md
 */
class CaseTypeListenerRegistrar {
	/**
	 * Where the inherited deadline listener runs relative to the rest.
	 *
	 * BELOW OpenRegister's `CalculationOnSaveListener`, which registers at the
	 * default priority of 0. A higher priority runs first, so a negative one
	 * runs after the calculation has filled in `startDate`, which is the date
	 * the inherited deadline is counted from.
	 */
	public const INHERITED_DEADLINE_PRIORITY = -100;

	/**
	 * Where the statutory term's deadline is put back into a case save.
	 *
	 * BELOW the inherited deadline listener and the calculation, because it
	 * overrides both: while a case has a statutory term, that term's end date
	 * is the deadline (one term engine, REQ-OTE-01), and a listener that ran
	 * before them would have its value replaced by the calculated one.
	 */
	public const TERM_DEADLINE_PRIORITY = -110;

	/**
	 * Register the case type inheritance listeners.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-types/spec.md
	 * @spec openspec/changes/one-term-engine/specs/termijn-binding/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		foreach ([ObjectCreatingEvent::class, ObjectUpdatingEvent::class] as $event) {
			$context->registerEventListener(
				event: $event,
				listener: CaseTypeParentCycleListener::class
			);
			// A withdrawal the portal offers must be one the workflow can
			// write, so the save that declares it is the one refused.
			$context->registerEventListener(
				event: $event,
				listener: CaseTypePortalWithdrawalListener::class
			);
			// The same shape one level down: a milestone whose `dependsOn`
			// closes a loop is refused by the edit that creates it, not by
			// whatever touches the case type next.
			$context->registerEventListener(
				event: $event,
				listener: MilestoneDependencyCycleListener::class
			);
			$context->registerEventListener(
				event: $event,
				listener: CaseInheritedDeadlineListener::class,
				priority: self::INHERITED_DEADLINE_PRIORITY
			);
		}//end foreach

		// UPDATE ONLY. No term exists before the case does; the bind that
		// follows the create writes the date back through the mirror.
		$context->registerEventListener(
			event: ObjectUpdatingEvent::class,
			listener: CaseDeadlineFollowsTermListener::class,
			priority: self::TERM_DEADLINE_PRIORITY
		);
	}//end register()
}//end class
