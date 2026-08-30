<?php

/**
 * Dossiq Migrate Committees To Decidiq Repair
 *
 * Migration repair step: for each local `bezwaaradviescommissie`, cause a
 * GovernanceBody to exist in the decision app and record its id back on the
 * local row. A bezwaaradviescommissie IS a governance body, and governance
 * bodies belong to the decision app; dossiq carrying a second register of who
 * sits on what is the duplication this step ends.
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\Bezwaar\CommitteeDelegationService;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Raises a GovernanceBody per local committee and records the mapping.
 *
 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
 */
class MigrateCommitteesToDecidiq implements IRepairStep {

	use SearchesObjects;

	/**
	 * Committees read per pass.
	 *
	 * @var integer
	 */
	private const BATCH_LIMIT = 500;

	/**
	 * Constructor.
	 *
	 * @param CommitteeDelegationService $delegation Raises the body in the decision app.
	 * @param SettingsService            $settings   Resolves OpenRegister and the schema slugs.
	 * @param LoggerInterface            $logger     Logger.
	 */
	public function __construct(
		private readonly CommitteeDelegationService $delegation,
		private readonly SettingsService $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name shown during the upgrade.
	 *
	 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
	 */
	public function getName(): string {
		return 'Dossiq: hold objection advisory committees as governance bodies';

	}//end getName()

	/**
	 * Migrate every committee that has not been migrated yet.
	 *
	 * @param IOutput $output The migration output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/migrate-committees-to-decidiq/specs/migrate-committees-to-decidiq/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->delegation->isAvailable() === false) {
			// The decision app is an OPTIONAL runtime dependency. An install
			// without it keeps its local committees and keeps working; this is
			// the one case where skipping is correct rather than a silent
			// no-op, so it is reported as a skip and not as a success.
			$output->info('Dossiq: the decision app is not installed; committees stay local.');
			return;
		}

		$objectService = $this->settings->getObjectService();
		if ($objectService === null) {
			$output->warning('Dossiq: OpenRegister is not available; committees were not migrated.');
			return;
		}

		$register = $this->settings->getConfigValue(key: 'register');
		$schema = $this->settings->getConfigValue(key: 'bezwaaradviescommissie_schema');
		if ($register === '' || $schema === '') {
			$output->warning('Dossiq: the committee register/schema is not configured; committees were not migrated.');
			return;
		}

		// 🔴 A repair step runs during `occ upgrade`, where there is NO session.
		// Without a system identity OpenRegister resolves the actor as Anonymous
		// and refuses every write — and $output->warning() does not fail an
		// upgrade, so the migration would do nothing while the upgrade reported
		// success. The shared trait falls back to running the operation bare
		// when runAsSystem() is absent; here that fallback is the exact silent
		// no-op the spec forbids, so this step FAILS instead.
		if (method_exists($objectService, 'runAsSystem') === false) {
			throw new RuntimeException(
				'Dossiq: OpenRegister exposes no runAsSystem(); the committee migration cannot establish an identity and refuses to run as Anonymous.'
			);
		}

		$counts = ['migrated' => 0, 'skipped' => 0, 'failed' => 0];

		$objectService->runAsSystem(
			function () use ($objectService, $register, $schema, $output, &$counts): void {
				$committees = $this->readCommittees(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					output: $output,
				);

				foreach ($committees as $committee) {
					$this->migrateOne(
						objectService: $objectService,
						register: $register,
						schema: $schema,
						committee: $committee,
						output: $output,
						counts: $counts,
					);
				}
			}
		);

		$output->info(
			sprintf(
				'Dossiq committees: %d migrated, %d already mapped, %d failed.',
				$counts['migrated'],
				$counts['skipped'],
				$counts['failed']
			)
		);

	}//end run()

	/**
	 * Read the local committees.
	 *
	 * @param object  $objectService OpenRegister's object service.
	 * @param string  $register      The register slug.
	 * @param string  $schema        The committee schema slug.
	 * @param IOutput $output        The migration output.
	 *
	 * @return array<int, array<string, mixed>> The committee rows.
	 */
	private function readCommittees(
		object $objectService,
		string $register,
		string $schema,
		IOutput $output,
	): array {
		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => self::BATCH_LIMIT],
			);
		} catch (Throwable $e) {
			$output->warning('Dossiq: could not list committees: ' . $e->getMessage());
			$this->logger->warning(
				'MigrateCommitteesToDecidiq: list failed',
				['error' => $e->getMessage()]
			);

			return [];
		}

	}//end readCommittees()

	/**
	 * Migrate one committee, or account for why it was not migrated.
	 *
	 * @param object                        $objectService OpenRegister's object service.
	 * @param string                        $register      The register slug.
	 * @param string                        $schema        The committee schema slug.
	 * @param array<string, mixed>          $committee     The committee row.
	 * @param IOutput                       $output        The migration output.
	 * @param array<string, int>            $counts        Running tallies, by reference.
	 *
	 * @return void
	 */
	private function migrateOne(
		object $objectService,
		string $register,
		string $schema,
		array $committee,
		IOutput $output,
		array &$counts,
	): void {
		$id = (string)($committee['id'] ?? ($committee['@self']['id'] ?? ''));
		if ($id === '') {
			$counts['failed']++;
			return;
		}

		if (trim((string)($committee['governanceBodyId'] ?? '')) !== '') {
			$counts['skipped']++;
			return;
		}

		try {
			$bodyId = $this->delegation->ensureGovernanceBody(committee: $committee);
		} catch (Throwable $e) {
			// One committee that cannot be raised must not abandon the rest.
			// It is counted and named, so the summary line reports a partial
			// run as partial rather than as a clean one.
			$counts['failed']++;
			$output->warning('Dossiq: could not migrate committee ' . $id . ': ' . $e->getMessage());
			$this->logger->warning(
				'MigrateCommitteesToDecidiq: committee failed',
				['committee' => $id, 'error' => $e->getMessage()]
			);

			return;
		}

		try {
			$objectService->saveObject(
				object: ($committee + ['governanceBodyId' => $bodyId]),
				register: $register,
				schema: $schema,
				uuid: $id,
			);
		} catch (Throwable $e) {
			// The body exists; only the local note failed. Counted as failed so
			// the summary is honest, and harmless to retry: the other side
			// resolves on (sourceApp, externalReference) and will match the
			// body it already has rather than minting a second.
			$counts['failed']++;
			$output->warning('Dossiq: raised a body for committee ' . $id . ' but could not record it: ' . $e->getMessage());

			return;
		}

		$counts['migrated']++;

	}//end migrateOne()

}//end class
