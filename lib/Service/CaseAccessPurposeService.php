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
 * @spec openspec/specs/case-access-purpose/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\People\CaseRoleHolder;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Records a reader's purpose for opening a case.
 *
 * @spec openspec/specs/case-access-purpose/spec.md
 */
class CaseAccessPurposeService {

	/**
	 * The audit trail action of a recorded purpose.
	 */
	public const ACTION = 'dossiq.case.access-purpose';

	/**
	 * The audit trail action of a role a purpose bound the reader to.
	 */
	public const ACTION_ROLE = 'dossiq.case.access-role';

	/**
	 * The app configuration key of the purpose and role rules.
	 */
	public const ROLES_KEY = AccessPurposeRules::KEY;

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
	 * @param CaseRoleHolder  $roles           Who holds a generic role on a case, and binding one.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly CaseRoleHolder $roles,
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
	 * @return array{recorded: bool, required: bool, roleAdded?: bool} Whether a purpose was required and recorded,
	 *         and whether it bound the reader to a role.
	 *
	 * @throws RefusedException When the purpose is invalid, the case cannot be read, or the entry cannot be written.
	 *
	 * @spec openspec/specs/case-access-purpose/spec.md#requirement-opening-a-sensitive-case-records-its-purpose
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

		$this->write(
			mapper: $mapper,
			case: $case,
			readerId: $readerId,
			action: self::ACTION,
			context: ['purpose' => $purpose, 'note' => $note, 'categories' => $categories],
		);

		$roleAdded = $this->bindFor(mapper: $mapper, case: $case, readerId: $readerId, purpose: $purpose);

