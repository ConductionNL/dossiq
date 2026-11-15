<?php

/**
 * Integriq IntakeMessageRoutedEvent test stub.
 *
 * Mirrors integriq's ADR-041 channel-intake contract verbatim (constructor
 * parameter names AND order, and every getter) so
 * IntakeMessageRoutedListener can be unit-tested without the integriq app
 * installed. The real class ships in integriq
 * (`lib/Event/IntakeMessageRoutedEvent.php`, change
 * `intake-channels-beyond-mail`), read at `parity/round2` `c13a20ca`; this
 * stub is loaded by tests/bootstrap.php only when the real class is absent.
 *
 * `setCreatedRef('')` throws in the real class, and it throws here, because a
 * listener that answered the slot with an empty string would read as "created
 * nothing, successfully" and a stub that accepted it would let that through.
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
 * Typed cross-app command: "a rule says this message opens one of yours".
 */
class IntakeMessageRoutedEvent extends Event {

	/**
	 * The object reference the listener created.
	 *
	 * @var string|null
	 */
	private ?string $createdRef = null;

	/**
	 * Constructor.
	 *
	 * @param array<string, mixed> $message       The normalised message.
	 * @param string               $targetSchema  The target the rule names.
	 * @param array<string, mixed> $targetPayload The mapped field values.
	 * @param array<int, mixed>    $files         The attachments and media.
	 * @param string               $messageUuid   The integriq `intake_message` uuid.
	 * @param string               $ruleName      The rule that matched.
	 */
	public function __construct(
		private readonly array $message,
		private readonly string $targetSchema,
		private readonly array $targetPayload,
		private readonly array $files = [],
		private readonly string $messageUuid = '',
		private readonly string $ruleName = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The normalised message.
	 *
	 * @return array<string, mixed> The message.
	 */
	public function getMessage(): array {
		return $this->message;
	}//end getMessage()

	/**
	 * The target the rule names.
	 *
	 * @return string The target schema.
	 */
	public function getTargetSchema(): string {
		return $this->targetSchema;
	}//end getTargetSchema()

	/**
	 * The mapped field values.
	 *
	 * @return array<string, mixed> The payload.
	 */
	public function getTargetPayload(): array {
		return $this->targetPayload;
	}//end getTargetPayload()

	/**
	 * The attachments and media, bytes included.
	 *
	 * @return array<int, mixed> The files.
	 */
	public function getFiles(): array {
		return $this->files;
	}//end getFiles()

	/**
	 * The integriq `intake_message` uuid.
	 *
	 * @return string The uuid.
	 */
	public function getMessageUuid(): string {
		return $this->messageUuid;
	}//end getMessageUuid()

	/**
	 * The rule that matched.
	 *
	 * @return string The rule name.
	 */
	public function getRuleName(): string {
		return $this->ruleName;
	}//end getRuleName()

	/**
	 * Answer with the object you opened.
	 *
	 * @param string $objectRef The object reference.
	 *
	 * @return void
	 *
	 * @throws InvalidArgumentException When the reference is empty.
	 */
	public function setCreatedRef(string $objectRef): void {
		if (trim($objectRef) === '') {
			throw new InvalidArgumentException('A created object reference cannot be empty.');
		}

		$this->createdRef = trim($objectRef);
	}//end setCreatedRef()

	/**
	 * The object the listener opened.
	 *
	 * @return string|null The reference, or null.
	 */
	public function getCreatedRef(): ?string {
		return $this->createdRef;
	}//end getCreatedRef()
}//end class
