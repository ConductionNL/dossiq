<?php

/**
 * Dossiq repair step: backfill `bacAdviceRequest.bezwaar`.
 *
 * `bezwaar` is the schema-declared (and REQUIRED) property naming the objection
 * an advice request belongs to. `objectionProceeding` is the schema it $refs.
 * AdvisoryCommitteeService wrote the latter into the object, so every advice
 * request created before the fix omits a required property and carries an
 * undeclared one instead.
 *
 * The visible cost was on BezwaarDetail: its advice-request stats-blocks and
 * object-list filter on `bezwaar: @objectId`, so a bezwaar rendered NO advice
 * requests no matter how many it had. Nothing errored — an empty list is what a
 * bezwaar without advice requests looks like too.
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copy the legacy objection key onto the schema-declared one.
 *
 * @spec openspec/changes/page-topology-cleanup/tasks.md
 */
class BackfillAdviceRequestObjection implements IRepairStep {
	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister.
	 * @param IAppConfig $appConfig App configuration.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/page-topology-cleanup/tasks.md
	 */
	public function getName(): string {
		return 'Backfill bacAdviceRequest.bezwaar from the legacy objectionProceeding key';
	}//end getName()

	/**
	 * Run the backfill.
	 *
	 * Non-fatal by construction: an upgrade must not fail because a projection
	 * could not complete, and the legacy key is left in place so a later run can
	 * repeat the work.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/page-topology-cleanup/tasks.md
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			$output->info('OpenRegister unavailable — skipping advice-request backfill.');
			return;
		}

		$register = $this->appConfig->getValueString(Application::APP_ID, 'register', '');
		$schema = $this->appConfig->getValueString(Application::APP_ID, 'bac_advice_request_schema', '');
		if ($register === '' || $schema === '') {
			$output->info('Advice-request schema not configured — skipping backfill.');
			return;
		}

		// A BLOCK closure, not `fn () => ...`. An arrow function implicitly
		// RETURNS its expression, and returning the result of a void method is a
		// fatal — which is exactly what the short form did here.
		$objectService->runAsSystem(
			function () use ($objectService, $register, $schema, $output): void {
				$this->backfill(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					output: $output,
				);
			}
		);
	}//end run()

	/**
	 * Walk every advice request and repair the ones missing `bezwaar`.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 */
	private function backfill(object $objectService, string $register, string $schema, IOutput $output): void {
		$rows = $objectService->findAll(['filters' => ['register' => $register, 'schema' => $schema]]);
		if (is_array($rows) === false) {
			$output->info('Advice-request backfill: nothing to read.');
			return;
		}

		$tally = ['repaired' => 0, 'skipped' => 0, 'failed' => 0];

		foreach ($rows as $row) {
			if (is_object($row) === true && method_exists($row, 'jsonSerialize') === true) {
				$row = $row->jsonSerialize();
			}

			$outcome = $this->repairRow(
				row: (array)$row,
				objectService: $objectService,
				register: $register,
				schema: $schema,
			);
			$tally[$outcome] = ($tally[$outcome] + 1);
		}

		$output->info(
			'Advice-request backfill: ' . $tally['repaired'] . ' repaired, '
			. $tally['skipped'] . ' already correct, ' . $tally['failed'] . ' failed.'
		);
	}//end backfill()

	/**
	 * Repair one row, returning which tally it belongs to.
	 *
	 * @param array<string, mixed> $row The stored advice request.
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register The register id.
	 * @param string $schema The schema id.
	 *
	 * @return string One of repaired|skipped|failed.
	 */
	private function repairRow(array $row, object $objectService, string $register, string $schema): string {
		if ((string)($row['bezwaar'] ?? '') !== '') {
			return 'skipped';
		}

		// Read WITHOUT a fallback: a row with neither key cannot be repaired, and
		// defaulting to '' would write an empty required property that looks
		// repaired while pointing at nothing.
		$legacy = (string)($row['objectionProceeding'] ?? '');
		$uuid = (string)($row['id'] ?? ($row['uuid'] ?? ''));
		if ($legacy === '' || $uuid === '') {
			return 'skipped';
		}

		try {
			$objectService->saveObject(
				object: ['bezwaar' => $legacy],
				register: $register,
				schema: $schema,
				uuid: $uuid,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: advice-request backfill failed for one row',
				['app' => Application::APP_ID, 'uuid' => $uuid, 'exception' => $e->getMessage()]
			);
			return 'failed';
		}

		return 'repaired';
	}//end repairRow()
}//end class
