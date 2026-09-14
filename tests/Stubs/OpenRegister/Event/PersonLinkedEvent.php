<?php

/**
 * OpenRegister PersonLinkedEvent stub.
 *
 * Declaration-only mirror of OpenRegister's people-on-objects event contract
 * (`lib/Event/PersonLinkedEvent.php`, change `people-on-objects`), the same
 * discipline as the Integriq delivery stubs beside it: dossiq's
 * PersonLinkListener is registered against these classes and analysed without
 * the openregister runtime present, so without a stub psalm proves the
 * listener unreachable and reports live code as undefined.
 *
 * NOT psr-4 autoloadable: `tests/Stubs/` maps to `OCA\\OpenRegister\\`, so a
 * class under `tests/Stubs/OpenRegister/` resolves to no autoload path and
 * cannot collide with the real class. tests/bootstrap.php includes it only
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
 *
 * @spec exclude A declaration-only mirror of another app's event contract, not
 * behaviour of this one: the requirement it serves is OpenRegister's
 * people-on-objects, and the dossiq side it lets the analysers see is specified
 * in openspec/specs/people-on-the-case.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * A person's was linked to an object.
 */
class PersonLinkedEvent extends Event {

	/**
	 * @param object $link The link, OpenRegister's ContactLink.
	 */
	public function __construct(private object $link) {
	}//end __construct()

	/**
	 * The link.
	 *
	 * @return object The link.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-001-a-person-linked-to-a-case-shall-become-a-role-record-on-that-case
	 */
	public function getLink(): object {
		return $this->link;
	}//end getLink()
}//end class
