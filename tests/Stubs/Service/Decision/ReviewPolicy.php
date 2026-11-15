<?php

/**
 * Test stub for OCA\OpenRegister\Service\Decision\ReviewPolicy.
 *
 * Loaded by tests/bootstrap.php when the real class is absent. It declares
 * no members: dossiq only needs the type, so the vetoable decision event
 * stubs can carry the same parameter and return types as OpenRegister's.
 * StubApiDriftTest checks the surface that is here.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Service\Decision
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 *
 * @spec exclude A declaration-only mirror of another app's class, not
 * behaviour of this one.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Service\Decision;

use OCA\OpenRegister\Kernel\Contracts\ReviewPolicyInterface;

/**
 * The review policy that applies to a decision (type only; the real class is final with a private constructor).
 */
class ReviewPolicy implements ReviewPolicyInterface {

	/**
	 * Whether the decider owns the decision.
	 *
	 * @return bool False on the stub.
	 */
	public function isDeciderOwned(): bool {
		return false;
	}//end isDeciderOwned()

	/**
	 * The supervising groups.
	 *
	 * @return array<int, string> None on the stub.
	 */
	public function getSupervisors(): array {
		return [];
	}//end getSupervisors()

	/**
	 * Whether others' decisions are hidden.
	 *
	 * @return bool False on the stub.
	 */
	public function hidesOthers(): bool {
		return false;
	}//end hidesOthers()

	/**
	 * The policy as an array.
	 *
	 * @return array<string, mixed> Empty on the stub.
	 */
	public function toArray(): array {
		return [];
	}//end toArray()
}//end class
