<?php

/**
 * Dossiq RebindImpactRun.
 *
 * One impact while CaseRebindImpact computes it: both case types' definitions
 * and the remaps, and, as the answers are walked, which target fields are
 * taken, what was ported, dropped and set aside for a remap.
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
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Cases;

/**
 * The state of one impact, built and read by CaseRebindImpact only.
 *
 * @spec openspec/specs/zaaktype-versioning/spec.md
 */
final class RebindImpactRun {
	/**
	 * Target fields that already receive an answer, keyed by comparable name.
	 *
	 * @var array<string, bool>
	 */
	public array $taken = [];

	/**
	 * The ported rows.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $ported = [];

	/**
	 * The dropped rows.
	 *
	 * @var list<array<string, mixed>>
	 */
	public array $dropped = [];

	/**
	 * Remapped answers, set aside by comparable source name until their remap is made.
	 *
	 * @var array<string, RebindAnswer>
	 */
	public array $deferred = [];

	/**
	 * Constructor.
	 *
	 * @param array<string, array<string, mixed>> $sourceDefs The source definitions, keyed by comparable name.
	 * @param array<string, array<string, mixed>> $targetDefs The target definitions, keyed by comparable name.
	 * @param array<string, string>               $remap      Remaps, keyed by comparable source name.
	 *
	 * @return void
	 */
	public function __construct(
		public readonly array $sourceDefs,
		public readonly array $targetDefs,
		public readonly array $remap,
	) {
	}//end __construct()
}//end class
