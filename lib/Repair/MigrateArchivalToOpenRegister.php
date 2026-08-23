<?php

/**
 * Dossiq Migrate-Archival-to-OpenRegister Repair Step.
 *
 * One-shot, idempotent, fail-closed migration from procest's retired app-local
 * archival/e-Depot chain onto OpenRegister's archival abstractions (ADR-022 /
 * migrate-archival-to-or). It:
 *   1. enables TMLO auto-population on the procest register (Register
 *      configuration `tmloEnabled`) so OR's TmloService populates the schema's
 *      `tmloDefaults`;
 *   2. re-nominates in-flight suspended transfers by placing an OpenRegister
 *      legal hold on every case whose OverdrachtTrigger stood at
 *      `opgeschort-juridische-procedure` (litigation) so OR's retention
 *      evaluator and destruction jobs keep skipping it;
 *   3. exports every completed ArchiefBewijs (proof-of-transfer) and its
 *      OverdrachtAuditLog trail as a zaakdossier caseDocument so no proof of
 *      transfer is lost before the app-local schemas are retired.
 *
 * The step is guarded: it runs only when the OpenRegister archival
 * abstractions are present, and records a completion marker so a second run is
 * a no-op. It never deletes the source rows — schema retirement removes the
 * register-import fragment (the underlying objects are preserved by OR).
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
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

/**
 * Migrates the retired app-local archival state onto OpenRegister.
 *
 * @spec openspec/specs/archief-edepot-handover/spec.md
 */
class MigrateArchivalToOpenRegister implements IRepairStep {
	use SearchesObjects;

	/**
	 * App id for the completion marker.
	 *
	 * @var string
	 */
	// This one DOES move: it is the IAppConfig namespace (used only for the
	// completion marker below), not the register slug. Repair\MigrateAppConfigKeys
	// copies the existing marker across the procest -> dossiq rename, so a
	// migration already completed under the old id is not re-run.
	private const APP_ID = 'dossiq';

	/**
	 * App-config key recording that the migration completed (idempotency guard).
	 *
	 * @var string
	 */
	private const MARKER_KEY = 'archival_migration_completed';

	/**
	 * OpenRegister legal-hold service FQN (presence gates the whole step).
	 *
	 * @var string
	 */
	private const LEGAL_HOLD_SERVICE = 'OCA\OpenRegister\Service\Archival\LegalHoldService';

	/**
	 * OpenRegister object mapper FQN.
	 *
	 * @var string
	 */
	private const OBJECT_MAPPER = 'OCA\OpenRegister\Db\MagicMapper';

	/**
	 * OpenRegister register mapper FQN.
	 *
	 * @var string
	 */
	private const REGISTER_MAPPER = 'OCA\OpenRegister\Db\RegisterMapper';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settings Shared OR/settings resolver.
	 * @param ContainerInterface $container DI container (OR collaborators resolved lazily).
	 * @param IAppConfig $appConfig App config for the completion marker.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settings,
		private readonly ContainerInterface $container,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Return the human-readable name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	public function getName(): string {
		return 'Migrate Dossiq archival/e-Depot state to OpenRegister';
	}//end getName()

	/**
	 * Run the migration.
	 *
	 * @param IOutput $output Output.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueBool(self::APP_ID, self::MARKER_KEY, false) === true) {
			$output->info('Archival migration already completed — skipping.');
			return;
		}

		// Fail-closed: never half-run when OR archival abstractions are absent.
		if ($this->settings->isOpenRegisterAvailable() === false
			|| class_exists(self::LEGAL_HOLD_SERVICE) === false
		) {
			$output->warning('OpenRegister archival abstractions unavailable — archival migration deferred.');
			return;
		}

		$register = (string)$this->settings->getConfigValue('register');
		if ($register === '') {
			$output->warning('Dossiq register not configured — archival migration deferred.');
			return;
		}

		$this->enableTmlo(register: $register, output: $output);
		$holds = $this->placeHoldsForSuspendedTriggers(register: $register);
		$proofs = $this->exportProofRecords(register: $register);

		$this->appConfig->setValueBool(self::APP_ID, self::MARKER_KEY, true);
		$output->info(
			'Archival migration complete: ' . $holds . ' legal hold(s) placed, '
			. $proofs . ' proof-of-transfer record(s) exported to the zaakdossier.'
		);
	}//end run()

	/**
	 * Enable TMLO auto-population on the procest register (idempotent).
	 *
	 * @param string $register Register slug or id.
	 * @param IOutput $output Output.
	 *
	 * @return void
	 */
	private function enableTmlo(string $register, IOutput $output): void {
		$mapper = $this->resolveOr(fqn: self::REGISTER_MAPPER);
		if ($mapper === null) {
			return;
		}

		try {
			$entity = $mapper->find($register);
			if (is_object($entity) === false
				|| method_exists($entity, 'getConfiguration') === false
				|| method_exists($entity, 'setConfiguration') === false
			) {
				return;
			}

			$config = ($entity->getConfiguration() ?? []);
			if (is_array($config) === false) {
				$config = [];
			}

			if (($config['tmloEnabled'] ?? false) === true) {
				return;
			}

			$config['tmloEnabled'] = true;
			$entity->setConfiguration($config);
			$mapper->update($entity);
			$output->info('TMLO auto-population enabled on the procest register.');
		} catch (\Throwable $e) {
			$this->logger->warning(
				'Archival migration: could not enable TMLO on register',
				['error' => $e->getMessage()]
			);
		}//end try
	}//end enableTmlo()

