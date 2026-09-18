<?php

/**
 * OpenRegister ObjectsMergedEvent stub.
 *
 * Declaration-only mirror of OpenRegister's merge event contract, the same
 * discipline as the three person-link stubs beside it: `CaseMergeRegistrar`
 * registers `CaseMergedListener` against this class and is analysed without
 * the openregister runtime present, so without a stub psalm reports the
 * registration as naming a class that does not exist and the follower reads as
 * dead code.
 *
 * NOT psr-4 autoloadable: `tests/Stubs/` maps to `OCA\\Dossiq\\Tests\\`, so a
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
 * behaviour of this one: the requirement it serves is OpenRegister's merge, and
 * the dossiq side it lets the analysers see is specified in
 * openspec/changes/case-merge/specs/case-management/spec.md.
 */

declare(strict_types=1);

namespace OCA\OpenRegister\Event;

use OCP\EventDispatcher\Event;

/**
 * Two or more objects were merged into one.
 */
class ObjectsMergedEvent extends Event {

	/**
	 * Constructor.
	 *
	 * @param string            $survivorUuid     The surviving object.
	 * @param array<int,string> $mergedFromUuids  The objects merged away.
	 * @param string            $mergeOperationId The persisted `mergeOperation` row.
	 * @param bool              $isReversal       Whether this reverses a merge.
	 */
	public function __construct(
		private readonly string $survivorUuid,
		private readonly array $mergedFromUuids,
		private readonly string $mergeOperationId,
		private readonly bool $isReversal = false,
	) {
		parent::__construct();
	}//end __construct()

	/**
	 * The surviving object.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md
	 */
	public function getSurvivorUuid(): string {
		return $this->survivorUuid;
	}//end getSurvivorUuid()

	/**
	 * The objects merged away.
	 *
	 * @return array<int, string> The uuids.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md
	 */
	public function getMergedFromUuids(): array {
		return $this->mergedFromUuids;
	}//end getMergedFromUuids()

	/**
	 * The persisted merge-operation row this event belongs to.
	 *
	 * @return string The uuid.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md
	 */
	public function getMergeOperationId(): string {
		return $this->mergeOperationId;
	}//end getMergeOperationId()

	/**
	 * Whether this event reverses an earlier merge.
	 *
	 * @return bool True when it is a reversal.
	 *
	 * @spec openspec/changes/case-merge/specs/case-management/spec.md
	 */
	public function isReversal(): bool {
		return $this->isReversal;
	}//end isReversal()
}//end class
