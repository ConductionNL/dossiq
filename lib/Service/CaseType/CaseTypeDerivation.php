<?php

/**
 * Dossiq Case Type Derivation
 *
 * One derivation in progress: where the source case type lives, its id, and whether the result is a version or a duplicate.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseType
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
 * @spec openspec/changes/archive/2026-07-13-zaaktype-copy/tasks.md#T01
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseType;

/**
 * The source of one duplicate or version.
 *
 * @spec openspec/changes/archive/2026-07-13-zaaktype-copy/tasks.md#T01
 */
final class CaseTypeDerivation {

	/**
	 * Constructor.
	 *
	 * @param CaseTypeCopyTarget $target    Where the case type lives.
	 * @param string             $sourceId  The source case type's id.
	 * @param boolean            $asVersion True for the next version, false for a duplicate.
	 */
	public function __construct(
		public readonly CaseTypeCopyTarget $target,
		public readonly string $sourceId,
		public readonly bool $asVersion,
	) {
	}//end __construct()
}//end class
