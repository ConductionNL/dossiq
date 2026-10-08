<?php

/**
 * Dossiq case record store for conversations.
 *
 * Reads a case through OpenRegister, so OpenRegister's own scoping applies,
 * and writes back only the fields the conversation services own. Both
 * {@see CaseConversationService} and {@see MajorCaseDeclaration} write onto
 * the same case, and holding the read and the partial write once keeps the
 * two from drifting into two different ideas of what a case write is.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Conversation
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
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Conversation;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use RuntimeException;

/**
 * Reads a case and writes the conversation fields onto it.
 *
 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
 */
class CaseRecordStore {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Register and schema configuration.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
	) {
	}//end __construct()

	/**
	 * Read a case, or null when it is not readable.
	 *
	 * @param string $caseId Case UUID.
	 *
	 * @return array<string, mixed>|null The case.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function read(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');
		if (empty($register) === true || empty($schema) === true) {
			return null;
		}

		return $this->findObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $caseId,
		);
	}//end read()

	/**
	 * Write these fields onto a case, and nothing else.
	 *
	 * @param string               $caseId  Case UUID.
	 * @param array<string, mixed> $changes The fields to write.
	 *
	 * @return array<string, mixed>|null The stored case.
	 *
	 * @throws RuntimeException When OpenRegister is not configured for cases.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function write(string $caseId, array $changes): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');
		if (empty($register) === true || empty($schema) === true) {
			throw new RuntimeException('Case schema not configured');
		}

		return $this->patchObjectAsArray(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $caseId,
			changes: $changes,
		);
	}//end write()

	/**
	 * The conversations already recorded on a case.
	 *
	 * @param array<string, mixed> $case The case as stored.
	 *
	 * @return array<int, array<string, mixed>> The records, newest last.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function conversationsOf(array $case): array {
		$conversations = ($case['conversations'] ?? []);
		if (is_array($conversations) === false) {
			return [];
		}

		return array_values(array_filter($conversations, 'is_array'));
	}//end conversationsOf()

	/**
	 * The name a room on this case carries into Talk.
	 *
	 * @param array<string, mixed> $case     The case as stored.
	 * @param string               $fallback What to call a case that names itself nothing.
	 *
	 * @return string The room name.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function roomNameFor(array $case, string $fallback): string {
		$identifier = (string)($case['identifier'] ?? ($case['title'] ?? ''));
		if ($identifier === '') {
			return $fallback;
		}

		return $fallback . ' ' . $identifier;
	}//end roomNameFor()

	/**
	 * The case type a case names, as an id or slug.
	 *
	 * @param array<string, mixed> $case The case as stored.
	 *
	 * @return string The reference, empty when the case names none.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function caseTypeIdOf(array $case): string {
		$caseType = ($case['caseType'] ?? '');
		if (is_array($caseType) === true) {
			return (string)($caseType['id'] ?? ($caseType['uuid'] ?? ''));
		}

		return (string)$caseType;
	}//end caseTypeIdOf()

	/**
	 * The current moment, in the format the schemas store.
	 *
	 * @return string An ISO 8601 timestamp.
	 *
	 * @spec openspec/changes/live-conversation-on-the-case/specs/case-management/spec.md
	 */
	public function now(): string {
		return (new DateTimeImmutable())->format(DateTimeImmutable::ATOM);
	}//end now()

}//end class
