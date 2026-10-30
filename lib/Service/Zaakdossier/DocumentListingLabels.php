<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zaakdossier;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * The two values the case Files tab shows beside a document that are not
 * stored on it as written: its type by name, and the other side of it.
 *
 * Both are added to the rows of the dossier listing, which the Files tab
 * reads once per folder, so a column costs no request of its own.
 *
 * @spec openspec/changes/case-files-list-columns/specs/document-zaakdossier/spec.md#requirement-the-case-files-list-shows-the-document-columns-of-the-board-req-zak-col-01
 */
class DocumentListingLabels {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and config.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Every document with `documentType` and `partyName`.
	 *
	 * `documentType` is the type's name. A document refers to its type by
	 * uuid, by slug or by the name itself (DocumentTypeResolver passes an
	 * unknown name through), and the catalogue is read once for the whole
	 * listing. A reference the catalogue does not know reads as written,
	 * unless it is a uuid, which reads ''.
	 *
	 * `partyName` is the sender of an incoming document, the recipients of an
	 * outgoing one, and for any other whichever it names, the sender first.
	 * It reads the names CorrespondentWriter::describeAll() put on the row.
	 *
	 * @param array<int, array<string, mixed>> $documents The listing rows.
	 *
	 * @return array<int, array<string, mixed>> The rows with both values.
	 *
	 * @throws RuntimeException When OpenRegister cannot be read.
	 *
	 * @spec openspec/changes/case-files-list-columns/specs/document-zaakdossier/spec.md#requirement-the-case-files-list-shows-the-document-columns-of-the-board-req-zak-col-01
	 */
	public function label(array $documents): array {
		if ($documents === []) {
			return [];
		}

		$names = $this->typeNames();
		foreach ($documents as $index => $document) {
			$reference = trim((string)($document['informatieobjecttype'] ?? ''));
			$name = ($names[$reference] ?? $reference);
			if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $name) === 1) {
				$name = '';
			}

			$documents[$index]['documentType'] = $name;
			$documents[$index]['partyName'] = $this->partyNameOf(document: $document);
		}

		return $documents;
	}//end label()

	/**
	 * The other side of one document as one line.
	 *
	 * @param array<string, mixed> $document The listing row.
	 *
	 * @return string The sender or the recipients, '' when it names neither.
	 */
	private function partyNameOf(array $document): string {
		$sender = trim((string)($document['senderName'] ?? ''));
		$recipients = implode(', ', array_filter(array_map('strval', (array)($document['recipientNames'] ?? []))));

		$direction = trim((string)($document['direction'] ?? ''));
		if ($direction === DocumentCorrespondents::DIRECTION_INCOMING) {
			return $sender;
		}

		if ($direction === DocumentCorrespondents::DIRECTION_OUTGOING || $sender === '') {
			return $recipients;
		}

		return $sender;
	}//end partyNameOf()

	/**
	 * Every type's name, keyed by its uuid, its slug and the name itself.
	 *
	 * An instance with no type catalogue configured has no names, so every
	 * type reads as stored.
	 *
	 * @return array<string, string> Reference to name.
	 *
	 * @throws RuntimeException When OpenRegister is not available.
	 */
	private function typeNames(): array {
		$schema = $this->settingsService->getConfigValue('dossier_informatieobjecttype_schema');
		if ($schema === '') {
			return [];
		}

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue('register');

		$rows = $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: ['_limit' => 500],
		);

		$names = [];
		foreach ($rows as $row) {
			$name = trim((string)($row['description'] ?? ''));
			$self = (array)($row['@self'] ?? []);
			$references = [(string)($row['id'] ?? ($self['id'] ?? '')), (string)($self['slug'] ?? ''), $name];
			foreach ($references as $reference) {
				if ($name !== '' && $reference !== '' && isset($names[$reference]) === false) {
					$names[$reference] = $name;
				}
			}
		}

		return $names;
	}//end typeNames()
}//end class
