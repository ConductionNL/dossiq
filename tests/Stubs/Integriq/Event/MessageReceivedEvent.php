<?php

/**
 * Integriq MessageReceivedEvent test stub.
 *
 * Mirrors integriq's ADR-041 mail-intake contract verbatim (constructor
 * parameter names AND order, every getter, the three outcome constants and
 * the validation `setOutcome()` performs) so MessageReceivedListener can be
 * unit-tested without the integriq app installed. The real class ships in
 * integriq (`lib/Event/MessageReceivedEvent.php`, change
 * `mail-intake-creates-cases`, PR 2052), read at `development` on 2026-09-18;
 * this stub is loaded by tests/bootstrap.php only when the real class is
 * absent.
 *
 * `setOutcome()` THROWS on an unknown outcome and on a claiming outcome with
 * no object reference, and it throws here too. A stub that accepted either
 * would let a listener pass a test while sending integriq an answer it
 * refuses, and there is no reason slot on a decline in the real class either:
 * that absence is why the listener writes its reason to dossiq's own intake
 * log, and a stub that invented the slot would hide it.
 *
 * @category Tests
 * @package  OCA\Integriq\Event
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Integriq\Event;

use InvalidArgumentException;
use OCP\EventDispatcher\Event;

/**
 * Typed cross-app command: "this message arrived, is it yours?".
 *
 * @spec exclude A declaration-only mirror of another app's event contract, not
 * behaviour of this one: the requirement it serves is integriq's mail intake,
 * which is specified in integriq's own repo and not here, and the dossiq side
 * it lets the analysers see is specified in
 * openspec/changes/inbound-messages-consume-integriq/specs/case-email-integration/spec.md.
 */
class MessageReceivedEvent extends Event {

	/**
	 * The listener linked the message to an object it already had.
	 *
	 * @var string
	 */
	public const OUTCOME_LINKED = 'linked';

	/**
	 * The listener created an object for the message.
	 *
	 * @var string
	 */
	public const OUTCOME_CREATED = 'created';

	/**
	 * The listener looked and wants nothing to do with the message.
	 *
	 * @var string
	 */
	public const OUTCOME_DECLINED = 'declined';

	/**
	 * Every outcome a listener may set.
	 *
	 * @var array<int,string>
	 */
	public const OUTCOMES = [self::OUTCOME_LINKED, self::OUTCOME_CREATED, self::OUTCOME_DECLINED];

	/**
	 * The outcome a listener set, null while nobody has answered.
	 *
	 * @var string|null
	 */
	private ?string $outcome = null;

	/**
	 * The object reference the listener answered with.
	 *
	 * @var string|null
	 */
	private ?string $objectRef = null;

	/**
	 * Constructor.
	 *
	 * @param array<string,mixed> $message The message as the `message` schema holds it.
	 * @param string|null $detectedReference The case reference the message names, or null.
	 * @param string $messageUuid The integriq `message` object uuid.
	 * @param string $sourceId The mailbox source the message came from.
	 */
	public function __construct(
		private readonly array $message,
		private readonly ?string $detectedReference,
		private readonly string $messageUuid = '',
		private readonly string $sourceId = '',
	) {
		parent::__construct();

	}//end __construct()

	/**
	 * The message payload.
	 *
	 * @return array<string,mixed> The message.
	 */
	public function getMessage(): array {
		return $this->message;

	}//end getMessage()

	/**
	 * The case reference the message names.
	 *
	 * @return string|null The reference, or null.
	 */
	public function getDetectedReference(): ?string {
		return $this->detectedReference;

	}//end getDetectedReference()

	/**
	 * The integriq `message` object uuid.
	 *
	 * @return string The uuid.
	 */
	public function getMessageUuid(): string {
		return $this->messageUuid;

	}//end getMessageUuid()

	/**
	 * The mailbox source the message came from.
	 *
	 * @return string The source id.
	 */
	public function getSourceId(): string {
		return $this->sourceId;

	}//end getSourceId()

	/**
	 * Answer the offer.
	 *
	 * @param string $outcome One of {@see self::OUTCOMES}.
	 * @param string|null $objectRef The listener's object reference, required for linked and created.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the outcome is not one this contract knows, or when a
	 *                                  claiming outcome carries no object reference.
	 */
	public function setOutcome(string $outcome, ?string $objectRef = null): void {
		if (in_array($outcome, self::OUTCOMES, true) === false) {
			throw new InvalidArgumentException('Unknown message intake outcome: ' . $outcome);
		}

		if ($outcome !== self::OUTCOME_DECLINED && ($objectRef === null || trim($objectRef) === '')) {
			throw new InvalidArgumentException('Outcome ' . $outcome . ' requires an object reference.');
		}

		$this->outcome = $outcome;
		$this->objectRef = ($objectRef === null ? null : trim($objectRef));

	}//end setOutcome()

	/**
	 * The outcome a listener set.
	 *
	 * @return string|null The outcome, or null when nobody answered.
	 */
	public function getOutcome(): ?string {
		return $this->outcome;

	}//end getOutcome()

	/**
	 * The object reference a listener answered with.
	 *
	 * @return string|null The reference, or null.
	 */
	public function getObjectRef(): ?string {
		return $this->objectRef;

	}//end getObjectRef()

	/**
	 * Whether a listener took the message.
	 *
	 * A declined message is answered but not claimed: it still needs somewhere
	 * to go, so intake treats it exactly like silence.
	 *
	 * @return bool True when a listener linked or created.
	 */
	public function isClaimed(): bool {
		return ($this->outcome === self::OUTCOME_LINKED || $this->outcome === self::OUTCOME_CREATED);

	}//end isClaimed()

}//end class
