<?php

/**
 * Dossiq CaseChangesFeed.
 *
 * What other people changed on cases since a moment, for a board that keeps
 * itself up to date (board DqWerkbord, "Zojuist bijgewerkt door Anouk
 * Bakker").
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Board
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
 * @spec openspec/specs/realtime-updates-ui/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Board;

use DateTimeImmutable;
use DateTimeInterface;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IL10N;

/**
 * Reads the case changes from OpenRegister's audit trail.
 *
 * The audit trail is read across every case, so each case it names is read
 * again as the caller before it is answered: a case the reader may not open
 * is never named, not even by its id.
 *
 * @spec openspec/specs/realtime-updates-ui/spec.md
 */
class CaseChangesFeed {
	use SearchesObjects;

	/**
	 * OpenRegister's audit trail mapper, by name: it is optional.
	 */
	private const AUDIT_MAPPER = 'OCA\\OpenRegister\\Db\\AuditTrailMapper';

	/**
	 * How many audit entries one poll reads, newest first.
	 */
	private const READ_LIMIT = 200;

	/**
	 * How many changed cases one answer names.
	 */
	private const ANSWER_LIMIT = 50;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The object service, the mapper and the schema ids.
	 * @param IL10N           $l10n            Translates the line about each change.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IL10N $l10n,
	) {
	}//end __construct()

	/**
	 * The cases others changed after `$since`, newest first, one entry per case.
	 *
	 * @param string               $readerId The reader; their own changes are left out.
	 * @param DateTimeImmutable    $since    The moment the board last asked.
	 * @param array<string, string> $scope   Optional `caseType` and `assignedGroup` the board shows.
	 *
	 * @return array{changes: list<array<string, string>>, now: string}
	 *
	 * @spec openspec/specs/realtime-updates-ui/spec.md
	 */
	public function since(string $readerId, DateTimeImmutable $since, array $scope): array {
		$now = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
		$mapper = $this->settingsService->getOpenRegisterClass(class: self::AUDIT_MAPPER);
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_schema');
		if ($mapper === null || $objectService === null || $register === '' || $schema === '') {
			return ['changes' => [], 'now' => $now];
		}

		$changes = [];
		foreach ($this->latestPerCase(mapper: $mapper, schema: $schema, since: $since, readerId: $readerId) as $caseId => $entry) {
			$case = $this->readableCase(objectService: $objectService, register: $register, schema: $schema, caseId: $caseId);
			if ($case !== null && $this->inScope(case: $case, scope: $scope) === true) {
				$changes[] = $this->changeOf(entry: $entry, caseId: $caseId);
			}

			if (count($changes) >= self::ANSWER_LIMIT) {
				break;
			}
		}

		return ['changes' => $changes, 'now' => $now];
	}//end since()

	/**
	 * The newest audit entry per case after the moment, by someone else.
	 *
	 * @param object            $mapper   The audit trail mapper.
	 * @param string            $schema   The case schema id.
	 * @param DateTimeImmutable $since    The moment.
	 * @param string            $readerId The reader.
	 *
	 * @return array<string, object> The entry per case id, newest case first.
	 */
	private function latestPerCase(object $mapper, string $schema, DateTimeImmutable $since, string $readerId): array {
		$filters = [];
		if (ctype_digit($schema) === true) {
			$filters['schema'] = $schema;
		}

		$latest = [];
		foreach ($mapper->findAll(limit: self::READ_LIMIT, filters: $filters, sort: ['created' => 'DESC']) as $entry) {
			$caseId = (string)$entry->getObjectUuid();
			$created = $entry->getCreated();
			if ($caseId === '' || isset($latest[$caseId]) === true || (string)$entry->getUser() === $readerId) {
				continue;
			}

			if ($created instanceof DateTimeInterface && $created > $since) {
				$latest[$caseId] = $entry;
			}
		}

		return $latest;
	}//end latestPerCase()

	/**
	 * The case as the reader may read it, or null. OpenRegister answers a case
	 * outside the reader's rights as not found, which the read maps to null; any
	 * other failure is not swallowed.
	 *
	 * @param object $objectService The OpenRegister object service.
	 * @param string $register      The register id.
	 * @param string $schema        The case schema id.
	 * @param string $caseId        The case id.
	 *
	 * @return array<string, mixed>|null
	 */
	private function readableCase(object $objectService, string $register, string $schema, string $caseId): ?array {
		return $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $caseId);
	}//end readableCase()

	/**
	 * Whether a case belongs on the board that asked.
	 *
	 * @param array<string, mixed>  $case  The case.
	 * @param array<string, string> $scope The board's case type and group, each optional.
	 *
	 * @return bool
	 */
	private function inScope(array $case, array $scope): bool {
		foreach (['caseType', 'assignedGroup'] as $field) {
			$wanted = trim((string)($scope[$field] ?? ''));
			$value = $case[$field] ?? '';
			if (is_array($value) === true) {
				$value = ($value['id'] ?? ($value['uuid'] ?? ''));
			}

			if ($wanted !== '' && (string)$value !== $wanted) {
				return false;
			}
		}

		return true;
	}//end inScope()

	/**
	 * One change in the board's shape.
	 *
	 * @param object $entry  The audit entry.
	 * @param string $caseId The case id.
	 *
	 * @return array<string, string>
	 */
	private function changeOf(object $entry, string $caseId): array {
		$actor = (string)($entry->getUserName() ?? $entry->getUser());
		$text = $this->l10n->t('Updated by %s', [$actor]);
		if ((string)$entry->getAction() === 'create') {
			$text = $this->l10n->t('Created by %s', [$actor]);
		}

		return [
			'id' => (string)$entry->getUuid(),
			'actor' => $actor,
			'actorId' => (string)$entry->getUser(),
			'text' => $text,
			'objectId' => $caseId,
			'at' => $entry->getCreated()->format(DateTimeInterface::ATOM),
		];
	}//end changeOf()
}//end class
