<?php

/**
 * Stand-in for OpenRegister's PortalSubject when OpenRegister is absent.
 *
 * Mirrors the one member dossiq uses: the constructor's `subjectRef` and the
 * `party:` reference built from it.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Inspection
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

namespace OCA\OpenRegister\Service\Portal;

/**
 * The verified portal subject.
 */
final class PortalSubject {
	/**
	 * Constructor.
	 *
	 * @param string $subjectRef The subject reference.
	 */
	public function __construct(public readonly string $subjectRef) {
	}//end __construct()

	/**
	 * The party reference.
	 *
	 * @return string `party:<subjectRef>`.
	 */
	public function partyReference(): string {
		return 'party:' . $this->subjectRef;
	}//end partyReference()
}//end class
