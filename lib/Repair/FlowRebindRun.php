<?php

/**
 * One run of the basis flow rebind: where it reads and writes, and the flows it read.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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

namespace OCA\Dossiq\Repair;

/**
 * The store and schemas of one rebind run, and the flows read so far.
 *
 * @spec openspec/changes/woo-stages-are-guarded-by-their-flow/specs/status-transition-engine/spec.md
 */
final class FlowRebindRun {
	/**
	 * The flows read so far, by id; null for one that could not be read.
	 *
	 * @var array<string, array<string, mixed>|null>
	 */
	public array $templates = [];

	/**
	 * Constructor.
	 *
	 * @param object                $objectService OpenRegister's ObjectService.
	 * @param string                $register      The register.
	 * @param array<string, string> $schemas       The schemas by role: `caseType`, `template` and `case`.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly string $register,
		public readonly array $schemas,
	) {
	}//end __construct()
}//end class
