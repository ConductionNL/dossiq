<?php

/**
 * Dossiq probe-adapter-seams repair step.
 *
 * Writes the Integrations page's Digital signing row from what this instance
 * actually has, at the one moment the app is allowed to look: `occ upgrade`.
 *
 * 🔴 WITHOUT THIS STEP THE ROW WOULD BE A SEED AND NOTHING ELSE. Every other
 * connection on that page is decided by a setting an admin saves or a probe an
 * admin runs, and both of those have a moment. Signing has neither: it is
 * decided by whether the LibreSign app is enabled, which an admin does in
 * Nextcloud's own app management and never in dossiq. A seeded row alone would
 * therefore say Simulated forever on an instance that signs perfectly well,
 * which is the same class of untruth in the other direction.
 *
 * The step must run AFTER the register import, because it updates a
 * `dossiqIntegration` row the import seeds. It is idempotent, writes only the
 * three status fields, and never throws: an instance that cannot reach
 * OpenRegister has bigger problems than a stale card, and a repair step that
 * fails takes the whole upgrade with it.
 *
 * 🔴 AND IT ELEVATES BEFORE IT WRITES. `occ` carries no session, so every
 * OpenRegister write from a repair step runs as 'Anonymous' and is REFUSED,
 * silently, because the recorder swallows its own failures and the upgrade
 * still reports success. `SeedWriteIdentityTest` is what caught this one; it
 * exists because the mistake is invisible at every other stage.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 *
 * @spec openspec/specs/admin-settings/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Repair\Support\RunsUnderSystemIdentity;
use OCA\Dossiq\Service\IntegrationStatusService;
use OCA\Dossiq\Service\SettingsService;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Throwable;

/**
 * Records what each app-backed adapter seam resolves to on this instance.
 *
 * @spec openspec/specs/admin-settings/spec.md
 */
class ProbeAdapterSeams implements IRepairStep {

	use RunsUnderSystemIdentity;

	/**
	 * Constructor.
	 *
	 * @param IntegrationStatusService $integrationStatus Writes the card.
	 * @param SettingsService $settingsService Resolves the object service to elevate through.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IntegrationStatusService $integrationStatus,
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * The step's name, as `occ upgrade` prints it.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function getName(): string {
		return 'Dossiq: record what each adapter seam resolves to';
	}//end getName()

	/**
	 * Probe the app-backed seams and write their rows.
	 *
	 * @param IOutput $output The upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/admin-settings/spec.md
	 */
	public function run(IOutput $output): void {
		$written = [];
		try {
			$this->withSystemIdentity(
				objectService: $this->settingsService->getObjectService(),
				work: function () use (&$written): void {
					$written = $this->integrationStatus->recordAdapterSeams();
				}
			);
		} catch (Throwable $e) {
			$output->info('Dossiq: could not probe the adapter seams: ' . $e->getMessage());
			return;
		}

		if ($written === []) {
			$output->info('Dossiq: no adapter seam row could be written (OpenRegister or the seed is absent).');
			return;
		}

		foreach ($written as $key => $status) {
			$output->info('Dossiq: ' . $key . ' reads ' . $status . '.');
		}
	}//end run()
}//end class
