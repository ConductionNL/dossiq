<?php

/**
 * Payable Cancel Requested Event
 *
 * The app that raised a payable asks shillinq to withdraw it before finance
 * acts on it, for example because the decision behind the payout is under
 * objection. It dispatches this event with IEventDispatcher::dispatchTyped()
 * and reads the answer from the same object, as with PayableRaisedEvent.
 *
 * @category Event
 * @package  OCA\Shillinq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-005)
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Shillinq\Event;

use OCP\EventDispatcher\Event;

/**
 * The cancel command, answered in place.
 *
 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-005)
 */
final class PayableCancelRequestedEvent extends Event {

	/**
	 * The version of the answer's shape.
	 * @var int
	 */
	public const CONTRACT_VERSION = 1;

	/**
	 * The answer, once a listener accepted the command.
	 * @var array<string, mixed>|null
	 */
	private ?array $result = null;

	/**
	 * Why the command was refused, once a listener refused it.
	 * @var string|null
	 */
	private ?string $error = null;

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp The app that raised the payable.
	 * @param string $reference The reference the app raised it under.
	 * @param string $reason    Why the app withdraws it.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $reference,
		private readonly string $reason,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The app that raised the payable.
	 *
	 * @return string The app id.
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-005)
	 */
	public function getSourceApp(): string {
		return trim($this->sourceApp);
	}//end getSourceApp()

	/**
	 * The reference the app raised the payable under.
	 *
	 * @return string The reference.
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-005)
	 */
	public function getReference(): string {
		return trim($this->reference);
	}//end getReference()

	/**
	 * Why the app withdraws the payable.
	 *
	 * @return string The reason.
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-005)
	 */
	public function getReason(): string {
		return trim($this->reason);
	}//end getReason()

	/**
	 * Accept the command and answer with the payable.
	 *
	 * @param string $payableId The payable's uuid.
	 * @param string $state     Its state.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-005)
	 */
	public function accept(string $payableId, string $state): void {
		$this->error = null;
		$this->result = [
			'contractVersion' => self::CONTRACT_VERSION,
			'payableId' => $payableId,
			'state' => $state,
		];
	}//end accept()

	/**
	 * Refuse the command with a reason.
	 *
	 * @param string $error Why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-005)
	 */
	public function refuse(string $error): void {
		$this->result = null;
		$this->error = $error;
	}//end refuse()

	/**
	 * Whether a listener accepted the command.
	 *
	 * @return bool True when accepted.
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-005)
	 */
	public function isHandled(): bool {
		return $this->result !== null;
	}//end isHandled()

	/**
	 * The answer: contractVersion, payableId and state.
	 *
	 * @return array<string, mixed>|null The answer, or null when not accepted.
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-005)
	 */
	public function getResult(): ?array {
		return $this->result;
	}//end getResult()

	/**
	 * Why the command was refused.
	 *
	 * @return string|null The reason, or null when not refused.
	 *
	 * @spec openspec/changes/inbound-payable-from-another-app/specs/inbound-payables/spec.md (REQ-IPY-005)
	 */
	public function getError(): ?string {
		return $this->error;
	}//end getError()
}//end class
