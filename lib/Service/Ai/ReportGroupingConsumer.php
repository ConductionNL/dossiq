<?php

/**
 * Asks hermiq which group an incoming report belongs to, and keeps what a
 * group means here.
 *
 * 🔴 HERMIQ ANSWERS, DOSSIQ DECIDES. hermiq scores the similarity and names the
 * group, its count and its near-duplicates. Whether two hundred reports become
 * one case with two hundred reporters or two hundred cases under a parent is a
 * question about acknowledgement duties and archiving, and it stays in dossiq.
 * This class therefore scores nothing and writes nothing: it asks and shapes.
 *
 * 🔴 A GROUP DOES NOT REDUCE THE CONFIRMATIONS OF RECEIPT OWED. Awb 4:3a owes
 * every electronic request its own confirmation. confirmationsOwed() counts the
 * reports and never reads the group, because a handler seeing one item where
 * two hundred people wrote will find it obvious that one confirmation went out.
 *
 * 🔑 UNDECLARED MEANS NO CALL. The report's text leaves the instance only when
 * its case type declares the grouping feature (REQ-AIC-01).
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Ai
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://dossiq.app
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-identical-reports-collapse-on-the-case-and-dossiq-decides-what-a-group-means-req-aic-04
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Ai;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\Assistant\HermiqAiFeatureClient;
use OCA\Dossiq\Service\Assistant\HermiqAssistantException;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Consumes hermiq's report grouping for one case.
 *
 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#requirement-identical-reports-collapse-on-the-case-and-dossiq-decides-what-a-group-means-req-aic-04
 */
class ReportGroupingConsumer {

	/**
	 * The feature slug a case type declares to have its reports grouped.
	 *
	 * The name is hermiq's own for the capability: its endpoint and its audit action
	 * are both `report-similarity`, so one feature is one thing across the two
	 * apps.
	 *
	 * @var string
	 */
	public const FEATURE_SLUG = 'report-similarity';

	/**
	 * The case property holding the group hermiq placed the case in.
	 *
	 * @var string
	 */
	public const GROUP_PROPERTY = 'reportGroupId';

	/**
	 * The longest text sent for one report.
	 *
	 * @var integer
	 */
	private const MAX_TEXT = 4000;

	/**
	 * Constructor.
	 *
	 * @param CaseTypeAiFeatures    $aiFeatures      Reads the case type's declaration.
	 * @param HermiqAiFeatureClient $client          Asks hermiq.
	 * @param CaseTypeStore         $caseTypes       Reads the case type.
	 * @param SettingsService       $settingsService Resolves the register, the case schema and the object service.
	 * @param LoggerInterface       $logger          Logger.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly CaseTypeAiFeatures $aiFeatures,
		private readonly HermiqAiFeatureClient $client,
		private readonly CaseTypeStore $caseTypes,
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Place one new case in hermiq's grouping, once, and keep the group's id on
	 * the case.
	 *
	 * 🔴 ONCE PER REPORT. hermiq's evaluation adds the report to a group, so
	 * asking again would count the same resident twice. The stored
	 * `reportGroupId` is what makes a second run a no-op.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>|null The answer, or null when the case cannot be read.
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-two-hundred-reports-read-as-one-item-with-a-count
	 */
	public function placeCase(string $caseId): ?array {
		$case = $this->readCase(caseId: $caseId);
		if ($case === null) {
			return null;
		}

		if ($this->storedGroupId(case: $case) !== '') {
			return ['declared' => true, 'available' => true, 'group' => null, 'alreadyPlaced' => true];
		}

		$answer = $this->groupFor(report: $case, caseType: $this->caseTypeOf(case: $case));
		$groupId = (string)($answer['group']['groupId'] ?? '');
		if ($groupId === '') {
			return $answer;
		}

		$case[self::GROUP_PROPERTY] = $groupId;
		$this->saveCase(case: $case);

		return $answer;
	}//end placeCase()

