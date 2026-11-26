<?php
/**
 * Stub of OpenRegister's LifecycleStatesKeeperInterface for running the unit suite without OpenRegister.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Stub
 * @package  OCA\OpenRegister\Kernel\Contracts\Core
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Kernel\Contracts\Core;

/**
 * Lifts the initial-state check on creates for the length of one operation.
 */
interface LifecycleStatesKeeperInterface {
	/**
	 * Run the operation; while it runs, a create keeps the lifecycle state it carries.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return mixed What the operation returned.
	 */
	public function keepingStates(callable $operation): mixed;
}//end interface
