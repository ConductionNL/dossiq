<?php

/**
 * The redacted snapshot record a federated case share carries.
 *
 * Split out of {@see \OCA\Dossiq\Service\Sharing\FederatedCaseShareService},
 * which keeps the reads, the save and the mint. This class reads and writes
 * nothing: it refuses a requested field outside the federation allow-list,
 * and from the loaded case and the request it takes the allow-listed fields
 * the case actually carries and the documents already attached to it,
 * refusing a document that is not. It also reads the case a stored
 * snapshot belongs to, marks a snapshot revoked, and reads a saved one back.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Sharing
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
 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Sharing;

use DateTime;
use OCA\Dossiq\Service\CaseSharingService;

/**
 * Builds a federated share's snapshot record.
 *
 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
 * @spec openspec/changes/method-decomposition/tasks.md
 */
final class FederatedShareSnapshot {

	/**
	 * The refusal for requested fields that may not cross a federation boundary, or null when all may.
	 *
	 * @param array<string> $sharedFields Requested case field names.
	 *
	 * @return array{error: string}|null The refusal naming the fields, or null.
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function fieldRefusal(array $sharedFields): ?array {
		$invalidFields = array_diff($sharedFields, CaseSharingService::FEDERATION_ALLOWED_FIELDS);
		if (count($invalidFields) > 0) {
			return ['error' => 'Field(s) not shareable across a federation boundary: ' . implode(', ', $invalidFields)];
		}

		return null;
	}//end fieldRefusal()

	/**
	 * The snapshot record for a share of the loaded case, or an error array.
	 *
	 * @param FederatedShareRequest $request  What the share was asked to carry.
	 * @param array<string, mixed>  $caseData The loaded case.
	 *
	 * @return array<string, mixed> The record, still `pending`, or `{error}` naming a document not on the case.
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-case-share-is-a-redacted-snapshot-never-the-live-case
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function record(FederatedShareRequest $request, array $caseData): array {
		$sharedFields = $request->sharedFields;
		$sharedDocuments = $request->sharedDocuments;

		// Build the redacted snapshot — allow-listed fields present on the case only.
		$fieldSnapshot = $this->fieldSnapshot(caseData: $caseData, sharedFields: $sharedFields);

		// Only document references already attached to the case may cross.
		$caseDocuments = (array)($caseData['documents'] ?? []);
		$validDocuments = array_values(array_intersect($sharedDocuments, $caseDocuments));
		$invalidDocuments = array_diff($sharedDocuments, $caseDocuments);
		if (count($invalidDocuments) > 0) {
			return ['error' => 'Document(s) not attached to this case: ' . implode(', ', $invalidDocuments)];
		}

		return [
			'caseId' => $request->caseId,
			'remoteCloudId' => $request->remoteCloudId,
			'sharedFields' => array_values($sharedFields),
			'sharedDocuments' => $validDocuments,
			'fieldSnapshot' => $fieldSnapshot,
			'permissionLevel' => $request->permissionLevel,
			'status' => 'pending',
			'createdBy' => $request->createdBy,
		];
	}//end record()

	/**
	 * The case a stored snapshot belongs to, as the store answered it.
	 *
	 * @param mixed $stored The stored snapshot: null when not found, an array, or an entity.
	 *
	 * @return string|null The caseId, or null when not found or when it names none.
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function caseIdOf(mixed $stored): ?string {
		if ($stored === null) {
			return null;
		}

		$shareData = $stored;
		if (is_array($stored) === false) {
			$shareData = $stored->jsonSerialize();
		}

		if (isset($shareData['caseId']) === true) {
			return (string)$shareData['caseId'];
		}

		return null;
	}//end caseIdOf()

	/**
	 * The snapshot marked revoked by the user, now.
	 *
	 * @param array<string, mixed> $shareData The stored snapshot.
	 * @param string               $userId    Who revokes it.
	 *
	 * @return array<string, mixed> The snapshot to save.
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function revoked(array $shareData, string $userId): array {
		$shareData['status'] = 'revoked';
		$shareData['revokedBy'] = $userId;
		$shareData['revokedAt'] = (new DateTime())->format('c');

		return $shareData;
	}//end revoked()

	/**
	 * A saved snapshot as a record: the array itself, or the entity's serialisation.
	 *
	 * @param mixed $saved What the object service answered for the save.
	 *
	 * @return array<string, mixed> The record.
	 *
	 * @spec openspec/specs/federated-case-collaboration/spec.md#federated-share-revocation-is-immediate-and-single-sourced
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function recordOf(mixed $saved): array {
		if (is_array($saved) === true) {
			return $saved;
		}

		return $saved->jsonSerialize();
	}//end recordOf()

	/**
	 * Project the requested fields that are actually present on the case.
	 *
	 * The caller has already rejected any field outside
	 * {@see CaseSharingService::FEDERATION_ALLOWED_FIELDS} (see fieldRefusal()),
	 * so this only drops fields the case does not carry.
	 *
	 * @param array<string, mixed> $caseData     The case data.
	 * @param array<string>        $sharedFields Requested case field names.
	 *
	 * @return array<string, mixed> The redacted snapshot.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	private function fieldSnapshot(array $caseData, array $sharedFields): array {
		$fieldSnapshot = [];
		foreach ($sharedFields as $field) {
			if (array_key_exists($field, $caseData) === true) {
				$fieldSnapshot[$field] = $caseData[$field];
			}
		}

		return $fieldSnapshot;
	}//end fieldSnapshot()
}//end class
