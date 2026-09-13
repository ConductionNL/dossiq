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

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\People\CaseRoleVocabulary;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Writes this instance's role types onto the case schema as the roles a
 * person can hold on a case.
 *
 * Runs on every upgrade rather than once behind a marker: role types are
 * data, an admin adds one whenever the process needs it, and a vocabulary
 * that ran once would go stale the first time they did.
 *
 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
 */
class SyncCaseRoleVocabulary implements IRepairStep {

	use RunsUnderSystemIdentity;

	/**
	 * @param SettingsService $settingsService OpenRegister access.
	 * @param CaseRoleVocabulary $vocabulary Writes the roles onto the case schema.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly CaseRoleVocabulary $vocabulary,
	) {
	}//end __construct()

	/**
	 * The step's name in the upgrade output.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
	 */
	public function getName(): string {
		return 'Declare this instance\'s role types as the roles a person can hold on a case';
	}//end getName()

	/**
	 * Sync the vocabulary, saying what it holds.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/people-on-the-case/spec.md#requirement-req-poc-002-the-case-schema-shall-declare-the-instances-role-types-as-its-link-vocabulary
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->info('Dossiq people: OpenRegister unavailable; the case role vocabulary was not written.');
			return;
		}

		$this->withSystemIdentity(
			objectService: $objectService,
			work: function () use ($output): void {
				$this->write(output: $output);
			}
		);
	}//end run()

	/**
	 * Write the vocabulary and report it.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 */
	private function write(IOutput $output): void {
		try {
			$count = $this->vocabulary->sync();
		} catch (Throwable $e) {
			$output->warning('Dossiq people: the case role vocabulary could not be written: ' . $e->getMessage());
			return;
		}

		if ($count < 0) {
			$output->info('Dossiq people: the case role vocabulary was not written; see the log.');
			return;
		}

		$output->info('Dossiq people: the case schema declares ' . $count . ' role(s) a person can hold.');
	}//end write()
}//end class
