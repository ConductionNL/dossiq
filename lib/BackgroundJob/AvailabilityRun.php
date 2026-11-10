<?php

/**
 * Dossiq availability run
 *
 * What one run of the specialist availability refresh shares between its steps.
 *
 * @category BackgroundJob
 * @package  OCA\Dossiq\BackgroundJob
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\BackgroundJob;

/**
 * One run of the availability refresh: where it reads and writes, and when a record is stale.
 *
 * Built by {@see SpecialistBeschikbaarheidRefreshJob} only; it never leaves it.
 *
 * @spec openspec/specs/kcc-werkplek-zaaksysteem-bridge/spec.md#requirement-specialist-beschikbaarheid-cache-stays-fresh
 */
final class AvailabilityRun {

	/**
	 * The OpenRegister object service.
	 *
	 * @var object
	 */
	public object $objectService;

	/**
	 * The register.
	 *
	 * @var string
	 */
	public string $register = '';

	/**
	 * The specialist availability schema.
	 *
	 * @var string
	 */
	public string $schema = '';

	/**
	 * The moment of the run, as a Unix timestamp.
	 *
	 * @var int
	 */
	public int $now = 0;

	/**
	 * How old a lastUpdate may be before the record is stale, in seconds.
	 *
	 * @var int
	 */
	public int $staleSeconds = 0;
}//end class
