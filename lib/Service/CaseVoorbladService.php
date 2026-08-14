<?php

/**
 * Procest Case-Voorblad Service.
 *
 * Aggregates the KCC case-voorblad for an identified burger: open zaken (capped),
 * recent contactmomenten (capped), and a suggested dialogue topic derived from
 * the most recent case activity. All reads are scoped to the burger reference so
 * a KCC-medewerker can never pull another burger's data through this service.
 *
 * @category Service
 * @package  OCA\Procest\Service
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T10
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Builds the KCC case-voorblad for an identified burger.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T10
 */
class CaseVoorbladService {
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
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T10
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

		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$caseSchema = $this->settingsService->getConfigValue('case_schema');
		if ($register === '' || $caseSchema === '') {
			return [];
		}

		try {
			$results = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $caseSchema,
				filters: ['initiator' => $burgerId, '_limit' => 50],
			);
		} catch (Throwable $e) {
			$this->logger->error(
				'Procest: failed to fetch zaken for voorblad: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return [];
		}

		$cases = [];
		foreach ((array)$results as $result) {
			$case = $this->toArray(result: $result);
			$status = strtolower((string)($case['status'] ?? ''));
			if (in_array($status, ['afgehandeld', 'gesloten', 'afgesloten'], true) === true) {
				continue;
			}

			$cases[] = [
				'id' => (string)($case['id'] ?? ($case['uuid'] ?? '')),
				'title' => (string)($case['title'] ?? ($case['titel'] ?? '')),
				'status' => (string)($case['status'] ?? ''),
				'laatsteActie' => (string)($case['lastActionDate'] ?? ($case['updated'] ?? '')),
				'caseType' => (string)($case['caseType'] ?? ''),
			];
		}

		usort(
			$cases,
			static function (array $a, array $b): int {
				return strcmp((string)$b['laatsteActie'], (string)$a['laatsteActie']);
			}
		);

		return $cases;
	}//end fetchOpenZaken()

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
		if (is_array($result) === true) {
			return $result;
		}

		if (is_object($result) === true && method_exists($result, 'jsonSerialize') === true) {
			return (array)$result->jsonSerialize();
		}

		if (is_object($result) === true) {
			return (array)$result;
		}

		return [];
	}//end toArray()
}//end class
