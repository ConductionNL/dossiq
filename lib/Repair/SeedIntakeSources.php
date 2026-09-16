<?php

/**
 * Dossiq intake-source seed repair step.
 *
 * Contributes the channels dossiq handles to OpenRegister's `intake-sources`
 * register: mail, the portal, the API, the contact centre and the DSO. The
 * register and its `intake-source` schema belong to OpenRegister, which seeds
 * them itself (SeedIntakeSourceRegister); this step only adds the rows.
 *
 * UPSERT BY SLUG, AND NEVER OVER THE SWITCH. An existing source has its title
 * and description refreshed on every upgrade so the wording follows the app,
 * and its `enabled`, `connection`, `location`, `state` and `settings` are left
 * exactly as they are. Those five are what an administrator set, and an
 * upgrade that switched a channel back on would start polling a mailbox nobody
 * asked it to poll.
 *
 * EVERY SOURCE SHIPS DISABLED for the same reason. `enabled: false` means stop
 * polling and keep listing, so a channel that is off is still a channel you can
 * see and switch on, which is the whole point of holding them as objects
 * instead of as a hardcoded list.
 *
 * SKIPS RATHER THAN THROWS on an OpenRegister that predates the register. A
 * repair step that throws aborts the upgrade; the seed re-runs on the next one.
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
 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\Intake\IntakeSourceSeeder;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;

/**
 * Seeds dossiq's intake channels into OpenRegister's intake-sources register.
 *
 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
 */
class SeedIntakeSources implements IRepairStep {

	/**
	 * Constructor.
	 *
	 * @param SettingsService     $settingsService OpenRegister availability check.
	 * @param IntakeSourceSeeder  $seeder          The upsert itself.
	 * @param LoggerInterface     $logger          Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IntakeSourceSeeder $seeder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
	 */
	public function getName(): string {
		return 'Seed dossiq intake channels into the OpenRegister intake-sources register';
	}//end getName()

	/**
	 * Seed the channels.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/case-objects-hinge-on-the-object/specs/case-management/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->settingsService->isOpenRegisterAvailable() === false) {
			$output->warning('OpenRegister is not installed or enabled. Skipping the intake-source seed.');
			return;
		}

		$result = $this->seeder->seed();

		if ($result['available'] === false) {
			// The register belongs to OpenRegister and is seeded by its own
			// repair step. A deployment where that step has not run yet is not
			// an error here: say so and let the next upgrade seed the rows.
			$output->warning(
				'The OpenRegister intake-sources register is not present yet; no intake channel was seeded. '
				. 'It is created by OpenRegister\'s own repair step and this seed re-runs on the next upgrade.'
			);
			return;
		}

		if ($result['refused'] !== []) {
			$output->warning(
				sprintf(
					'Intake channels: %d created, %d refreshed, %d REFUSED (%s). See the log for each refusal.',
					$result['created'],
					$result['updated'],
					count($result['refused']),
					implode(', ', $result['refused'])
				)
			);
			$this->logger->warning(
				'Dossiq: intake-source seed refused rows',
				['refused' => $result['refused']]
			);
			return;
		}

		$output->info(
			sprintf(
				'Intake channels seeded: %d created, %d refreshed.',
				$result['created'],
				$result['updated']
			)
		);
	}//end run()
}//end class
