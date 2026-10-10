<?php

/**
 * OpenRegister OrganisationUpdatedEvent stub.
 *
 * Declaration-only mirror of OpenRegister's event, dispatched by
 * `OrganisationMapper::update()` with the organisation after and before the
 * write. `OrganisationStatusChangeListener` listens to it and is analysed and tested
 * without the openregister runtime present.
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
 * in openspec/changes/tenancy-onto-openregister-organisation/specs/tenant-organisation-boundary/spec.md.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCA\OpenRegister\Db\Organisation;
use OCP\EventDispatcher\Event;

/**
 * An organisation was updated.
 */
class OrganisationUpdatedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param Organisation $newOrganisation The organisation after the update.
	 * @param Organisation $oldOrganisation The organisation before the update.
	 */
	public function __construct(
		private readonly Organisation $newOrganisation,
		private readonly Organisation $oldOrganisation,
	) {
		parent::__construct();
	}

	/**
	 * The organisation after the update.
	 *
	 * @return Organisation The organisation.
	 */
	public function getOrganisation(): Organisation {
		return $this->newOrganisation;
	}

	/**
	 * The organisation after the update.
	 *
	 * @return Organisation The organisation.
	 */
	public function getNewOrganisation(): Organisation {
		return $this->newOrganisation;
	}

	/**
	 * The organisation before the update.
	 *
	 * @return Organisation The organisation.
	 */
	public function getOldOrganisation(): Organisation {
		return $this->oldOrganisation;
	}
}
