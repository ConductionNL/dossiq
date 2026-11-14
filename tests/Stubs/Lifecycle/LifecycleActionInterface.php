<?php

/**
 * Test stub mirroring OpenRegister's LifecycleActionInterface.
 *
 * Dossiq's lib/Lifecycle actions implement OR's
 * OCA\OpenRegister\Lifecycle\LifecycleActionInterface, which is only present at
 * runtime when OpenRegister is installed. This stub lets the dossiq unit suite
 * and the static analysers resolve the type without the OR app on the
 * classpath. It is autoloaded via the OCA\OpenRegister\ → tests/Stubs/ map in
 * composer.json (autoload-dev).
 *
 * 🔴 THE SIGNATURE IS COPIED, NOT PARAPHRASED — parameter names included. These
 * are called by name from OpenRegister's LifecycleActionExecutor, so a stub
 * that renamed one would let a call compile here and fail on a live instance,
 * which is the class of defect a stub is most likely to introduce.
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
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Lifecycle;

/**
 * Runs a declared lifecycle action on a transition.
 */
interface LifecycleActionInterface {
	/**
	 * Run the action on a transitioning object.
	 *
	 * @param LifecycleActionContext $context The object after and before the transition, the declared parameters and the action name.
	 *
	 * @return array<string, mixed> The object payload, with any self-mutations applied.
	 */
	public function execute(LifecycleActionContext $context): array;
}//end interface
