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

	/**
	 * Take one of the moves this provider offered.
	 *
	 * 🔑 ADDED 2026-09-15, and the reason is worth keeping. This stub declared
	 * `availableActions()` ALONE while dossiq's provider had implemented
	 * `execute()` in full for months, which made the write half look like
	 * dossiq's missing work. It was not: OpenRegister's own interface declared
	 * no `execute()`, so nothing ever called what dossiq had written, and
	 * `lifecycle-acts-on-the-case` opened against a lane that was already
	 * closed. openregister#3679 was fixed by openregister#3682, which added
	 * this method and the provider branch in `applyTransition()`.
	 *
	 * 🔴 THE SIGNATURE IS COPIED, NOT PARAPHRASED, parameter names included,
	 * for the reason the class docblock gives: these are called by name.
	 *
	 * @param array<string, mixed> $object The object payload before the move.
	 * @param string $userId The uid of the caller, empty when there is no session user.
	 * @param string $action The action id, one `availableActions()` published.
	 * @param array<string, mixed> $data The inputs the caller supplied, keyed by field.
	 *
	 * @return array<string, mixed> Whatever the provider reports about the move.
	 */
	public function execute(array $object, string $userId, string $action, array $data): array;
}//end interface