	/**
	 * The group one case is in, as it now stands, read as the caller.
	 *
	 * A read only: it never places the case. A case the caller cannot read
	 * answers null, exactly like one that does not exist.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>|null The answer, or null when the case cannot be read.
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-near-duplicates-are-visible-beside-the-group
	 */
	public function currentGroup(string $caseId): ?array {
		$case = $this->readCase(caseId: $caseId);
		if ($case === null) {
			return null;
		}

		if ($this->isDeclared(caseType: $this->caseTypeOf(case: $case)) === false) {
			return ['declared' => false, 'available' => false, 'group' => null];
		}

		$groupId = $this->storedGroupId(case: $case);
		if ($groupId === '') {
			return $this->unavailable(reason: 'This report has not been grouped yet.', gate: null);
		}

		try {
			$group = $this->client->group(groupId: $groupId);
		} catch (HermiqAssistantException $e) {
			return $this->unavailable(reason: $e->getMessage(), gate: $e->getErrorCode());
		}

		return ['declared' => true, 'available' => true, 'group' => $this->shape(answer: $group)];
	}//end currentGroup()

	/**
	 * Ask hermiq which group one report belongs to.
	 *
	 * Answers `declared: false` without any call when the case type does not
	 * declare the feature, and `available: false` with hermiq's reason (and its
	 * gate, when it named one) when hermiq could not or would not answer.
	 *
	 * @param array<string, mixed> $report   The report (a case record).
	 * @param array<string, mixed> $caseType Its case type.
	 *
	 * @return array<string, mixed> The answer.
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-the-near-duplicates-are-visible-beside-the-group
	 */
	public function groupFor(array $report, array $caseType): array {
		if ($this->isDeclared(caseType: $caseType) === false) {
			return ['declared' => false, 'available' => false, 'group' => null];
		}

		$reportId = trim($this->caseTypes->rowId(row: $report));
		$reportType = $this->caseTypes->rowId(row: $caseType);
		if ($reportId === '' || $reportType === '') {
			return $this->unavailable(reason: 'A grouping question needs the report and its case type.', gate: null);
		}

		try {
			$answer = $this->client->groupFor(
				reportId: $reportId,
				reportType: $reportType,
				text: $this->text(report: $report)
			);
		} catch (HermiqAssistantException $e) {
			return $this->unavailable(reason: $e->getMessage(), gate: $e->getErrorCode());
		}

		return [
			'declared' => true,
			'available' => true,
			'group' => $this->shape(answer: $answer),
		];
	}//end groupFor()

	/**
	 * The confirmations of receipt owed for a set of reports.
	 *
	 * One per report, whatever hermiq grouped them into. The group is not a
	 * parameter on purpose: there is nothing to read from it here.
	 *
	 * @param array<int, mixed> $reports The reports (records or ids).
	 *
	 * @return int The confirmations owed.
	 *
	 * @spec openspec/changes/ai-features-on-the-case-consume-hermiq/specs/ai-features-on-the-case/spec.md#scenario-grouping-does-not-reduce-the-confirmations-owed
	 */
	public function confirmationsOwed(array $reports): int {
		$seen = [];
		foreach ($reports as $report) {
			$reportId = $this->caseTypes->referenceId(value: $report);
			if ($reportId === '') {
				continue;
			}

			$seen[$reportId] = true;
		}

		return count($seen);
	}//end confirmationsOwed()

	/**
	 * Whether the case type declares grouping on any surface.
	 *
	 * @param array<string, mixed> $caseType The case type.
	 *
	 * @return bool True when declared and not switched off.
	 */
	private function isDeclared(array $caseType): bool {
		$declared = $this->aiFeatures->declared(caseType: $caseType);
		$surface = ($declared[self::FEATURE_SLUG] ?? CaseTypeAiFeatures::SURFACE_NONE);

		return $surface !== CaseTypeAiFeatures::SURFACE_NONE;
	}//end isDeclared()

