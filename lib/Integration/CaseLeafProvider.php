<?php

/**
 * Dossiq CaseLeafProvider.
 *
 * The cases of a party a host app knows by master entity. A host app
 * (pipelinq's client page) does not hold dossiq's party uuids; it holds the
 * master entity its own party record shares with dossiq's brpPerson or
 * kvkCompany (`masterEntityRef`), so that is the key this leaf answers on.
 *
 * @category Integration
 * @package  OCA\Dossiq\Integration
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git_id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/case-leaf-by-master-entity/specs/case-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Integration;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Read the cases of a party by its master entity.
 *
 * Every read goes through OpenRegister as the calling user, so a party or a
 * case the caller may not read is not returned.
 *
 * @spec openspec/changes/case-leaf-by-master-entity/specs/case-leaf/spec.md
 */
class CaseLeafProvider {

	use SearchesObjects;

	/**
	 * The party schemas a master entity can resolve to.
	 *
	 * @var array<int, string>
	 */
	public const PARTY_SCHEMAS = ['brpPerson', 'kvkCompany'];

	/**
	 * The status filters a read may ask for.
	 *
	 * @var array<int, string>
	 */
	public const STATUS_FILTERS = ['open', 'closed', 'all'];

	/**
	 * The most parties or cases one read returns, per ADR-058.
	 *
	 * @var int
	 */
	public const MAX_ROWS = 1000;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The register and the object service.
	 * @param IUserManager    $userManager     Names the handler.
	 * @param IURLGenerator   $urlGenerator    Links a case to its page.
	 * @param LoggerInterface $logger          PSR logger.
	 * @param LanguageMapText $text            A translatable title or name as one string.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserManager $userManager,
		private readonly IURLGenerator $urlGenerator,
		private readonly LoggerInterface $logger,
		private readonly LanguageMapText $text = new LanguageMapText(),
	) {
	}//end __construct()

	/**
	 * The cases of the parties that map to one master entity: open cases
	 * first by deadline, then closed cases newest first.
	 *
	 * @param string $ref    The master entity reference.
	 * @param string $status `open`, `closed` or `all`.
	 *
	 * @return array<string, mixed> `status` plus either `cases` or `error`.
	 *
	 * @spec openspec/changes/case-leaf-by-master-entity/specs/case-leaf/spec.md#requirement-a-host-app-reads-a-partys-cases-by-master-entity
	 */
	public function byMasterEntity(string $ref, string $status = 'all'): array {
		if (in_array($status, self::STATUS_FILTERS, true) === false) {
			return ['status' => 400, 'error' => 'Unknown case status filter.'];
		}

		$ref = trim($ref);
		if ($ref === '') {
			return ['status' => 200, 'cases' => []];
		}

		$register = (string)$this->settingsService->getConfigValue('register');
		$objectService = $this->settingsService->getObjectService();
		if ($register === '' || $objectService === null) {
			return ['status' => 503, 'error' => 'The case surface is not configured.'];
		}

		$cases = [];
		foreach ($this->partyIds(objectService: $objectService, register: $register, ref: $ref) as $partyId) {
			foreach ($this->read(objectService: $objectService, register: $register, schema: 'case', filters: ['requester' => $partyId]) as $case) {
				$id = (string)($case['id'] ?? '');
				if ($id !== '' && $this->matches(case: $case, status: $status) === true) {
					$cases[$id] = $case;
				}
			}
		}

		$types = $this->byId(objectService: $objectService, register: $register, schema: 'caseType', cases: $cases, field: 'caseType');
		$statuses = $this->byId(objectService: $objectService, register: $register, schema: 'statusType', cases: $cases, field: 'status');

		$rows = array_map(
			fn (array $case): array => $this->present(
				case: $case,
				caseType: ($types[(string)($case['caseType'] ?? '')] ?? []),
				statusType: ($statuses[(string)($case['status'] ?? '')] ?? [])
			),
			array_values($cases)
		);
		usort($rows, fn (array $left, array $right): int => $this->compare(left: $left, right: $right));

		return ['status' => 200, 'cases' => $rows];
	}//end byMasterEntity()

	/**
	 * Order open cases first by deadline, then closed cases newest first.
	 *
	 * @param array<string, mixed> $left  One row.
	 * @param array<string, mixed> $right The other.
	 *
	 * @return int Negative, zero or positive.
	 */
	private function compare(array $left, array $right): int {
		if ($left['isFinal'] !== $right['isFinal']) {
			return $left['isFinal'] <=> $right['isFinal'];
		}

		if ($left['isFinal'] === true) {
			return strcmp((string)$right['endDate'], (string)$left['endDate']);
		}

		return strcmp($this->dueKey(row: $left), $this->dueKey(row: $right));
	}//end compare()

	/**
	 * The deadline a row sorts on; a case without one sorts last.
	 *
	 * @param array<string, mixed> $row The row.
	 *
	 * @return string The sort key.
	 */
	private function dueKey(array $row): string {
		if ($row['deadline'] === '') {
			return '9999';
		}

		return (string)$row['deadline'];
	}//end dueKey()

	/**
	 * The ids of the parties that carry the master entity.
	 *
	 * @param object $objectService OpenRegister's object service.
	 * @param string $register      The register.
	 * @param string $ref           The master entity reference.
	 *
	 * @return array<int, string> The party ids.
	 */
	private function partyIds(object $objectService, string $register, string $ref): array {
		$ids = [];
		foreach (self::PARTY_SCHEMAS as $schema) {
			foreach ($this->read(objectService: $objectService, register: $register, schema: $schema, filters: ['masterEntityRef' => $ref]) as $party) {
				$id = (string)($party['id'] ?? '');
				if ($id !== '' && (string)($party['masterEntityRef'] ?? '') === $ref) {
					$ids[$id] = true;
				}
			}
		}

		return array_keys($ids);
	}//end partyIds()

	/**
	 * Whether a case fits the asked status filter.
	 *
	 * @param array<string, mixed> $case   The stored case.
	 * @param string               $status `open`, `closed` or `all`.
	 *
	 * @return bool True when it fits.
	 */
	private function matches(array $case, string $status): bool {
		if ($status === 'all') {
			return true;
		}

		return (($case['isFinalStatus'] ?? false) === true) === ($status === 'closed');
	}//end matches()

	/**
	 * The objects the cases point at through one field, by id, read once.
	 *
	 * @param object                              $objectService OpenRegister's object service.
	 * @param string                              $register      The register.
	 * @param string                              $schema        The schema of the objects.
	 * @param array<string, array<string, mixed>> $cases         The cases.
	 * @param string                              $field         The field holding the id.
	 *
	 * @return array<string, array<string, mixed>> The objects by id.
	 */
	private function byId(object $objectService, string $register, string $schema, array $cases, string $field): array {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map(static fn (array $case): string => (string)($case[$field] ?? ''), $cases),
					static fn (string $id): bool => $id !== ''
				)
			)
		);
		if ($ids === []) {
			return [];
		}

		$found = [];
		foreach ($this->read(objectService: $objectService, register: $register, schema: $schema, filters: ['_ids' => $ids]) as $row) {
			$found[(string)($row['id'] ?? '')] = $row;
		}

		return $found;
	}//end byId()

	/**
	 * The fields of a case a host app shows.
	 *
	 * @param array<string, mixed> $case       The stored case.
	 * @param array<string, mixed> $caseType   Its case type, or none.
	 * @param array<string, mixed> $statusType Its status, or none.
	 *
	 * @return array<string, mixed> The leaf row.
	 */
	private function present(array $case, array $caseType, array $statusType): array {
		$id = (string)$case['id'];
		$assignee = (string)($case['assignee'] ?? '');

		return [
			'id' => $id,
			'identifier' => (string)($case['identifier'] ?? ''),
			'title' => (string)($case['title'] ?? ''),
			'caseTypeTitle' => $this->text->textOf(value: ($caseType['title'] ?? '')),
			'statusName' => $this->text->textOr(
				value: ($statusType['name'] ?? ''),
				fallback: $this->text->textOf(value: ($case['statusPublicLabel'] ?? ''))
			),
			'statusColour' => (string)($statusType['colour'] ?? ''),
			'isFinal' => (($case['isFinalStatus'] ?? false) === true),
			'deadline' => (string)($case['deadline'] ?? ''),
			'endDate' => (string)($case['endDate'] ?? ''),
			'assignee' => $assignee,
			'assigneeName' => $this->nameOf(uid: $assignee),
			'url' => rtrim($this->urlGenerator->linkToRoute('dossiq.dashboard.page'), '/') . '/cases/' . rawurlencode($id),
		];
	}//end present()

	/**
	 * The display name of a Nextcloud user, the uid when there is none, '' for no user.
	 *
	 * @param string $uid The user id.
	 *
	 * @return string The name.
	 */
	private function nameOf(string $uid): string {
		if ($uid === '') {
			return '';
		}

		$name = $this->userManager->getDisplayName($uid);
		if ($name === null || $name === '') {
			return $uid;
		}

		return $name;
	}//end nameOf()

	/**
	 * Read objects as the caller; a failed read is logged and reads as none.
	 *
	 * @param object               $objectService OpenRegister's object service.
	 * @param string               $register      The register.
	 * @param string               $schema        The schema slug.
	 * @param array<string, mixed> $filters       The filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function read(object $objectService, string $register, string $schema, array $filters): array {
		try {
			return $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: array_merge($filters, ['_limit' => self::MAX_ROWS])
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq case leaf: reading {schema} failed: {message}',
				['schema' => $schema, 'message' => $e->getMessage()]
			);
			return [];
		}
	}//end read()
}//end class
