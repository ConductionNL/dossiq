<?php

/**
 * Procest DossierZipExporter.
 *
 * ZIP export collaborator for the ZGW DRC case dossier. Split out of
 * ZaakdossierController so that controller keeps only endpoint shape: gathering
 * the case's documents, narrowing them to an optional selected subset, choosing
 * the archive layout, and building the archive through a temporary file that is
 * always cleaned up all live here (ADR-022).
 *
 * The two failure modes stay distinguishable for the caller: collectDocuments()
 * raises RuntimeException when the backing register is unreachable (a 503),
 * while buildZipData() raises Throwable when the archive itself cannot be
 * written (a 500).
 *
 * @category Service
 * @package  OCA\Procest\Service\Zaakdossier
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Zaakdossier;

use OCA\Procest\Service\ZaakdossierService;
use OCA\Procest\Service\ZipManifestBuilder;
use OCP\IUser;
use Psr\Log\LoggerInterface;

/**
 * Builds clearance-filtered dossier ZIP exports.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
 */
class DossierZipExporter {
	/**
	 * Constructor.
	 *
	 * @param ZaakdossierService $dossierService The dossier orchestrator.
	 * @param ZipManifestBuilder $zipBuilder The ZIP export builder.
	 * @param LoggerInterface $logger The logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly ZaakdossierService $dossierService,
		private readonly ZipManifestBuilder $zipBuilder,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Collect the case's documents, optionally narrowed to selected ids.
	 *
	 * @param string $caseId The case UUID.
	 * @param array<int,mixed> $selectedIds Optional subset of informatieobject ids.
	 *
	 * @return array<int, array<string, mixed>> The documents to export.
	 *
	 * @throws \RuntimeException When the dossier cannot be read.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function collectDocuments(string $caseId, array $selectedIds): array {
		$dossier = $this->dossierService->getDossierForCase(caseId: $caseId);
		$documents = ($dossier['informatieobjecten'] ?? []);

		if (empty($selectedIds) === true) {
			return $documents;
		}

		$wanted = array_map('strval', $selectedIds);

		return array_values(
			array_filter(
				$documents,
				static fn (array $doc) => in_array((string)($doc['id'] ?? ''), $wanted, true),
			)
		);
	}//end collectDocuments()

	/**
	 * Build the ZIP archive and return its bytes.
	 *
	 * @param IUser $user The requesting user (clearance filtering).
	 * @param array<int, array<string, mixed>> $documents The documents to include.
	 * @param bool $flatLayout True for a flat archive, false for per-type subfolders.
	 *
	 * @return string The archive bytes.
	 *
	 * @throws \Throwable When the archive cannot be written or read back.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function buildZipData(IUser $user, array $documents, bool $flatLayout): string {
		$layout = ZipManifestBuilder::LAYOUT_PER_TYPE;
		if ($flatLayout === true) {
			$layout = ZipManifestBuilder::LAYOUT_FLAT;
		}

		$tmpPath = (string)tempnam(sys_get_temp_dir(), 'procest-dossier-');
		try {
			$this->zipBuilder->buildZip(
				targetPath: $tmpPath,
				user: $user,
				documents: $documents,
				layout: $layout,
			);

			return (string)file_get_contents($tmpPath);
		} finally {
			// A temp file we cannot remove is a real (if minor) problem — say
			// so rather than hiding the failure behind an `@`.
			if (is_file($tmpPath) === true && unlink($tmpPath) === false) {
				$this->logger->warning(
					'Procest dossier: temporary ZIP could not be removed',
					['path' => $tmpPath]
				);
			}
		}//end try
	}//end buildZipData()
}//end class