		return ['recorded' => true, 'required' => true, 'roleAdded' => $roleAdded];
	}//end record()

	/**
	 * Whether the reader must state a purpose to open this case.
	 *
	 * A case whose case type names no special categories asks none. A reader
	 * who holds one of the configured generic roles on the case (by default
	 * the handler) does not either: the configured purpose is recorded for
	 * them, marked automatic, and the case opens without the dialog.
	 *
	 * @param string $caseId   The case uuid.
	 * @param string $readerId The reader's user id.
	 *
	 * @return array{required: bool, recorded: bool} Whether a purpose is asked, and whether one was recorded now.
	 *
	 * @throws RefusedException When the case cannot be read, or the automatic purpose cannot be written.
	 *
	 * @spec openspec/specs/case-access-purpose/spec.md#requirement-the-cases-own-handler-opens-it-without-the-dialog
	 */
	public function check(string $caseId, string $readerId): array {
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
			return ['required' => false, 'recorded' => false];
		}

		$rules = $this->rules();
		$caseData = $this->dataOf(entity: $case);
		if ($this->holdsSkipRole(case: $caseData, readerId: $readerId, genericRoles: $rules['skipFor']) === false) {
			return ['required' => true, 'recorded' => false];
		}

		$this->write(
			mapper: $mapper,
			case: $case,
			readerId: $readerId,
			action: self::ACTION,
			context: ['purpose' => $rules['skipPurpose'], 'note' => '', 'categories' => $categories, 'automatic' => true],
		);

		return ['required' => false, 'recorded' => true];
	}//end check()

	/**
	 * Bind the reader to the role their purpose names, and say so in the trail.
	 *
	 * @param object $mapper   OpenRegister's audit trail mapper.
	 * @param object $case     The case entity.
	 * @param string $readerId The reader's user id.
	 * @param string $purpose  The recorded purpose.
	 *
	 * @return bool True when the reader was bound.
	 *
	 * @spec openspec/specs/case-access-purpose/spec.md#requirement-handling-the-case-makes-you-its-handler
	 */
	private function bindFor(object $mapper, object $case, string $readerId, string $purpose): bool {
		$role = ($this->rules()['assign'][$purpose] ?? '');
		if (is_string($role) === false || $role === '') {
			return false;
		}

		$caseData = $this->dataOf(entity: $case);
		if ($caseData === [] || isset($caseData['id']) === false) {
			$caseData['id'] = $this->idOfEntity(entity: $case);
		}

		try {
			$bound = $this->roles->bind(case: $caseData, uid: $readerId, genericRole: $role, displayName: '');
		} catch (Throwable $e) {
			// The purpose is recorded and the case opens; only the binding is missing.
			$this->logger->warning(
				'Dossiq: the roles on the case could not be read, so the reader is not bound to the role their purpose names',
				['app' => Application::APP_ID, 'role' => $role, 'exception' => $e->getMessage()]
			);
			$bound = false;
		}

		if ($bound === false) {
			return false;
		}

		try {
			$this->write(
				mapper: $mapper,
				case: $case,
				readerId: $readerId,
				action: self::ACTION_ROLE,
				context: ['purpose' => $purpose, 'role' => $role, 'participant' => 'user:'.$readerId],
			);
		} catch (RefusedException $e) {
			// The binding stands; the trail misses only the line that says why.
			$this->logger->warning('Dossiq: the role an access purpose added could not be written to the trail', ['app' => Application::APP_ID]);
		}

		return true;
	}//end bindFor()

	/**
	 * Whether the reader holds a role that opens the case without the dialog.
	 * Roles that cannot be read count as not held, so the reader is asked:
	 * the dialog is the safe side of a failed read.
	 *
	 * @param array<string, mixed> $case         The case's fields.
	 * @param string               $readerId     The reader's user id.
	 * @param array<int, string>   $genericRoles The generic roles that skip the dialog.
	 *
	 * @return bool True when the reader holds one.
	 *
	 * @spec openspec/specs/case-access-purpose/spec.md#requirement-the-cases-own-handler-opens-it-without-the-dialog
	 */
	private function holdsSkipRole(array $case, string $readerId, array $genericRoles): bool {
		try {
			return $this->roles->holdsAny(case: $case, uid: $readerId, genericRoles: $genericRoles);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: the roles on the case could not be read, so the reader is asked for a purpose',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return false;
		}
	}//end holdsSkipRole()

	/**
	 * The purpose and role rules: the app configuration, else the defaults.
	 *
	 * @return array{skipFor: array<int, string>, skipPurpose: string, assign: array<string, string>} The rules.
	 *
	 * @spec openspec/specs/case-access-purpose/spec.md
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) fromConfig() is a pure reader of the configured JSON.
	 */
	private function rules(): array {
		return AccessPurposeRules::fromConfig(raw: $this->settingsService->getConfigValue(self::ROLES_KEY), purposes: self::PURPOSES);
	}//end rules()

	/**
	 * Write one audit entry on the case, with the reader and the time.
	 *
	 * @param object               $mapper   OpenRegister's audit trail mapper.
	 * @param object               $case     The case entity.
	 * @param string               $readerId The reader's user id.
	 * @param string               $action   The audit action.
	 * @param array<string, mixed> $context  What the entry records.
	 *
	 * @return void
	 *
	 * @throws RefusedException When the entry cannot be written.
	 */
	private function write(object $mapper, object $case, string $readerId, string $action, array $context): void {
		try {
			$mapper->createAuditTrailEntry(
				object: $case,
				action: $action,
				context: array_merge(['reader' => $readerId, 'at' => (new DateTimeImmutable())->format(DateTimeInterface::ATOM)], $context),
				actorId: $readerId,
				actorName: $readerId,
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: an access purpose could not be recorded, so the case stays closed',
				['app' => Application::APP_ID, 'action' => $action, 'exception' => $e->getMessage()]
			);
			throw $this->notRecorded(previous: $e);
		}
	}//end write()

	/**
	 * An entity's uuid.
	 *
	 * @param object $entity The OpenRegister entity.
	 *
	 * @return string The uuid, or ''.
	 */
	private function idOfEntity(object $entity): string {
		if (method_exists($entity, 'getUuid') === true) {
			return (string) $entity->getUuid();
		}

		return '';
	}//end idOfEntity()

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
