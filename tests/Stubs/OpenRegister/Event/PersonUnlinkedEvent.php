<?php

/**
 * OpenRegister PersonUnlinkedEvent stub.
 *
 * Declaration-only mirror of OpenRegister's people-on-objects event contract
 * (`lib/Event/PersonUnlinkedEvent.php`, change `people-on-objects`), the same
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
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * A person's link on an object was removed.
 */
class PersonUnlinkedEvent extends Event {

	/**
	 * @param object $link The link, OpenRegister's ContactLink.
	 */
	public function __construct(private object $link) {
	}//end __construct()

	/**
	 * The link.
	 *
	 * @return object The link.
	 */
	public function getLink(): object {
		return $this->link;
	}//end getLink()
}//end class
