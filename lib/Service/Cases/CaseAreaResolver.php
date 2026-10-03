<?php

/**
 * Which wijk and which buurt a case's address falls in.
 *
 * 🔑 RESOLVED ONCE, WHEN THE ADDRESS IS SET, AND HELD ON THE CASE (D-5). The
 * value is then local: routing reads it without a network call, a list can
 * filter on it, and a boundary service being down cannot change where a case
 * routes.
 *
 * 🔑 THE ANSWER CARRIES ITS SOURCE AND ITS DATE (D-7). Boundaries change, and
 * a municipality may use its own division instead of the national one. A wijk
 * on a case from last year can only be explained beside the set it was read
 * from and the day it was read.
 *
 * 🔴 WHAT THIS READS TODAY IS PDOK'S LOCATIESERVER, THROUGH `PdokService`, AND
 * THAT IS THE PROPOSAL'S OWN INTERIM. The outbound connection belongs in
 * integriq's registry as `pdok-geo-boundaries` (ADR-019); until it exists the
 * boundaries come back on the address lookup, which already carries `wijknaam`
 * and `buurtnaam` for an adres document. `areaSource` names which of the two
 * answered, so the day the connection lands, every case says which set placed
 * it.
 *
 * 🔴 AN ADDRESS THAT PLACES IN NOTHING STILL ANSWERS. A null here would be
 * indistinguishable from an address never looked up, and the case type's
 * fallback exists precisely so that case can be routed anyway (D-6).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

use OCA\Dossiq\Service\PdokService;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * Resolves a case's area from an address, through the boundary set in force.
 *
 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md
 */
class CaseAreaResolver {
	/**
	 * The boundary set this app reads until integriq publishes the connection.
	 *
	 * @var string
	 */
	public const SOURCE_PDOK_LOCATIESERVER = 'pdok-locatieserver';

	/**
	 * Constructor.
	 *
	 * @param PdokService $pdok Resolves an address, and with it its wijk and buurt.
	 * @param ITimeFactory $time The clock, so a test can say when the read happened.
	 */
	public function __construct(
		private readonly PdokService $pdok,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The area projection for one BAG address id.
	 *
	 * @param string $addressId The PDOK/BAG id of the case's address.
	 *
	 * @return array{district: string, neighbourhood: string, areaSource: string, areaResolvedAt: string, areaFallbackUsed: bool}
	 *         The projection, always answered.
	 *
	 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md#requirement-the-case-holds-the-area-it-is-in-and-routing-reads-it-req-rtp-04
	 */
	public function forAddressId(string $addressId): array {
		if ($addressId === '') {
			return $this->unplaced();
		}

		$doc = $this->pdok->lookupAddress(id: $addressId);
		if (is_array($doc) === false) {
			// The lookup failed or the id is unknown. Unplaced, not wrong: a
			// guessed wijk routes a case to a team that has never seen it.
			return $this->unplaced();
		}

		return $this->fromDocument(doc: $doc);
	}//end forAddressId()

	/**
	 * The area projection for an address document already in hand.
	 *
	 * Separated so the caller that has just looked an address up does not look
	 * it up twice, and so the mapping can be tested without a network at all.
	 *
	 * @param array<string, mixed> $doc A PDOK locatieserver document.
	 *
	 * @return array{district: string, neighbourhood: string, areaSource: string, areaResolvedAt: string, areaFallbackUsed: bool}
	 *         The projection.
	 *
	 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md#requirement-the-case-holds-the-area-it-is-in-and-routing-reads-it-req-rtp-04
	 */
	public function fromDocument(array $doc): array {
		$district = trim((string)($doc['wijknaam'] ?? ''));
		$neighbourhood = trim((string)($doc['buurtnaam'] ?? ''));

		if ($district === '' && $neighbourhood === '') {
			return $this->unplaced();
		}

		return [
			'district' => $district,
			'neighbourhood' => $neighbourhood,
			'areaSource' => self::SOURCE_PDOK_LOCATIESERVER,
			'areaResolvedAt' => $this->now(),
			'areaFallbackUsed' => false,
		];
	}//end fromDocument()

	/**
	 * Whether a case's held area still needs resolving for this address.
	 *
	 * The address changing is the whole trigger: re-resolving on every write
	 * would ask PDOK once per note added to a case.
	 *
	 * @param array<string, mixed> $case The stored case.
	 * @param string $addressId The address id now on it.
	 *
	 * @return bool True when the area should be resolved again.
	 *
	 * @spec openspec/changes/routing-by-weight-position-and-area/specs/role-based-step-routing/spec.md#requirement-the-case-holds-the-area-it-is-in-and-routing-reads-it-req-rtp-04
	 */
	public function needsResolving(array $case, string $addressId): bool {
		if ($addressId === '') {
			return false;
		}

		if (trim((string)($case['areaResolvedAt'] ?? '')) === '') {
			return true;
		}

		return (trim((string)($case['areaAddressId'] ?? '')) !== $addressId);
	}//end needsResolving()

	/**
	 * The projection for an address that placed in nothing.
	 *
	 * It is still stamped: a case that was looked at and could not be placed
	 * is a different fact from a case nobody has looked at, and only the
	 * timestamp tells them apart.
	 *
	 * @return array{district: string, neighbourhood: string, areaSource: string, areaResolvedAt: string, areaFallbackUsed: bool} The projection.
	 */
	private function unplaced(): array {
		return [
			'district' => '',
			'neighbourhood' => '',
			'areaSource' => self::SOURCE_PDOK_LOCATIESERVER,
			'areaResolvedAt' => $this->now(),
			'areaFallbackUsed' => true,
		];
	}//end unplaced()

	/**
	 * Now, as an ISO 8601 string.
	 *
	 * @return string The moment.
	 */
	private function now(): string {
		return $this->time->getDateTime()->format('c');
	}//end now()
}//end class
