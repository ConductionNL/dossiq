<?php

/**
 * Dossiq Disclosable Document Check
 *
 * Says whether a set of disclosable documents may go out in a publication,
 * without reading any store: a document whose file could not be read would
 * go out as an entry without its file, and a document under an access
 * exception names its only readers, which a publication contradicts.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Publication
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Publication;

use OCA\Dossiq\Service\Zaakdossier\AccessExceptionValue;

/**
 * The refusal a set of disclosable documents earns before it is published.
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-with-an-exception-is-never-published
 */
class DisclosableDocumentCheck {

	/**
	 * The refusal a set of disclosable documents earns: one whose file cannot be read, or one under an access
	 * exception. Null when the set may go out.
	 *
	 * @param array<int, array<string, mixed>> $documents The disclosable documents.
	 *
	 * @return array{available: false, reason: string, documents: list<string>}|null The refusal.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-with-an-exception-is-never-published
	 */
	public function refusal(array $documents): ?array {
		$unreadable = $this->unreadable(documents: $documents);
		if ($unreadable !== []) {
			return ['available' => false, 'reason' => 'documents_unreadable', 'documents' => $unreadable];
		}

		$restricted = $this->restricted(documents: $documents);
		if ($restricted !== []) {
			return ['available' => false, 'reason' => 'documents_restricted', 'documents' => $restricted];
		}

		return null;
	}//end refusal()

	/**
	 * The disclosable documents whose file could not be read.
	 *
	 * Each of them would go out as a publication entry without its file, so a
	 * publication with one of them is refused instead.
	 *
	 * @param array<int, array<string, mixed>> $documents The disclosable documents.
	 *
	 * @return list<string> Their names.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function unreadable(array $documents): array {
		$names = [];
		foreach ($documents as $document) {
			if (empty($document['content']) === true) {
				$names[] = $this->nameOf(document: $document);
			}
		}

		return $names;
	}//end unreadable()

	/**
	 * The disclosable documents that name their only readers: an access exception and a publication contradict
	 * each other, so the publication is refused until the exception is lifted.
	 *
	 * @param array<int, array<string, mixed>> $documents The disclosable documents.
	 *
	 * @return list<string> Their names.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 * @spec openspec/changes/document-access-exception/specs/document-access-exception/spec.md#requirement-a-document-with-an-exception-is-never-published
	 */
	private function restricted(array $documents): array {
		$value = new AccessExceptionValue();
		$names = [];
		foreach ($documents as $document) {
			if ($value->exceptionOf(record: $document) !== null) {
				$names[] = $this->nameOf(document: $document);
			}
		}

		return $names;
	}//end restricted()

	/**
	 * The name a refusal lists a document by: its file name, else its title, else its id.
	 *
	 * @param array<string, mixed> $document The document.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
	 */
	private function nameOf(array $document): string {
		return (string)($document['fileName'] ?? ($document['title'] ?? ($document['id'] ?? '')));
	}//end nameOf()
}//end class
