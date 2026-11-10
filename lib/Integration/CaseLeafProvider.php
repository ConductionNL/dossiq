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
 * @spec openspec/specs/case-leaf/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Integration;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\LanguageMapText;
use OCP\IURLGenerator;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

/**
 * Read the cases of a party by its master entity.
 *
 * Every read goes through OpenRegister as the calling user, so a party or a
 * case the caller may not read is not returned.
 *
 * @spec openspec/specs/case-leaf/spec.md
 */
class CaseLeafProvider {

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
	 * @spec openspec/specs/case-leaf/spec.md#requirement-a-host-app-reads-a-partys-cases-by-master-entity
	 */
	public function byMasterEntity(string $ref, string $status = 'all'): array {
		if (in_array($status, self::STATUS_FILTERS, true) === false) {
			return ['status' => 400, 'error' => 'Unknown case status filter.'];
		}

		$ref = trim($ref);
		if ($ref === '') {
			return ['status' => 200, 'cases' => []];
		}

		$reader = $this->reader();
		if ($reader === null) {
			return ['status' => 503, 'error' => 'The case surface is not configured.'];
		}

		$cases = $reader->casesOf(partyIds: $reader->partyIds(ref: $ref), status: $status);

		return ['status' => 200, 'cases' => $this->rows(cases: $cases, reader: $reader)];
	}//end byMasterEntity()

	/**
	 * The reader over the configured register, or null when the surface is not configured.
	 *
	 * @return CaseLeafReader|null
	 */
	private function reader(): ?CaseLeafReader {
		$register = (string)$this->settingsService->getConfigValue('register');
		$objectService = $this->settingsService->getObjectService();
		if ($register === '' || $objectService === null) {
			return null;
		}

		return new CaseLeafReader(
			source: new CaseLeafRegister(objectService: $objectService, register: $register),
			logger: $this->logger
		);
	}//end reader()

	/**
	 * The leaf rows of the cases: open first by deadline, then closed newest first.
	 *
	 * @param array<string, array<string, mixed>> $cases  The cases by id.
	 * @param CaseLeafReader                      $reader The reader, for their types and statuses.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rows(array $cases, CaseLeafReader $reader): array {
		$types = $reader->linkedById(cases: $cases, field: 'caseType');
		$statuses = $reader->linkedById(cases: $cases, field: 'status');

		$rows = array_map(
			fn (array $case): array => $this->present(
				case: $case,
				caseType: ($types[(string)($case['caseType'] ?? '')] ?? []),
				statusType: ($statuses[(string)($case['status'] ?? '')] ?? [])
			),
			array_values($cases)
		);
		usort($rows, fn (array $left, array $right): int => $this->compare(left: $left, right: $right));

		return $rows;
	}//end rows()

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

}//end class
