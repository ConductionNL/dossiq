<?php

/**
 * The shipped case-flow seed file.
 *
 * Read by `CaseFlowSeedDataRepairStep` before a run: where the file is, and
 * what a run reports when it is missing or not JSON.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCP\Migration\IOutput;

/**
 * Reads and decodes the shipped seed. A plain helper: the repair step builds it.
 *
 * @spec openspec/specs/case-flow-human-steps/spec.md
 */
final class CaseFlowSeedFile {

	/**
	 * The shipped seed.
	 *
	 * @var string
	 */
	private const SEED_PATH = __DIR__ . '/../Settings/case_flow_seed_data.json';

	/**
	 * Read and decode the seed file.
	 *
	 * @param IOutput $output Output sink.
	 *
	 * @return array<string, mixed>|null The seed, or null when unreadable.
	 *
	 * @spec openspec/specs/case-flow-human-steps/spec.md
	 */
	public function load(IOutput $output): ?array {
		if (is_readable(self::SEED_PATH) === false) {
			$output->warning('Case-flow seed file not found at ' . self::SEED_PATH);
			return null;
		}

		$decoded = json_decode((string)file_get_contents(self::SEED_PATH), true);
		if (is_array($decoded) === false) {
			$output->warning('Case-flow seed file is not valid JSON.');
			return null;
		}

		return $decoded;
	}//end load()
}//end class
