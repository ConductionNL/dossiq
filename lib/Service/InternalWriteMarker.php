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
 * A depth counter on the one shared instance, so a nested write stays marked
 * until the outermost one ends. It is set only by code in this process, never
 * from request input.
 *
 * @spec openspec/changes/only-the-advice-transition-sets-the-outcome/specs/bezwaar-advisory-committee/spec.md#requirement-only-the-advice-transition-writes-an-advice-request-into-advice-issued-or-inadmissible
 */
class InternalWriteMarker {

	/**
	 * How many internal writes are running.
	 *
	 * @var integer
	 */
	private int $depth = 0;

	/**
	 * Whether an internal write is running.
	 *
	 * @return bool
	 *
	 * @spec openspec/changes/only-the-advice-transition-sets-the-outcome/specs/bezwaar-advisory-committee/spec.md#requirement-only-the-advice-transition-writes-an-advice-request-into-advice-issued-or-inadmissible
	 */
	public function isInternal(): bool {
		return $this->depth > 0;
	}//end isInternal()

	/**
	 * Run a write as an internal one.
	 *
	 * @param callable $write The write.
	 *
	 * @return mixed What the write returns.
	 *
	 * @spec openspec/changes/only-the-advice-transition-sets-the-outcome/specs/bezwaar-advisory-committee/spec.md#requirement-only-the-advice-transition-writes-an-advice-request-into-advice-issued-or-inadmissible
	 */
	public function runInternal(callable $write): mixed {
		++$this->depth;
		try {
			return $write();
		} finally {
			--$this->depth;
		}
	}//end runInternal()
}//end class
