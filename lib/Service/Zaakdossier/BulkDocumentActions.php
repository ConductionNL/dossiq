<?php

/**
 * Dossiq bulk document actions.
 *
 * Doing one act to many documents at once: clearing a whole selection before
 * anything is written, and applying a metadata change document by document so
 * one refusal does not take the rest of the run with it.
 *
 * 🔴 THE CLEARANCE GATE RUNS BEFORE ANY MUTATION, NOT BESIDE IT. A bulk
 * transition that checked each document as it wrote would leave a selection
 * half moved when the fourth one is out of reach, and the caller would have no
 * way to tell which half. The metadata run is the other shape on purpose: it
 * reports per id, because a metadata change is independent per document and a
 * single refusal there is not a reason to abandon the rest.
 *
 * Split out of {@see \OCA\Dossiq\Controller\ZaakdossierController}, which was
 * over its coupling ceiling. A controller reads the request and answers with a
 * status code; deciding a selection is the work behind it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zaakdossier
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
 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\Service\ZaakdossierService;
use OCP\IUser;
use Throwable;

/**
 * One act over many informatieobjecten.
 *
 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
 */
class BulkDocumentActions {

	/**
	 * Constructor.
	 *
	 * @param InformatieobjectReader $reader      The per-object clearance gate.
	 * @param ZaakdossierService     $fileService The writer of an informatieobject.
	 */
	public function __construct(
		private readonly InformatieobjectReader $reader,
		private readonly ZaakdossierService $fileService,
	) {
	}//end __construct()

	/**
	 * Whether every listed informatieobject is readable by the user.
	 *
	 * @param IUser            $user The requesting user.
	 * @param array<int,mixed> $ids  The informatieobject UUIDs.
	 *
	 * @return bool True when all ids pass the clearance gate.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function allReadable(IUser $user, array $ids): bool {
		foreach ($ids as $id) {
			if ($this->reader->guardReadable(user: $user, infoObjectId: (string)$id) !== null) {
				return false;
			}
		}

		return true;
	}//end allReadable()

	/**
	 * Apply one metadata change to every listed informatieobject.
	 *
	 * @param IUser                $user     The requesting user.
	 * @param array<int,mixed>     $ids      The informatieobject UUIDs.
	 * @param array<string, mixed> $metadata The metadata to apply.
	 *
	 * @return array<int, array<string, mixed>> One result entry per id, in order.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	public function updateAll(IUser $user, array $ids, array $metadata): array {
		$results = [];
		foreach ($ids as $id) {
			$results[] = $this->updateOne(user: $user, id: (string)$id, metadata: $metadata);
		}

		return $results;
	}//end updateAll()

	/**
	 * Update one informatieobject's metadata inside a bulk run.
	 *
	 * @param IUser                $user     The requesting user.
	 * @param string               $id       The informatieobject UUID.
	 * @param array<string, mixed> $metadata The metadata to apply.
	 *
	 * @return array<string, mixed> The per-id result entry.
	 *
	 * @spec openspec/changes/document-zaakdossier/tasks.md#T05
	 */
	private function updateOne(IUser $user, string $id, array $metadata): array {
		if ($this->reader->guardReadable(user: $user, infoObjectId: $id) !== null) {
			return ['id' => $id, 'success' => false, 'error' => 'Insufficient clearance'];
		}

		try {
			$this->fileService->updateMetadata(infoObjectId: $id, metadata: $metadata);
			return ['id' => $id, 'success' => true];
		} catch (Throwable $e) {
			return ['id' => $id, 'success' => false, 'error' => $e->getMessage()];
		}
	}//end updateOne()

}//end class
