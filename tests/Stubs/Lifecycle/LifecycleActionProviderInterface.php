<?php

/**
 * Test stub mirroring OpenRegister's LifecycleActionProviderInterface.
 *
 * Dossiq's CaseActionProvider implements OR's
 * OCA\OpenRegister\Lifecycle\LifecycleActionProviderInterface, which is only
 * present at runtime when OpenRegister is installed. This stub lets the dossiq
 * unit suite and the static analysers resolve the type without the OR app on
 * the classpath. It is autoloaded via the OCA\OpenRegister\ -> tests/Stubs/ map
 * in composer.json (autoload-dev).
 *
 * 🔴 THE SIGNATURE IS COPIED, NOT PARAPHRASED — parameter names included. These
 * are called by name from OpenRegister's TransitionEngine, so a stub that
 * renamed one would let a call compile here and fail on a live instance, which
 * is the class of defect a stub is most likely to introduce.
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
 * Answers "which lifecycle moves does this object offer right now".
 */
interface LifecycleActionProviderInterface {
	/**
	 * List the actions the object offers from its current state.
	 *
	 * @param array<string, mixed> $object The loaded object payload at its current state.
	 * @param string $userId The uid of the caller.
	 *
	 * @return list<array{action:string,to:string,requires:?string,description:?string,inputs:list<array{field:string,required:bool}>,label?:string,blocked?:bool}>
	 */
	public function availableActions(array $object, string $userId): array;
}//end interface
