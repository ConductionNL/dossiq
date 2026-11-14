<?php

/**
 * The two cases of one split: the case being split and the case being split off.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Cases
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

/**
 * The two cases of one split: the case being split and the case being split off.
 *
 * @spec openspec/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
 */
final class SplitCases {

	/**
	 * Constructor.
	 *
	 * @param string $sourceId The case being split.
	 * @param string $newId    The case being split off.
	 *
	 * @spec openspec/specs/case-management/spec.md#requirement-a-split-divides-a-case-rather-than-duplicating-it-req-cm-50
	 */
	public function __construct(
		public readonly string $sourceId,
		public readonly string $newId,
	) {
	}//end __construct()
}//end class