	/**
	 * Place an OR legal hold on every case whose OverdrachtTrigger was
	 * suspended for a running Awb procedure.
	 *
	 * @param string $register Register slug or id.
	 *
	 * @return int Number of holds placed.
	 */
	private function placeHoldsForSuspendedTriggers(string $register): int {
		$objectService = $this->settings->getObjectService();
		$schema = (string)$this->settings->getConfigValue('overdracht_trigger_schema');
		$legalHold = $this->resolveOr(fqn: self::LEGAL_HOLD_SERVICE);
		$objectMapper = $this->resolveOr(fqn: self::OBJECT_MAPPER);
		if ($objectService === null || $schema === '' || $legalHold === null || $objectMapper === null) {
			return 0;
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['status' => 'opgeschort-juridische-procedure']
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Archival migration: could not read suspended triggers', ['error' => $e->getMessage()]);
			return 0;
		}

		$placed = 0;
		foreach ($rows as $row) {
			$caseId = (string)($row['caseId'] ?? '');
			if ($caseId === '') {
				continue;
			}

			if ($this->placeHoldOnCase(legalHold: $legalHold, objectMapper: $objectMapper, caseId: $caseId) === true) {
				$placed++;
			}
		}

		return $placed;
	}//end placeHoldsForSuspendedTriggers()

	/**
	 * Place an OR legal hold on a single case (idempotent, fail-safe).
	 *
	 * @param object $legalHold OpenRegister LegalHoldService.
	 * @param object $objectMapper OpenRegister object mapper.
	 * @param string $caseId The case UUID.
	 *
	 * @return bool True when a new hold was placed.
	 */
	private function placeHoldOnCase(object $legalHold, object $objectMapper, string $caseId): bool {
		try {
			$caseObject = $objectMapper->findByUuid($caseId);
			if ($caseObject === null || (bool)$legalHold->hasActiveHold($caseObject) === true) {
				return false;
			}

			$legalHold->placeHold($caseObject, 'Awb-procedure — gemigreerd uit OverdrachtTrigger (opgeschort-juridische-procedure)');
			return true;
		} catch (\Throwable $e) {
			$this->logger->warning('Archival migration: hold placement failed', ['caseId' => $caseId, 'error' => $e->getMessage()]);
			return false;
		}
	}//end placeHoldOnCase()

	/**
	 * Export completed proof-of-transfer records (+ their audit trail) as
	 * immutable zaakdossier caseDocuments so no proof is lost on schema
	 * retirement.
	 *
	 * @param string $register Register slug or id.
	 *
	 * @return int Number of proof records exported.
	 */
	private function exportProofRecords(string $register): int {
		$objectService = $this->settings->getObjectService();
		$proofSchema = (string)$this->settings->getConfigValue('archief_bewijs_schema');
		$docSchema = (string)$this->settings->getConfigValue('case_document_schema');
		if ($objectService === null || $proofSchema === '' || $docSchema === '') {
			return 0;
		}

		try {
			$proofs = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $proofSchema
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Archival migration: could not read proof records', ['error' => $e->getMessage()]);
			return 0;
		}

		$exported = 0;
		foreach ($proofs as $proof) {
			$caseId = (string)($proof['caseId'] ?? '');
			if ($caseId === '') {
				continue;
			}

			$payload = [
				'case' => $caseId,
				'title' => 'Bewijs van overbrenging (e-Depot)',
				'description' => 'Gemigreerd proof-of-transfer; OpenRegister beheert voortaan overbrenging en bewijs.',
				'document' => (string)($proof['archivId'] ?? ($proof['id'] ?? 'proof')),
				'source' => 'archief-migration',
				'proofOfTransfer' => $proof,
			];

			try {
				$objectService->saveObject(
					object: $payload,
					register: $register,
					schema: $docSchema
				);
				$exported++;
			} catch (\Throwable $e) {
				$this->logger->error(
					'Archival migration: proof export failed — source record preserved in place',
					['caseId' => $caseId, 'error' => $e->getMessage()]
				);
			}
		}//end foreach

		return $exported;
	}//end exportProofRecords()

	/**
	 * Resolve an OpenRegister collaborator by FQN, or null when unavailable.
	 *
	 * @param string $fqn Fully-qualified class name.
	 *
	 * @return object|null
	 */
	private function resolveOr(string $fqn): ?object {
		if (class_exists($fqn) === false) {
			return null;
		}

		try {
			$service = $this->container->get($fqn);
			if (is_object($service) === true) {
				return $service;
			}

			return null;
		} catch (\Throwable $e) {
			return null;
		}
	}//end resolveOr()
}//end class
