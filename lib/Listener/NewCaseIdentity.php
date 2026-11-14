<?php

/**
 * The case a term is bound to: its id, its case type as the case carries it, and that case type's slug.
 *
 * @category Listener
 * @package  OCA\Dossiq\Listener
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
 * @spec openspec/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Listener;

/**
 * The case a term is bound to: its id, its case type as the case carries it, and that case type's slug.
 *
 * @spec openspec/specs/termijn-binding/spec.md
 */
final class NewCaseIdentity {

	/**
	 * Constructor.
	 *
	 * @param string $caseId      The case id.
	 * @param string $caseTypeRef The case type reference as the case carries it.
	 * @param string $caseType    The case type slug.
	 *
	 * @spec openspec/specs/termijn-binding/spec.md
	 */
	public function __construct(
		public readonly string $caseId,
		public readonly string $caseTypeRef,
		public readonly string $caseType,
	) {
	}//end __construct()
}//end class
