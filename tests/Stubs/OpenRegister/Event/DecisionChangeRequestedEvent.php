<?php

/**
 * OpenRegister DecisionChangeRequestedEvent stub.
 *
 * Declaration-only mirror of OpenRegister's vetoable event (openregister#4583,
 * reviewer-owns-their-decisions): `DecisionOwnership` dispatches it when somebody
 * other than the decider asks to change a decision under
 * `x-openregister-review {ownership: decider}`, so the app that owns the subject
 * can allow() or refuse() the change. `CaseDecisionAccessListener` listens to it
 * and is analysed and tested without the openregister runtime present.
 *
 * The constructor takes the decision and the policy as plain objects: the real
 * `OCA\OpenRegister\Db\Decision` and `Service\Decision\ReviewPolicy` are not
 * stubbed because dossiq reads neither. Every getter dossiq calls is mirrored.
 *
 * NOT psr-4 autoloadable, like the stubs beside it: tests/bootstrap.php
 * includes it only when the real class is absent.
 *
 * @category Tests
 * @package  OCA\OpenRegister\Event
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
 * @spec exclude A declaration-only mirror of another app's event contract, not
 * behaviour of this one; the dossiq side it lets the analysers see is specified
 * in openspec/changes/woo-request-scoped-access/specs/case-reviewer-access/spec.md.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Decision;
use OCA\OpenRegister\Service\Decision\ReviewPolicy;
use OCP\EventDispatcher\Event;

/**
 * Somebody other than the decider asks to change a decision.
 */
class DecisionChangeRequestedEvent extends Event {

	/**
	 * Why the owning app refused, or null.
	 *
	 * @var string|null
	 */
	private ?string $reason = null;

	/**
	 * Constructor.
	 *
	 * @param Decision               $decision The current decision.
	 * @param string               $actorId  The user who wants to change it.
	 * @param ReviewPolicy           $policy   The review policy that applies.
	 * @param array<string, mixed> $subject  subjectType, subjectId, fileId, objectId, register, schema, objectUuid.
	 * @param bool                 $allowed  The answer before any listener: a supervisor of the policy.
	 */
	public function __construct(
		private readonly Decision $decision,
		private readonly string $actorId,
		private readonly ReviewPolicy $policy,
		private readonly array $subject,
		private bool $allowed,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The current decision.
	 *
	 * @return object
	 */
	public function getDecision(): Decision {
		return $this->decision;
	}//end getDecision()

	/**
	 * The user who wants to change it.
	 *
	 * @return string
	 */
	public function getActorId(): string {
		return $this->actorId;
	}//end getActorId()

	/**
	 * The policy that applies.
	 *
	 * @return object
	 */
	public function getPolicy(): ReviewPolicy {
		return $this->policy;
	}//end getPolicy()

	/**
	 * What the decision is on.
	 *
	 * @return array<string, mixed>
	 */
	public function getSubject(): array {
		return $this->subject;
	}//end getSubject()

	/**
	 * Let the change through.
	 *
	 * @return void
	 */
	public function allow(): void {
		$this->allowed = true;
		$this->reason = null;
	}//end allow()

	/**
	 * Refuse the change.
	 *
	 * @param string $reason Why.
	 *
	 * @return void
	 */
	public function refuse(string $reason): void {
		$this->allowed = false;
		$this->reason = $reason;
	}//end refuse()

	/**
	 * Whether the change may go through.
	 *
	 * @return bool
	 */
	public function isAllowed(): bool {
		return $this->allowed;
	}//end isAllowed()

	/**
	 * Why it was refused, or null.
	 *
	 * @return string|null
	 */
	public function getReason(): ?string {
		return $this->reason;
	}//end getReason()
}//end class
