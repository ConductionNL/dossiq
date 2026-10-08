<?php

/**
 * Integriq DigitalPostDeliveredEvent test stub.
 *
 * Mirrors integriq's contract verbatim (constructor parameter names AND order,
 * and every getter) so DigitalPostDeliveredListener can be unit-tested without
 * the integriq app installed. The real class ships in integriq
 * (`lib/Event/DigitalPostDeliveredEvent.php`, change
 * `berichtenbox-digital-post-adapter`, PR 2062), read at `development` on
 * 2026-09-18; this stub is loaded by tests/bootstrap.php only when the real
 * class is absent.
 *
 * The name says delivered because that is the status anyone waits for, and the
 * real class says in its own docblock that it fires on every change including
 * `failed`. The stub carries `lastError` for exactly that reason: a listener
 * tested only against the happy path would pass while dropping the one status
 * a handler has to act on.
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
 * Every status change of a tracked digital post message.
 */
class DigitalPostDeliveredEvent extends Event {
	/**
	 * Constructor.
	 *
	 * @param string $messageId The tracked message id.
	 * @param string $status The status it moved to.
	 * @param string $requestedBy Who asked for the letter.
	 * @param string $previousStatus The status it moved from.
	 * @param bool $simulated Whether the binding that handled it sends nothing.
	 * @param string $lastError The provider's reason, when the status is failed.
	 */
	public function __construct(
		private readonly string $messageId,
		private readonly string $status,
		private readonly string $requestedBy = '',
		private readonly string $previousStatus = '',
		private readonly bool $simulated = false,
		private readonly string $lastError = '',
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The tracked message id.
	 *
	 * @return string Message id.
	 */
	public function getMessageId(): string {
		return $this->messageId;
	}//end getMessageId()

	/**
	 * The status it moved to.
	 *
	 * @return string Status.
	 */
	public function getStatus(): string {
		return $this->status;
	}//end getStatus()

	/**
	 * Who asked for the letter.
	 *
	 * @return string The acting user or system id.
	 */
	public function getRequestedBy(): string {
		return $this->requestedBy;
	}//end getRequestedBy()

	/**
	 * The status it moved from.
	 *
	 * @return string Previous status.
	 */
	public function getPreviousStatus(): string {
		return $this->previousStatus;
	}//end getPreviousStatus()

	/**
	 * Whether the binding that handled it sends nothing.
	 *
	 * @return bool True when the send was simulated.
	 */
	public function isSimulated(): bool {
		return $this->simulated;
	}//end isSimulated()

	/**
	 * The provider's reason, when the status is failed.
	 *
	 * @return string The reason, empty otherwise.
	 */
	public function getLastError(): string {
		return $this->lastError;
	}//end getLastError()
}//end class
