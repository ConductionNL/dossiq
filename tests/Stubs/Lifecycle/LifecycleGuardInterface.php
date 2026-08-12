<?php

/**
 * Test stub mirroring OpenRegister's LifecycleGuardInterface.
 *
 * Procest's lib/Lifecycle guards implement OR's
 * OCA\OpenRegister\Lifecycle\LifecycleGuardInterface, which is only present
 * at runtime when OpenRegister is installed. This stub lets the procest unit
 * suite + static analysers resolve the type without the OR app on the
 * classpath. It is autoloaded via the OCA\OpenRegister\ → tests/Stubs/ map in
 * composer.json (autoload-dev).
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Stub
 * @package  OCA\OpenRegister\Lifecycle
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

/**
 * Apps implement this interface to authorise a lifecycle transition.
 */
interface LifecycleGuardInterface {

	/**
	 * Authorise (or deny) a transition.
	 *
	 * @param array<string, mixed> $object The loaded object payload at its current state.
	 * @param string $action The transition action being applied.
	 * @param string $userId The uid of the caller.
	 *
	 * @return GuardResult Allow or deny + optional message.
	 */
	public function check(array $object, string $action, string $userId): GuardResult;
}//end interface
