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

use OCA\Dossiq\Service\CaseTypeResolver;
use OCP\Files\File;
use OCP\IUserSession;
use Throwable;

/**
 * What a document's record says when nothing but the file is known.
 *
 * The derived defaults of a drop (REQ-DPR-001): the title from the name, the
 * format and size from the node, the author from the session, the document
 * type from the case type's default or the register's first, the
 * confidentiality from that type or the case's own level, and the sha256
 * integrity block. Kept apart from the projection so the rules that decide
 * WHEN to write stay readable next to the rules that decide WHAT.
 *
 * @spec openspec/specs/document-projection/spec.md
 */
class DocumentDefaults {

	/**
	 * The confidentiality a document gets when neither the case type nor the
	 * document type says otherwise: the case's own, never public.
	 */
	public const FALLBACK_CLASSIFICATION = 'zaakvertrouwelijk';

	/**
	 * Files above this size are not hashed on the request: the integrity
	 * block stays empty and the DRC's own hashing fills it on download.
	 */
	private const HASH_LIMIT_BYTES = 268435456;

	/**
	 * @param DocumentRecordStore $store Document types in OpenRegister.
	 * @param CaseTypeResolver $caseTypes The effective case type, for its document default.
	 * @param IUserSession $userSession Who is writing the file, for `auteur` and `direction`.
	 */
	public function __construct(
		private readonly DocumentRecordStore $store,
		private readonly CaseTypeResolver $caseTypes,
		private readonly IUserSession $userSession,
	) {
	}//end __construct()

	/**
	 * The record a new file gets.
	 *
	 * @param File $node The file.
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return array<string, mixed> The record to store.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function forNewFile(File $node, array $case): array {
		[$actorId, $author]       = $this->actor();
		[$type, $classification] = $this->documentDefaults(case: $case);
		$record = self::fileFacts(node: $node) + [
			'vertrouwelijkheidaanduiding' => $classification,
			'auteur' => $author,
			'status' => 'draft',
			'informatieobjecttype' => $type,
			'direction' => self::directionOf(actorId: $actorId, caseRow: $case),
		];

		return $record + self::registrationDefaults() + ['integrity' => $this->integrityOf(node: $node), 'fileId' => $node->getId()];
	}//end forNewFile()

	/**
	 * Who is signed in: their user id and display name, both '' when nobody is.
	 *
	 * @return array{0: string, 1: string} The id and the name.
	 */
	private function actor(): array {
		$actor = $this->userSession->getUser();
		if ($actor === null) {
			return ['', ''];
		}

		return [$actor->getUID(), $actor->getDisplayName()];
	}//end actor()

	/**
	 * A file's name, size and format as the record carries them.
	 *
	 * @param File $node The file.
	 *
	 * @return array<string, mixed> The title, file name, size and format.
	 */
	private static function fileFacts(File $node): array {
		return [
			'title' => pathinfo($node->getName(), PATHINFO_FILENAME),
			'fileName' => $node->getName(),
			'bestandsomvang' => $node->getSize(),
			'format' => $node->getMimeType(),
		];
	}//end fileFacts()

	/**
	 * Internal when the case's own handler adds the file, incoming otherwise.
	 *
	 * @param string               $actorId Who adds the file, '' when nobody is signed in.
	 * @param array<string, mixed> $caseRow The case row.
	 *
	 * @return string The direction.
	 */
	private static function directionOf(string $actorId, array $caseRow): string {
		if ($actorId !== '' && $actorId === (string)($caseRow['assignee'] ?? '')) {
			return 'internal';
		}

		return 'incoming';
	}//end directionOf()

	/**
	 * The fields every new record starts with, dated today.
	 *
	 * @return array<string, mixed> The keywords, creation date, source organisation, language and description.
	 */
	private static function registrationDefaults(): array {
		return ['keywords' => [], 'creatiedatum' => date('Y-m-d'), 'bronorganisatie' => '', 'taal' => 'nld', 'description' => ''];
	}//end registrationDefaults()

	/**
	 * The document type and confidentiality a case's documents default to.
	 *
	 * The case type's own default first; else the register's first document
	 * type by title. The confidentiality is that type's own (REQ-ZAK-003d),
	 * else the case's own level.
	 *
	 * @param array<string, mixed> $case The case row.
	 *
	 * @return array{0: string, 1: string} Type uuid and classification.
	 */
	private function documentDefaults(array $case): array {
		$typeId = '';
		$caseTypeId = $this->referenceId(value: ($case['caseType'] ?? null));
		if ($caseTypeId !== '') {
			$caseType = $this->caseTypes->effectiveCaseType(caseTypeId: $caseTypeId);
			$typeId = trim((string)($caseType['defaultInformatieobjecttype'] ?? ''));
		}

		$type = $this->store->findDocumentType(typeId: $typeId);
		$classification = trim((string)($type['vertrouwelijkheidaanduiding'] ?? ''));
		if ($classification === '') {
			$classification = self::FALLBACK_CLASSIFICATION;
		}

		return [$this->idOf(row: $type), $classification];
	}//end documentDefaults()

	/**
	 * A reference as a uuid, whether it came as a string or an inlined row.
	 *
	 * @param mixed $value The reference value.
	 *
	 * @return string The uuid, or ''.
	 */
	private function referenceId(mixed $value): string {
		if (is_array($value) === true) {
			return $this->idOf(row: $value);
		}

		if (is_string($value) === false) {
			return '';
		}

		return trim($value);
	}//end referenceId()

	/**
	 * A row's uuid, from `id`, `uuid` or `@self.id`.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The uuid, or ''.
	 */
	private function idOf(array $row): string {
		$self = (array)($row['@self'] ?? []);
		return trim((string)($row['id'] ?? ($row['uuid'] ?? ($self['id'] ?? ''))));
	}//end idOf()

	/**
	 * The sha256 integrity block of a file, empty above the size limit.
	 *
	 * @param File $node The file.
	 *
	 * @return array<string, string> Algorithm, value and date, or [] when not hashed.
	 *
	 * @spec openspec/specs/document-projection/spec.md
	 */
	public function integrityOf(File $node): array {
		if ($node->getSize() > self::HASH_LIMIT_BYTES) {
			return [];
		}

		try {
			$stream = $node->fopen('r');
			if (is_resource($stream) === false) {
				return [];
			}

			$context = hash_init('sha256');
			hash_update_stream($context, $stream);
			fclose($stream);

			return [
				'algorithm' => 'sha256',
				'value' => hash_final($context),
				'date' => date('Y-m-d\TH:i:s'),
			];
		} catch (Throwable) {
			return [];
		}
	}//end integrityOf()
}//end class
