<?php

/**
 * One scan of the satellite rows for tenant references that resolve to no Organisation.
 *
 * Built by `SatelliteOrphanScanner::reportOrphans()` and never handed out:
 * the OpenRegister services it reads with, the report so far, the schema
 * being read and its counts, and the tenant references already resolved.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

/**
 * The state of one satellite orphan scan.
 *
 * @spec openspec/specs/tenant-organisation-boundary/spec.md
 */
final class OrphanScan {
	/**
	 * The report of a scan that read nothing.
	 *
	 * @var array{scanned:int, orphans:int, templates:int, bySchema:array<string, array<string, int>>, rows:array<int, array<string, string>>}
	 */
	public const EMPTY_REPORT = [
		'scanned' => 0,
		'orphans' => 0,
		'templates' => 0,
		'bySchema' => [],
		'rows' => [],
	];

	/**
	 * The report so far.
	 *
	 * @var array{scanned:int, orphans:int, templates:int, bySchema:array<string, array<string, int>>, rows:array<int, array<string, string>>}
	 */
	public array $report = self::EMPTY_REPORT;

	/**
	 * The schema being read.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * The counts of the schema being read.
	 *
	 * @var array{scanned:int, orphans:int, templates:int}
	 */
	public array $perSchema = ['scanned' => 0, 'orphans' => 0, 'templates' => 0];

	/**
	 * Whether each tenant reference seen resolves to an Organisation.
	 *
	 * @var array<string, bool>
	 */
	public array $resolves = [];

	/**
	 * Constructor.
	 *
	 * @param object $objectService OR ObjectService.
	 * @param object $mapper        OR OrganisationMapper.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly object $mapper,
	) {
	}//end __construct()
}//end class
