<?php

/**
 * The ZGW notification channels (kanalen) every install is seeded with.
 *
 * Which channels there are, whether the kanaal mapping names the register
 * and schema they are written to, and the line that reports what was
 * written and what was refused. The write itself stays in
 * {@see \OCA\Dossiq\Repair\LoadDefaultZgwMappings}, under its system identity.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Support;

use OCP\Migration\IOutput;

/**
 * The default notification channels, their readiness and their report.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
final class DefaultNotificationChannels {

	/**
	 * The default notification channel configurations.
	 *
	 * @return array<int, array{name: string, filters: list<string>}> The default kanaal data
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function defaults(): array {
		return [
			['name' => 'zaken', 'filters' => ['bronorganisatie', 'caseType', 'vertrouwelijkheidaanduiding']],
			['name' => 'documenten', 'filters' => ['bronorganisatie', 'informatieobjecttype', 'vertrouwelijkheidaanduiding']],
			['name' => 'besluiten', 'filters' => ['verantwoordelijkeOrganisatie', 'besluittype']],
			['name' => 'catalogi', 'filters' => []],
			['name' => 'autorisaties', 'filters' => []],
		];
	}//end defaults()

	/**
	 * Whether the kanaal mapping is configured with a register and schema.
	 *
	 * @param IOutput                   $output         The output interface for progress reporting
	 * @param array<string, mixed>|null $channelMapping The kanaal mapping, or null when absent
	 *
	 * @return bool True when the default channels can be written
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function mappingReady(IOutput $output, ?array $channelMapping): bool {
		if ($channelMapping === null) {
			$output->info('Kanaal mapping not configured. Skipping default channels.');
			return false;
		}

		// On a fresh install the schema settings can still be empty when this
		// step runs; a search against an empty schema context throws and would
		// abort the install. Skip by name instead.
		if ((string)($channelMapping['sourceRegister'] ?? '') === ''
			|| (string)($channelMapping['sourceSchema'] ?? '') === ''
		) {
			$output->info('Kanaal mapping has no register/schema configured yet. Skipping default channels.');
			return false;
		}

		return true;
	}//end mappingReady()

	/**
	 * Report how many default channels were created and which were refused.
	 *
	 * @param IOutput                                    $output The output interface for progress reporting
	 * @param array{created: int, refused: list<string>} $result Number of channels created and names of refused channels
	 *
	 * @return void
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function report(IOutput $output, array $result): void {
		$created = $result['created'];
		$refused = $result['refused'];
		if ($refused !== []) {
			$output->warning(
				sprintf(
					'Created %d default notification channels; %d REFUSED (%s). ZGW notifications on those channels cannot be delivered.',
					$created,
					count($refused),
					implode(', ', $refused)
				)
			);
			return;
		}

		$output->info("Created {$created} default notification channels.");
	}//end report()
}//end class
