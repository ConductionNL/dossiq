<?php

/**
 * Procest CMMN Illegal Plan-Item Transition Exception.
 *
 * Thrown whenever `PlanItemTransitions::assertLegal()` is asked to validate a
 * transition that is not present in the exhaustive legal-transition table for
 * the item's type. The engine never silently no-ops a rejected transition —
 * every illegal request surfaces as this exception.
 *
 * @category Exception
 * @package  OCA\Procest\Service\Cmmn
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-002
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Cmmn;

use RuntimeException;

/**
 * Thrown on any plan-item state transition not present in the legal table.
 *
 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-002
 */
class IllegalPlanItemTransitionException extends RuntimeException {
	/**
	 * Constructor.
	 *
	 * @param string $itemId Plan-item id the transition was attempted on.
	 * @param string $itemType Plan-item type (`stage`|`humanTask`|`milestone`).
	 * @param string $fromState Current state at the time of the attempt.
	 * @param string $toState Requested target state.
	 */
	public function __construct(
		private readonly string $itemId,
		private readonly string $itemType,
		private readonly string $fromState,
		private readonly string $toState,
	) {
		parent::__construct(message: 'illegal_plan_item_transition');
	}//end __construct()

	/**
	 * The plan-item id the illegal transition was attempted on.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-002
	 */
	public function getItemId(): string {
		return $this->itemId;
	}//end getItemId()

	/**
	 * The plan-item type.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-002
	 */
	public function getItemType(): string {
		return $this->itemType;
	}//end getItemType()

	/**
	 * The state the item was in when the illegal transition was attempted.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-002
	 */
	public function getFromState(): string {
		return $this->fromState;
	}//end getFromState()

	/**
	 * The illegal target state that was requested.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/cmmn-adaptive-case/spec.md#REQ-CMMN-002
	 */
	public function getToState(): string {
		return $this->toState;
	}//end getToState()
}//end class
