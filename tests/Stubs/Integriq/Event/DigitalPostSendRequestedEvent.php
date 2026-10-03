<?php

/**
 * Integriq DigitalPostSendRequestedEvent test stub.
 *
 * Mirrors integriq's ADR-041 digital post command contract verbatim
 * (constructor parameter names AND order, and every getter and setter) so
 * IntegriqAdapter can be unit-tested without the integriq app installed. The
 * real class ships in integriq (`lib/Event/DigitalPostSendRequestedEvent.php`,
 * change `berichtenbox-digital-post-adapter`, PR 2062), read at `development`
 * on 2026-09-18; this stub is loaded by tests/bootstrap.php only when the real
 * class is absent.
 *
 * The result slot is the whole contract: it carries either the tracked message
 * id or a structured refusal, never both, and is never left empty on a handled
 * event. A stub that let a handled event answer nothing would make the
 * adapter's "no tracked message is a refusal" branch untestable, which is the
 * branch that keeps a letter that went nowhere from reading as delivered.
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

use OCP\EventDispatcher\Event;

/**
 * Typed cross-app command: "send this letter on my behalf".
 *
 * @SuppressWarnings(PHPMD.ExcessiveParameterList) the contract is a flat envelope.
 */
class DigitalPostSendRequestedEvent extends Event {
	/**
	 * Whether an integriq listener handled the request.
	 *
	 * @var bool
	 */
	private bool $handled = false;

	/**
	 * The tracked message id, once handled.
	 *
	 * @var string|null
	 */
	private ?string $messageId = null;

	/**
	 * The structured refusal, when the send was refused.
	 *
	 * @var array<string,mixed>|null
	 */
	private ?array $refusal = null;

	/**
	 * Constructor.
	 *
	 * @param string $sourceApp The requesting app id, for example `dossiq`.
	 * @param string $sourceId The digital post source to send over.
	 * @param string $recipient The recipient identity, for example a BSN.
	 * @param string $subject The letter's subject.
	 * @param string $body The letter's body.
	 * @param array<int,array<string,mixed>> $attachments Attachment references.
	 * @param string $requestedBy The acting user or system id.
	 * @param string $correlationId Caller-generated id, echoed on the concluded event.
	 */
	public function __construct(
		private readonly string $sourceApp,
		private readonly string $sourceId,
		private readonly string $recipient,
		private readonly string $subject,
		private readonly string $body,
		private readonly array $attachments = [],
		private readonly string $requestedBy = '',
		private readonly string $correlationId = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The requesting app id.
	 *
	 * @return string Source app id.
	 */
	public function getSourceApp(): string {
		return $this->sourceApp;
	}//end getSourceApp()

	/**
	 * The digital post source to send over.
	 *
	 * @return string Source id.
	 */
	public function getSourceId(): string {
		return $this->sourceId;
	}//end getSourceId()

	/**
	 * The recipient identity.
	 *
	 * @return string Recipient.
	 */
	public function getRecipient(): string {
		return $this->recipient;
	}//end getRecipient()

	/**
	 * The letter's subject.
	 *
	 * @return string Subject.
	 */
	public function getSubject(): string {
		return $this->subject;
	}//end getSubject()

	/**
	 * The letter's body.
	 *
	 * @return string Body.
	 */
	public function getBody(): string {
		return $this->body;
	}//end getBody()

	/**
	 * The attachment references.
	 *
	 * @return array<int,array<string,mixed>> Attachments.
	 */
	public function getAttachments(): array {
		return $this->attachments;
	}//end getAttachments()

	/**
	 * Who asked for this letter.
	 *
	 * @return string The acting user or system id.
	 */
	public function getRequestedBy(): string {
		return $this->requestedBy;
	}//end getRequestedBy()

	/**
	 * The caller's correlation id.
	 *
	 * @return string Correlation id.
	 */
	public function getCorrelationId(): string {
		return $this->correlationId;
	}//end getCorrelationId()

	/**
	 * Mark the request as handled.
	 *
	 * @param bool $handled Whether it was handled.
	 *
	 * @return void
	 */
	public function setHandled(bool $handled): void {
		$this->handled = $handled;
	}//end setHandled()

	/**
	 * Whether an integriq listener handled the request.
	 *
	 * @return bool True when handled.
	 */
	public function isHandled(): bool {
		return $this->handled;
	}//end isHandled()

	/**
	 * Record the tracked message id.
	 *
	 * @param string $messageId The message id.
	 *
	 * @return void
	 */
	public function setMessageId(string $messageId): void {
		$this->messageId = $messageId;
	}//end setMessageId()

	/**
	 * The tracked message id, once handled.
	 *
	 * @return string|null Message id.
	 */
	public function getMessageId(): ?string {
		return $this->messageId;
	}//end getMessageId()

	/**
	 * Record a structured refusal.
	 *
	 * @param string $reason Why the send was refused.
	 * @param string $code A machine-readable code for the refusal.
	 *
	 * @return void
	 */
	public function setRefusal(string $reason, string $code = 'refused'): void {
		$this->refusal = ['code' => $code, 'reason' => $reason];
	}//end setRefusal()

	/**
	 * The structured refusal, when there was one.
	 *
	 * @return array<string,mixed>|null The refusal.
	 */
	public function getRefusal(): ?array {
		return $this->refusal;
	}//end getRefusal()
}//end class
