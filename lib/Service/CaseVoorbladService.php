<?php

/**
 * Dossiq Case-Voorblad Service.
 *
 * Aggregates the KCC case-voorblad for an identified burger: open zaken (capped),
 * recent contactmomenten (capped), and a suggested dialogue topic derived from
 * the most recent case activity. All reads are scoped to the burger reference so
 * a KCC-medewerker can never pull another burger's data through this service.
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
 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T10
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\CaseVoorbladServiceInterface;
use OCA\Dossiq\Service\Support\CastArray;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the KCC case-voorblad for an identified burger.
 *
 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T10
 */
class CaseVoorbladService implements CaseVoorbladServiceInterface {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service.
	 * @param ContactMomentService $contactMomentService The contactmoment service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ContactMomentService $contactMomentService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Build the case-voorblad for an identified burger.
	 *
	 * @param string $burgerId The identified burger reference.
	 *
	 * @return array{burgerId: string, openZaken: array<int, mixed>, recenteContactmomenten: array<int, mixed>, suggestedTopic: string}
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T10
	 */
	public function getCaseVoorblad(string $burgerId): array {
		$maxCases = max(1, (int)$this->settingsService->getKccConfigValue('max_zaken_voorblad'));
		$maxContactmomenten = max(1, (int)$this->settingsService->getKccConfigValue('max_contactmomenten_history'));

		$openCases = array_slice($this->fetchOpenCases(burgerId: $burgerId), 0, $maxCases);
		$contactmomenten = array_slice(
			$this->contactMomentService->listForBurger($burgerId, $maxContactmomenten),
			0,
			$maxContactmomenten,
		);

		return [
			'burgerId' => $burgerId,
			'openZaken' => $openCases,
			'recenteContactmomenten' => $contactmomenten,
			'suggestedTopic' => $this->suggestTopic(openCases: $openCases),
		];
	}//end getCaseVoorblad()

	/**
	 * Fetch open zaken for a burger reference.
	 *
	 * @param string $burgerId The burger reference.
	 *
	 * @return array<int, array<string, mixed>> The open case summaries.
	 */
	private function fetchOpenCases(string $burgerId): array {
		if ($burgerId === '') {
			return [];
		}

		$scope = $this->caseSearchScope();
		if ($scope === null) {
			return [];
		}

		try {
			$results = $this->searchCasesOf(scope: $scope, burgerId: $burgerId);
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: failed to fetch zaken for voorblad: ' . $e->getMessage(),
				['app' => AppIdentity::APP_ID],
			);
			return [];
		}

		return $this->openCaseSummaries(results: (array)$results);
	}//end fetchOpenZaken()

	/**
	 * The object service, register and case schema to search, or null when
	 * OpenRegister or either setting is missing.
	 *
	 * @return array{0: object, 1: string, 2: string}|null
	 */
	private function caseSearchScope(): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		if ($register === '' || $caseSchema === '') {
			return null;
		}

		return [$objectService, $register, $caseSchema];
	}//end caseSearchScope()

	/**
	 * The cases a burger initiated, at most fifty.
	 *
	 * @param array{0: object, 1: string, 2: string} $scope    The object service, register and case schema.
	 * @param string                                 $burgerId The burger reference.
	 *
	 * @return array<int, mixed>
	 */
	private function searchCasesOf(array $scope, string $burgerId): array {
		return $this->searchObjectsAsArrays(
			objectService: $scope[0],
			register: $scope[1],
			schema: $scope[2],
			filters: ['initiator' => $burgerId, '_limit' => 50],
		);
	}//end searchCasesOf()

	/**
	 * The open cases among the results, summarised, newest action first.
	 *
	 * @param array<int, mixed> $results The search results.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	private function openCaseSummaries(array $results): array {
		$cases = [];
		foreach ($results as $result) {
			$case = $this->toArray(result: $result);
			if ($this->isClosed(case: $case) === false) {
				$cases[] = $this->caseSummary(case: $case);
			}
		}

		usort(
			$cases,
			static function (array $a, array $b): int {
				return strcmp((string)$b['laatsteActie'], (string)$a['laatsteActie']);
			}
		);

		return $cases;
	}//end openCaseSummaries()

	/**
	 * Whether a case is handled or closed.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return bool
	 */
	private function isClosed(array $case): bool {
		$status = strtolower((string)($case['status'] ?? ''));

		return in_array($status, ['handled', 'gesloten', 'closed'], true);
	}//end isClosed()

	/**
	 * One open case as the cover sheet shows it.
	 *
	 * @param array<string, mixed> $case The case.
	 *
	 * @return array<string, string>
	 */
	private function caseSummary(array $case): array {
		return [
			'id' => (string)($case['id'] ?? ($case['uuid'] ?? '')),
			'title' => (string)($case['title'] ?? ($case['titel'] ?? '')),
			'status' => (string)($case['status'] ?? ''),
			'laatsteActie' => (string)($case['lastActionDate'] ?? ($case['updated'] ?? '')),
			'caseType' => (string)($case['caseType'] ?? ''),
		];
	}//end caseSummary()

	/**
	 * Suggest a likely dialogue topic from the most recent open case.
	 *
	 * @param array<int, array<string, mixed>> $openCases The open case summaries.
	 *
	 * @return string A short human-readable topic suggestion.
	 */
	private function suggestTopic(array $openCases): string {
		if (empty($openCases) === true) {
			return '';
		}

		$first = $openCases[0];
		$title = trim((string)($first['title'] ?? ''));
		if ($title === '') {
			$title = trim((string)($first['caseType'] ?? 'lopende zaak'));
		}

		return 'Waarschijnlijk statusvraag over ' . $title;
	}//end suggestTopic()

	/**
	 * Normalise an ObjectService result into a plain array.
	 *
	 * @param mixed $result The ObjectService result.
	 *
	 * @return array<string, mixed> The normalised record.
	 */
	private function toArray($result): array {
		return CastArray::of(value: $result);
	}//end toArray()
}//end class
