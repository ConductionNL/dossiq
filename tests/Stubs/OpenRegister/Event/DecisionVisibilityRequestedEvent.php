<?php

/**
 * OpenRegister DecisionVisibilityRequestedEvent stub.
 *
 * Declaration-only mirror of OpenRegister's visibility question
 * (openregister#4592, reviewer-owns-their-decisions REQ-DEC-003):
 * `DecisionVisibility` dispatches it before another reviewer's decision is
 * hidden from a caller under `x-openregister-review {hideOthers: true}`, so the
 * app that owns the subject can allow() the caller to see it.
 * `CaseDecisionAccessListener` listens to it and is analysed and tested without
 * the openregister runtime present.
 *
 * The decision and the policy are plain objects: dossiq reads neither.
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
 * May this user see a decision hidden under hideOthers?
 */
class DecisionVisibilityRequestedEvent extends Event {

	/**
	 * Whether a listener allowed it.
	 *
	 * @var boolean
	 */
	private bool $allowed = false;

	/**
	 * Constructor.
	 *
	 * @param Decision|null        $decision The hidden decision, or null when its record is gone.
	 * @param string               $actorId  The user who reads it.
	 * @param ReviewPolicy         $policy   The review policy that applies.
	 * @param array<string, mixed> $subject  subjectType, subjectId, fileId, objectId, register, schema, objectUuid.
	 */
	public function __construct(
		private readonly ?Decision $decision,
		private readonly string $actorId,
		private readonly ReviewPolicy $policy,
		private readonly array $subject,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The hidden decision.
	 *
	 * @return object|null
	 */
	public function getDecision(): ?Decision {
		return $this->decision;
	}//end getDecision()

	/**
	 * The user who reads it.
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
	 * Let the caller see the decision.
	 *
	 * @return void
	 */
	public function allow(): void {
		$this->allowed = true;
	}//end allow()

	/**
	 * The answer after every listener.
	 *
	 * @return bool
	 */
	public function isAllowed(): bool {
		return $this->allowed;
	}//end isAllowed()
}//end class
