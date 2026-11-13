<?php

/**
 * Contract of SubsidieRegisterExporter
 *
 * The public surface of SubsidieRegisterExporter that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

/**
 * Contract of SubsidieRegisterExporter.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SubsidieRegisterExporterInterface {

	/**
	 * Build a complete, paginated JSON-LD feed document (REQ-SUB-006).
	 *
	 * @param array<int, array<string, mixed>> $entries The pre-built feed entries.
	 * @param int $limit Page size.
	 * @param int $offset Page offset.
	 *
	 * @return array<string, mixed> The feed document.
	 *
	 * @spec openspec/changes/archive/2026-06-13-subsidieverlening-keten/specs.md
	 */
	public function buildFeed(array $entries, int $limit = 100, int $offset = 0): array;

	/**
	 * Map one subsidy dossier into a feed entry (REQ-SUB-006).
	 *
	 * @param array<string, mixed> $request The application record.
	 * @param array<string, mixed> $regeling The regeling record.
	 * @param array<string, mixed> $decision The (latest) decision record.
	 *
	 * @return array<string, mixed> The feed entry.
	 *
	 * @spec openspec/changes/archive/2026-06-13-subsidieverlening-keten/specs.md
	 */
	public function toFeedEntry(array $request, array $regeling, array $decision): array;
}//end interface
