<?php

/**
 * Dossiq case access purpose
 *
 * Records why a reader opens a case whose case type processes special
 * categories of personal data, in OpenRegister's audit trail on that case:
 * who, when, which purpose and, for "other", the reader's own words.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/changes/case-access-purpose/specs/case-access-purpose/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records a reader's purpose for opening a case.
 *
 * @spec openspec/changes/case-access-purpose/specs/case-access-purpose/spec.md
 */
class CaseAccessPurposeService {

	/**
	 * The audit trail action of a recorded purpose.
	 */
	public const ACTION = 'dossiq.case.access-purpose';

	/**
	 * The purposes a reader can pick; `other` needs the reader's own words.
	 * The case page offers the same list (src/menu-layout.simple.json).
	 */
	public const PURPOSES = ['handling', 'advice', 'quality-check', 'legal-obligation', 'data-subject-request', 'other'];

	/**
	 * The longest purpose a reader can write under "other".
	 */
	public const NOTE_MAX = 500;

	private const AUDIT_MAPPER = 'OCA\\OpenRegister\\Db\\AuditTrailMapper';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The app's OpenRegister configuration.
	 * @param LoggerInterface $logger          The log.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Record the purpose, or say why it is refused.
	 *
	 * The case is read as the reader, so a case the reader may not open is
	 * refused before anything is written. A case type that names no special
	 * categories asks no purpose and nothing is written. A purpose that could
	 * not be written is refused, so the page stays closed rather than opening
	 * unrecorded.
	 *
	 * @param string $caseId   The case uuid.
	 * @param string $readerId The reader's user id.
	 * @param string $purpose  One of {@see self::PURPOSES}.
	 * @param string $note     The reader's own words, for `other`.
	 *
	 * @return array{recorded: bool, required: bool} Whether a purpose was required and recorded.
	 *
	 * @throws RefusedException When the purpose is invalid, the case cannot be read, or the entry cannot be written.
	 *
	 * @spec openspec/changes/case-access-purpose/specs/case-access-purpose/spec.md#requirement-opening-a-sensitive-case-records-its-purpose
	 */
	public function record(string $caseId, string $readerId, string $purpose, string $note): array {
		$note = trim($note);
		$this->assertPurpose(purpose: $purpose, note: $note);

		$objectService = $this->settingsService->getObjectService();
		$mapper = $this->settingsService->getOpenRegisterClass(class: self::AUDIT_MAPPER);
		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		$caseTypeSchema = $this->settingsService->getConfigValue('case_type_schema');
		if ($objectService === null || $mapper === null || $register === '' || $caseSchema === '' || $caseTypeSchema === '') {
			throw $this->notRecorded(previous: null);
		}

		$case = $this->find(
			objectService: $objectService,
			id: $caseId,
			register: $register,
			schema: $caseSchema,
			refusal: new RefusedException(rule: 'case-not-readable', sentence: 'This case cannot be opened.', status: 404)
		);

		$categories = $this->categoriesOf(objectService: $objectService, case: $case, register: $register, schema: $caseTypeSchema);
		if ($categories === []) {
			return ['recorded' => false, 'required' => false];
		}

		$context = [
			'reader' => $readerId,
			'at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
			'purpose' => $purpose,
			'note' => $note,
			'categories' => $categories,
		];
		try {
			$mapper->createAuditTrailEntry(
				object: $case,
				action: self::ACTION,
				context: $context,
				actorId: $readerId,
				actorName: $readerId,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: an access purpose could not be recorded, so the case stays closed',
				['app' => Application::APP_ID, 'case' => $caseId, 'exception' => $e->getMessage()]
			);
			throw $this->notRecorded(previous: $e);
		}

		return ['recorded' => true, 'required' => true];
	}//end record()

	/**
	 * Refuse a purpose that is not on the list, or an "other" without words.
	 *
	 * @param string $purpose The purpose.
	 * @param string $note    The reader's own words, trimmed.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the purpose is not acceptable.
	 */
	private function assertPurpose(string $purpose, string $note): void {
		if (in_array($purpose, self::PURPOSES, true) === false) {
			throw new RefusedException(
				rule: 'access-purpose-unknown',
				sentence: 'Pick one of the purposes offered.',
				status: RefusedException::STATUS_UNPROCESSABLE
			);
		}

		if ($purpose === 'other' && ($note === '' || mb_strlen($note) > self::NOTE_MAX)) {
			throw new RefusedException(
				rule: 'access-purpose-note',
				sentence: 'Describe your purpose in at most 500 characters.',
				status: RefusedException::STATUS_UNPROCESSABLE
			);
		}
	}//end assertPurpose()

	/**
	 * An object as the reader may read it.
	 *
	 * @param object           $objectService OpenRegister's ObjectService.
	 * @param string           $id            The object uuid.
	 * @param string           $register      The register.
	 * @param string           $schema        The schema.
	 * @param RefusedException $refusal       What a miss is answered with.
	 *
	 * @return object The entity.
	 *
	 * @throws RefusedException When the reader cannot read it.
	 */
	private function find(object $objectService, string $id, string $register, string $schema, RefusedException $refusal): object {
		try {
			$found = $objectService->find($id, register: $register, schema: $schema);
		} catch (Throwable $e) {
			throw $refusal;
		}

		if (is_object($found) === false) {
			throw $refusal;
		}

		return $found;
	}//end find()

	/**
	 * The special categories the case's case type names, [] when it names none.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param object $case          The case entity.
	 * @param string $register      The register.
	 * @param string $schema        The case type schema.
	 *
	 * @return list<string> The categories.
	 *
	 * @throws RefusedException When the case type cannot be read.
	 */
	private function categoriesOf(object $objectService, object $case, string $register, string $schema): array {
		$caseData = $this->dataOf(entity: $case);
		$caseTypeId = (string)($caseData['caseType'] ?? '');
		if ($caseTypeId === '') {
			return [];
		}

		// The case type decides whether a purpose is asked, so one that cannot
		// be read is not taken to ask nothing.
		$caseType = $this->find(
			objectService: $objectService,
			id: $caseTypeId,
			register: $register,
			schema: $schema,
			refusal: $this->notRecorded(previous: null)
		);

		$categories = ($this->dataOf(entity: $caseType)['specialDataCategories'] ?? []);
		if (is_array($categories) === false) {
			return [];
		}

		return array_values(array_filter(array_map('strval', $categories), static fn (string $category): bool => $category !== ''));
	}//end categoriesOf()

	/**
	 * An entity's own fields.
	 *
	 * @param object $entity The OpenRegister entity.
	 *
	 * @return array<string, mixed> The fields.
	 */
	private function dataOf(object $entity): array {
		if (method_exists($entity, 'getObject') === true) {
			$data = $entity->getObject();
			if (is_array($data) === true) {
				return $data;
			}
		}

		if (method_exists($entity, 'jsonSerialize') === true) {
			$data = $entity->jsonSerialize();
			if (is_array($data) === true) {
				return $data;
			}
		}

		return [];
	}//end dataOf()

	/**
	 * The refusal of a purpose that could not be recorded.
	 *
	 * @param Throwable|null $previous The failure underneath.
	 *
	 * @return RefusedException A 503 refusal.
	 */
	private function notRecorded(?Throwable $previous): RefusedException {
		return new RefusedException(
			rule: 'access-purpose-not-recorded',
			sentence: 'Your purpose could not be recorded, so the case does not open.',
			status: RefusedException::STATUS_INDETERMINATE,
			previous: $previous,
		);
	}//end notRecorded()
}//end class