	/**
	 * The text hermiq compares: the subject and the description, bounded.
	 *
	 * @param array<string, mixed> $report The report.
	 *
	 * @return string The text.
	 */
	private function text(array $report): string {
		$text = trim((string)($report['title'] ?? '') . "\n" . (string)($report['description'] ?? ''));

		return mb_substr($text, 0, self::MAX_TEXT);
	}//end text()

	/**
	 * Shape hermiq's answer for the handler: the count, the near-duplicates
	 * beside it, and the reasons.
	 *
	 * @param array<string, mixed> $answer hermiq's answer.
	 *
	 * @return array<string, mixed> The group.
	 */
	private function shape(array $answer): array {
		$nearDuplicates = [];
		foreach ((array)($answer['nearDuplicates'] ?? []) as $member) {
			if (is_array($member) === false) {
				continue;
			}

			$nearDuplicates[] = [
				'reportId' => (string)($member['reportId'] ?? ''),
				'score' => (float)($member['score'] ?? 0),
				'decidedBy' => (string)($member['decidedBy'] ?? ''),
			];
		}

		return [
			'groupId' => (string)($answer['groupId'] ?? ''),
			'count' => (int)($answer['count'] ?? count((array)($answer['members'] ?? []))),
			'nearDuplicates' => $nearDuplicates,
			'terms' => array_values(array_map('strval', (array)($answer['terms'] ?? []))),
			'windowMinutes' => (int)($answer['windowMinutes'] ?? 0),
			'newGroup' => (($answer['newGroup'] ?? false) === true),
		];
	}//end shape()

	/**
	 * An answer saying hermiq could not answer, with its reason.
	 *
	 * @param string      $reason Why.
	 * @param string|null $gate   hermiq's gate name, when it named one.
	 *
	 * @return array<string, mixed> The answer.
	 */
	private function unavailable(string $reason, ?string $gate): array {
		return [
			'declared' => true,
			'available' => false,
			'group' => null,
			'reason' => $reason,
			'gate' => $gate,
		];
	}//end unavailable()

	/**
	 * The group id already stored on a case.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return string The id, or the empty string.
	 */
	private function storedGroupId(array $case): string {
		return trim((string)($case[self::GROUP_PROPERTY] ?? ''));
	}//end storedGroupId()

	/**
	 * The case type a case points at.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, mixed> The case type, or an empty array.
	 */
	private function caseTypeOf(array $case): array {
		$caseTypeId = $this->caseTypes->referenceId(value: ($case['caseType'] ?? ''));
		if ($caseTypeId === '') {
			return [];
		}

		return $this->caseTypes->readCaseType(caseTypeId: $caseTypeId);
	}//end caseTypeOf()

	/**
	 * Write the case back with its group id.
	 *
	 * @param array<string, mixed> $case The case, carrying the group id.
	 *
	 * @return void
	 */
	private function saveCase(array $case): void {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return;
		}

		$objectService->saveObject(
			object: $case,
			register: $this->settingsService->getConfigValue(key: 'register'),
			schema: $this->settingsService->getConfigValue(key: 'case_schema')
		);
	}//end saveCase()

	/**
	 * Read one case as the caller.
	 *
	 * @param string $caseId The case uuid.
	 *
	 * @return array<string, mixed>|null The case, or null when unreadable.
	 */
	private function readCase(string $caseId): ?array {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: 'case_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return null;
		}

		try {
			$found = $objectService->find($caseId, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->info(
				'ReportGroupingConsumer: case not readable',
				['app' => Application::APP_ID, 'caseId' => $caseId, 'error' => $e->getMessage()]
			);
			return null;
		}

		if ($found === null) {
			return null;
		}

		$row = $this->caseTypes->asRow(value: $found);
		if ($row === []) {
			return null;
		}

		return $row;
	}//end readCase()
}//end class
