<?php

/**
 * OpenRegister ObjectActivatedEvent stub.
 *
 * Declaration-only mirror of the event OpenRegister sends after a draft left
 * `draft` (openregister#4566, decision 180). ReceiptMoment names the class by
 * string, so only the unit tests need it. tests/bootstrap.php includes it only
 * when the real class is absent.
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use DateTimeImmutable;
use OCA\OpenRegister\Db\ObjectEntity;
use OCP\EventDispatcher\Event;

/**
 * A draft left draft: the moment of receipt.
 */
class ObjectActivatedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param ObjectEntity      $object     The object, now `active`.
	 * @param DateTimeImmutable $receivedAt The moment it left draft.
	 */
	public function __construct(
		private readonly ObjectEntity $object,
		private readonly DateTimeImmutable $receivedAt,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The activated object.
	 *
	 * @return ObjectEntity The object.
	 */
	public function getObject(): ObjectEntity {
		return $this->object;
	}//end getObject()

	/**
	 * The moment of receipt.
	 *
	 * @return DateTimeImmutable The moment.
	 */
	public function getReceivedAt(): DateTimeImmutable {
		return $this->receivedAt;
	}//end getReceivedAt()
}//end class
