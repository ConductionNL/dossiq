<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Command\Backfill;

use Symfony\Component\Console\Output\OutputInterface;

/**
 * One Awb proceeding scan: what it reads from, what it reports to, and what it found so far.
 *
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */
final class AwbScanRun {

	/**
	 * OpenRegister object service.
	 *
	 * @var object
	 */
	public object $objectService;

	/**
	 * Whether soft-deleted proceedings are in scope.
	 *
	 * @var bool
	 */
	public bool $includeDeleted;

	/**
	 * Where the scan reports its counts.
	 *
	 * @var OutputInterface
	 */
	public OutputInterface $output;

	/**
	 * The proceeding UUIDs that carry a terminal decision.
	 *
	 * @var array<int, string>
	 */
	public array $closed = [];

	/**
	 * The opening schema being scanned.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * Candidate cases: per case id, the opening schemas seen and the open proceedings counted.
	 *
	 * @var array<string, array{schemas: array<int, string>, count: int}>
	 */
	public array $candidates = [];
}//end class
