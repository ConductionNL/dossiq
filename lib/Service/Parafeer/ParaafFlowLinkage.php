<?php

/**
 * Answers which run a voorstel's parafering is on, and records that on a paraaf.
 *
 * Split out of ParaafResumeListener so the listener orchestrates and this
 * touches objects. The listener was one dependency over phpmd's coupling
 * threshold, and the split it wanted is the one the design wanted anyway: a
 * listener that both decides and queries is a listener that cannot be tested
 * without an object store.
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Parafeer
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Parafeer;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Reads and writes the flow linkage between a voorstel, its run and a paraaf.
 *
 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
 */
class ParaafFlowLinkage {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register/schema configuration.
	 * @param LoggerInterface $logger          The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The flow run driving this voorstel's parafering, if one does.
	 *
	 * An empty string is the ordinary answer today: the voorstel is driven by
	 * its route snapshot, and nothing should be resumed.
	 *
	 * @param string $proposalId The voorstel.
	 *
	 * @return string The run uuid, or an empty string.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function runDriving(string $proposalId): string {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return '';
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('voorstel_schema');
		if ($register === '' || $schema === '') {
			return '';
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['id' => $proposalId],
			);
		} catch (Throwable $e) {
			return '';
		}

		$first = ($rows[0] ?? null);
		if (is_array($first) === false) {
			return '';
		}

		return trim((string)($first['flowRunId'] ?? ''));
	}//end runDriving()

	/**
	 * Statuses a voorstel may hold, as the proposal schema declares them.
	 *
	 * Kept here so a flow cannot write a status the schema rejects. OpenRegister
	 * runs hard validation by default, so an undeclared value fails the save far
	 * from the node that chose it, and dossiq#1609 is what that looks like when
	 * it goes unnoticed: two of three outcomes could not be written at all.
	 *
	 * @var array<int, string>
	 */
	private const VOORSTEL_STATUSES = [
		'draft',
		'in_parafering',
		'ter_accordering',
		'geaccordeerd',
		'aangeboden',
		'besloten',
		'archived',
		'teruggestuurd',
	];

	/**
	 * Move a voorstel to a status.
	 *
	 * @param string $proposalId The voorstel.
	 * @param string $status     The status to write.
	 *
	 * @return boolean True when the voorstel was moved.
	 *
	 * @throws RuntimeException When the status is not one the schema declares.
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function setStatus(string $proposalId, string $status): bool {
		if (in_array($status, self::VOORSTEL_STATUSES, true) === false) {
			// Refused here rather than at the save, so the message names the
			// status and the node that chose it instead of surfacing as a
			// validation error three layers down.
			throw new RuntimeException('not a voorstel status: ' . $status);
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return false;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('voorstel_schema');
		if ($register === '' || $schema === '') {
			return false;
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['id' => $proposalId],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not load the voorstel to set its status',
				['voorstel' => $proposalId, 'error' => $e->getMessage()],
			);
			return false;
		}

		$voorstel = ($rows[0] ?? null);
		if (is_array($voorstel) === false) {
			return false;
		}

		try {
			$objectService->saveObject(
				object: array_merge($voorstel, ['status' => $status]),
				register: $register,
				schema: $schema,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not move the voorstel to ' . $status,
				['voorstel' => $proposalId, 'error' => $e->getMessage()],
			);
			return false;
		}

		return true;
	}//end setStatus()

	/**
	 * Record which run and node a paraaf answers.
	 *
	 * @param array<string, mixed> $paraaf  The paraaf.
	 * @param string               $runUuid The run.
	 * @param string               $nodeId  The awaiting node.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/parafering-runs-as-a-flow/specs/parafering-flow/spec.md
	 */
	public function stamp(array $paraaf, string $runUuid, string $nodeId): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('parafeeractie_schema');
		if ($register === '' || $schema === '') {
			return;
		}

		try {
			$objectService->saveObject(
				object: array_merge($paraaf, ['flowRun' => $runUuid, 'flowNode' => $nodeId]),
				register: $register,
				schema: $schema,
			);
		} catch (Throwable $e) {
			// Reported, not raised. The signal is what advances the run; losing
			// the stamp costs traceability, not correctness, and failing here
			// would leave the run suspended for a paraaf already given.
			$this->logger->warning(
				'Dossiq: could not stamp the flow linkage onto a paraaf',
				['paraaf' => ($paraaf['id'] ?? null), 'run' => $runUuid, 'error' => $e->getMessage()],
			);
		}
	}//end stamp()
}//end class
