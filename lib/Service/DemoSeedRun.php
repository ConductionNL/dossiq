<?php

/**
 * Dossiq Demo Seed Run
 *
 * The state one demo caseload seed carries from case to case.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/dossiq-app-scaffold/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;

/**
 * One demo caseload seed: where it writes, its clock, what it looked up once,
 * and what it created so far. It never leaves DemoCaseloadSeedDataService.
 *
 * @spec openspec/specs/dossiq-app-scaffold/spec.md
 */
final class DemoSeedRun {
	/**
	 * The register and schema ids.
	 *
	 * @var array<string, string>
	 */
	public array $ids = [];

	/**
	 * Installed case types keyed by identifier.
	 *
	 * @var array<string, array{id: string, processingDeadline: string}>
	 */
	public array $caseTypes = [];

	/**
	 * Status type ids keyed by "caseTypeId|name".
	 *
	 * @var array<string, string>
	 */
	public array $statuses = [];

	/**
	 * What was created so far.
	 *
	 * @var array{cases: integer, tasks: integer, skipped: integer}
	 */
	public array $summary = ['cases' => 0, 'tasks' => 0, 'skipped' => 0];

	/**
	 * Constructor.
	 *
	 * @param object            $objectService The OpenRegister ObjectService.
	 * @param DateTimeImmutable $now           The clock.
	 */
	public function __construct(
		public readonly object $objectService,
		public readonly DateTimeImmutable $now,
	) {
	}//end __construct()
}//end class
