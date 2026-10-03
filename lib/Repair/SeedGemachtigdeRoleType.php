<?php

/**
 * Seeds the one generic Gemachtigde role type on upgrade.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\People\GemachtigdeRoleTypeSeeder;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Makes sure every case type can record an authorised representative.
 *
 * The work is in GemachtigdeRoleTypeSeeder; this step runs it and says what it
 * did. It never throws: a repair step that throws aborts the upgrade, and an
 * OpenRegister that cannot answer yet costs one upgrade rather than the whole
 * install.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-every-case-type-offers-a-gemachtigde-role-req-role-009
 */
class SeedGemachtigdeRoleType implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param GemachtigdeRoleTypeSeeder $seeder The seed itself.
	 * @param LoggerInterface           $logger Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly GemachtigdeRoleTypeSeeder $seeder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The name.
	 */
	public function getName(): string {
		return 'Seed the generic Gemachtigde role type every case type offers';
	}//end getName()

	/**
	 * Run the seed.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/gemachtigde-role-on-every-case-type/specs/roles-decisions/spec.md#requirement-every-case-type-offers-a-gemachtigde-role-req-role-009
	 */
	public function run(IOutput $output): void {
		$result = $this->seeder->seed();

		if ($result['available'] === false) {
			$output->warning(
				'OpenRegister could not be asked for the role types, so the generic Gemachtigde role '
				. 'was not seeded. This seed re-runs on the next upgrade.'
			);
			return;
		}

		if ($result['refused'] !== null) {
			$output->warning('The generic Gemachtigde role type was refused. See the log.');
			$this->logger->error(
				'Dossiq: the generic Gemachtigde role type could not be written',
				['reason' => $result['refused']]
			);
			return;
		}

		if ($result['created'] === 1) {
			$output->info('Created the generic Gemachtigde role type. Every case type now offers it.');
			return;
		}

		if ($result['adopted'] === 1) {
			$output->info(
				'The Gemachtigde role type this instance already held now carries the generic key, so '
				. 'every case type offers it. The roles pointing at it are unchanged.'
			);
			return;
		}

		$output->info('The generic Gemachtigde role type is already there. Nothing was written.');
	}//end run()
}//end class
