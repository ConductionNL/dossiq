<?php

/**
 * Dossiq proof-of-transfer export.
 *
 * Writes every completed proof-of-transfer record of the retired archival
 * chain as an immutable caseDocument on its case, so no proof is lost when the
 * app-local schemas are retired. A record that cannot be written is logged and
 * left where it is. Run by `MigrateArchivalToOpenRegister`.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/archief-edepot-handover/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectFindTarget;
use OCA\OpenRegister\Kernel\Contracts\Value\ObjectSaveOptions;
use Psr\Log\LoggerInterface;

/**
 * Exports the proof-of-transfer records as case documents.
 *
 * @spec openspec/specs/archief-edepot-handover/spec.md
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class ProofOfTransferExport {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settings Shared OR/settings resolver.
	 * @param LoggerInterface          $logger   Logger.
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settings,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Export completed proof-of-transfer records (+ their audit trail) as
	 * immutable zaakdossier caseDocuments so no proof is lost on schema
	 * retirement.
	 *
	 * @param string $register Register slug or id.
	 *
	 * @return int Number of proof records exported.
	 *
	 * @spec openspec/specs/archief-edepot-handover/spec.md
	 */
	public function export(string $register): int {
		$objectService = $this->settings->getObjectService();
		$proofSchema = (string)$this->settings->getConfigValue('archief_bewijs_schema');
		$docSchema = (string)$this->settings->getConfigValue('case_document_schema');
		if ($objectService === null || $proofSchema === '' || $docSchema === '') {
			return 0;
		}

		$proofs = $this->readProofs(objectService: $objectService, where: ['register' => $register, 'schema' => $proofSchema]);
		if ($proofs === null) {
			return 0;
		}

		return $this->exportProofs(
			target: new ProofDocumentTarget(objectService: $objectService, register: $register, docSchema: $docSchema),
			proofs: $proofs
		);
	}//end export()

	/**
	 * Read the proof-of-transfer records.
	 *
	 * @param object                                 $objectService OpenRegister's object service.
	 * @param array{register: string, schema: string} $where         Register slug or id, and the proof schema.
	 *
	 * @return array<int, array<string, mixed>>|null The records, or null when they could not be read.
	 */
	private function readProofs(object $objectService, array $where): ?array {
		try {
			$proofs = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $where['register'],
				schema: $where['schema']
			);
		} catch (\Throwable $e) {
			$this->logger->warning('Archival migration: could not read proof records', ['error' => $e->getMessage()]);
			return null;
		}

		return $proofs;
	}//end readProofs()

	/**
	 * Export every proof record as a caseDocument.
	 *
	 * @param ProofDocumentTarget              $target The object service, register and case document schema.
	 * @param array<int, array<string, mixed>> $proofs The proof records.
	 *
	 * @return int Number of proof records exported.
	 */
	private function exportProofs(ProofDocumentTarget $target, array $proofs): int {
		$exported = 0;
		foreach ($proofs as $proof) {
			$exported += $this->exportProof(target: $target, proof: $proof);
		}

		return $exported;
	}//end exportProofs()

	/**
	 * Export one proof record as a caseDocument.
	 *
	 * @param ProofDocumentTarget  $target The object service, register and case document schema.
	 * @param array<string, mixed> $proof  The proof record.
	 *
	 * @return int 1 when exported, 0 otherwise.
	 */
	private function exportProof(ProofDocumentTarget $target, array $proof): int {
		$caseId = (string)($proof['caseId'] ?? '');
		if ($caseId === '') {
			return 0;
		}

		$payload = $this->proofDocument(caseId: $caseId, proof: $proof);

		return $this->saveProofDocument(target: $target, payload: $payload);
	}//end exportProof()

	/**
	 * The caseDocument a proof record becomes.
	 *
	 * @param string               $caseId The case the proof belongs to.
	 * @param array<string, mixed> $proof  The proof record.
	 *
	 * @return array<string, mixed> The caseDocument payload.
	 */
	private function proofDocument(string $caseId, array $proof): array {
		return [
			'case' => $caseId,
			'title' => 'Bewijs van overbrenging (e-Depot)',
			'description' => 'Gemigreerd proof-of-transfer; OpenRegister beheert voortaan overbrenging en bewijs.',
			'document' => (string)($proof['archivId'] ?? ($proof['id'] ?? 'proof')),
			'source' => 'archief-migration',
			'proofOfTransfer' => $proof,
		];
	}//end proofDocument()

	/**
	 * Save a proof's caseDocument, logging a failure and leaving the source in place.
	 *
	 * @param ProofDocumentTarget  $target  The object service, register and case document schema.
	 * @param array<string, mixed> $payload The caseDocument payload, with its `case`.
	 *
	 * @return int 1 when exported, 0 otherwise.
	 */
	private function saveProofDocument(ProofDocumentTarget $target, array $payload): int {
		try {
			$target->objectService->saveObject(
				object: $payload,
				options: new ObjectSaveOptions(target: new ObjectFindTarget(register: $target->register, schema: $target->docSchema))
			);
			return 1;
		} catch (\Throwable $e) {
			$this->logger->error(
				'Archival migration: proof export failed — source record preserved in place',
				['caseId' => $payload['case'], 'error' => $e->getMessage()]
			);
			return 0;
		}
	}//end saveProofDocument()

}//end class
