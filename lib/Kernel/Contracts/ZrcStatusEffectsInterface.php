<?php

/**
 * Contract of ZrcStatusEffects
 *
 * The public surface of ZrcStatusEffects that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of ZrcStatusEffects.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface ZrcStatusEffectsInterface {

	/**
	 * Derive the zaak's archiving parameters when a resultaat is created (zrc-021).
	 *
	 * @param array $body The resultaat body as received.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function applyResultEffect(array $body): void;

	/**
	 * Close or reopen the zaak a new status belongs to (zrc-007a, zrc-021, zrc-007b, zrc-008).
	 *
	 * @param array $body The status body as received.
	 * @param array $objectData The stored status.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function applyStatusEffect(array $body, array $objectData): void;

	/**
	 * Whether a new status would reopen a closed zaak, which needs zaken.heropenen (zrc-008c).
	 *
	 * Only the status type's explicit flag counts here (not the highest
	 * volgnummer), as before. Fail-closed: when the answer cannot be read it
	 * is "yes", so the scope gate applies rather than being skipped.
	 *
	 * @param array $body The status body as received.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function isReopenAttempt(array $body): bool;

	/**
	 * Refuse an eindstatus while a linked document has no indicatieGebruiksrecht (zrc-007q).
	 *
	 * On the zaak's first close the indication is derived first (zrc-007b), so
	 * only documents it could not settle are refused. Any lookup failure lets
	 * the status through, as it always has.
	 *
	 * @param array $body The status body as received.
	 *
	 * @return array|null The 400 body, or null when the status may be created.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function unsetUsageRightsRefusal(array $body): ?array;
}//end interface
