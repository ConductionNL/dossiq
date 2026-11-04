<?php

/**
 * Dossiq internal write marker.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * Tells a pre-save listener that the app itself is writing.
 *
 * A depth counter per object on the one shared instance, so a nested write of
 * the same object stays marked until the outermost one ends, and a write of
 * another object is not marked by it. It is set only by code in this process,
 * never from request input.
 *
 * @spec openspec/changes/only-the-advice-transition-sets-the-outcome/specs/bezwaar-advisory-committee/spec.md#requirement-only-the-advice-transition-writes-an-advice-request-into-advice-issued-or-inadmissible
 */
class InternalWriteMarker {

	/**
	 * How many internal writes are running, per object id.
	 *
	 * @var array<string, int>
	 */
	private array $depth = [];

	/**
	 * Whether an internal write of this object is running.
	 *
	 * @param string $objectId The object's id.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/only-the-advice-transition-sets-the-outcome/specs/bezwaar-advisory-committee/spec.md#requirement-only-the-advice-transition-writes-an-advice-request-into-advice-issued-or-inadmissible
	 */
	public function isInternal(string $objectId): bool {
		return ($this->depth[$objectId] ?? 0) > 0;
	}//end isInternal()

	/**
	 * Run a write of one object as an internal one.
	 *
	 * @param string   $objectId The id of the object the write saves.
	 * @param callable $write    The write.
	 *
	 * @return mixed What the write returns.
	 *
	 * @spec openspec/changes/only-the-advice-transition-sets-the-outcome/specs/bezwaar-advisory-committee/spec.md#requirement-only-the-advice-transition-writes-an-advice-request-into-advice-issued-or-inadmissible
	 */
	public function runInternal(string $objectId, callable $write): mixed {
		$this->depth[$objectId] = (($this->depth[$objectId] ?? 0) + 1);
		try {
			return $write();
		} finally {
			$this->depth[$objectId] -= 1;
		}
	}//end runInternal()
}//end class
