<?php

/**
 * Dossiq Case Merge Registrar.
 *
 * Wires {@see \OCA\Dossiq\Listener\CaseMergedListener} onto OpenRegister's
 * `ObjectsMergedEvent`. The `::class` reference does not autoload, so the
 * registration is safe on an instance without OpenRegister: the event is
 * never dispatched and the listener never runs.
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
 * @spec openspec/changes/case-merge/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\AppInfo\Registrar;

use OCA\Dossiq\Listener\CaseMergedListener;
use OCA\OpenRegister\Event\ObjectsMergedEvent;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

/**
 * Registers the merge follower.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/case-merge/specs/case-management/spec.md
 */
class CaseMergeRegistrar {
	/**
	 * Register the merge follower.
	 *
	 * @param IRegistrationContext $context The registration context.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md
	 */
	public function register(IRegistrationContext $context): void {
		$context->registerEventListener(
			event: ObjectsMergedEvent::class,
			listener: CaseMergedListener::class
		);
	}//end register()
}//end class
