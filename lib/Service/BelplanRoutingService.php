<?php

/**
 * Procest Belplan Routing Service.
 *
 * Datagedreven inbound-telephony routing: resolves the active belplan for a
 * dialed number, maps a keuzemenu selection to a vaardigheid, and picks the
 * available specialist with the shortest wachtrij. When every specialist is
 * busy beyond the configured thresholds it overflows to a generalist with an
 * escalatie-flag.
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
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use OCA\Procest\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Routes inbound calls onto available specialists per belplan.
 *
 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
 */
class BelplanRoutingService {
	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService The settings service.
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the active belplan for a dialed phone number.
	 *
	 * @param string $phoneNumber The dialed number.
	 *
	 * @return array<string, mixed>|null The belplan record, or null when none matches.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
	 */
	public function getActiveBelplan(string $phoneNumber): ?array {
		$phoneNumber = trim($phoneNumber);
		if ($phoneNumber === '') {
			return null;
		}

		foreach ($this->loadBelplannen() as $belplan) {
			if (($belplan['isActive'] ?? true) !== true) {
				continue;
			}

			$triggers = (array)($belplan['triggerNummer'] ?? []);
			if (in_array($phoneNumber, array_map('strval', $triggers), true) === true) {
				return $belplan;
			}
		}

		return null;
	}//end getActiveBelplan()

	/**
	 * Route a call to the best specialist, applying overflow rules.
	 *
	 * @param string $phoneNumber The dialed number.
	 * @param string $menuSelection The keuzemenu selection (e.g. "Omgevingsvergunningen").
	 *
	 * @return array{destinationSpecialistId: ?string, vaardigheid: string, escalatieFlag: bool, estimatedWaitTime: int, fallbackRol: ?string}
	 *
	 * @throws RuntimeException When no belplan matches the dialed number.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
	 */
	public function routeCall(string $phoneNumber, string $menuSelection): array {
		$belplan = $this->getActiveBelplan(phoneNumber: $phoneNumber);
		if ($belplan === null) {
			throw new RuntimeException('No active belplan for number');
		}

		$vaardigheid = $this->resolveVaardigheid(belplan: $belplan, menuSelection: $menuSelection);
		$specialists = $this->getSpecialistBeschikbaarheid(vaardigheid: $vaardigheid);

		$available = array_values(
			array_filter(
				$specialists,
				static function (array $specialist): bool {
					return (($specialist['status'] ?? '') === 'beschikbaar');
				}
			)
		);

		usort(
			$available,
			static function (array $a, array $b): int {
				return ((int)($a['huidigeWachtrijLengte'] ?? 0) <=> (int)($b['huidigeWachtrijLengte'] ?? 0));
			}
		);

		$overflow = $this->overflowConfig(belplan: $belplan);

		if (empty($available) === true || (int)($available[0]['huidigeWachtrijLengte'] ?? 0) > $overflow['wachtrij']) {
			// All busy or queue too long → overflow to generalist with escalatie.
			return [
				'destinationSpecialistId' => null,
				'vaardigheid' => $vaardigheid,
				'escalatieFlag' => true,
				'estimatedWaitTime' => $this->estimateWait(specialists: $specialists),
				'fallbackRol' => $overflow['fallbackRol'],
			];
		}

		$chosen = $available[0];

		return [
			'destinationSpecialistId' => (string)($chosen['medewerkerId'] ?? ''),
			'vaardigheid' => $vaardigheid,
			'escalatieFlag' => false,
			'estimatedWaitTime' => ((int)($chosen['huidigeWachtrijLengte'] ?? 0) * (int)($chosen['gemiddeldeBehandelduur'] ?? 0)),
			'fallbackRol' => null,
		];
	}//end routeCall()

	/**
	 * Fetch specialist availability records for a vaardigheid.
	 *
	 * @param string $vaardigheid The vaardigheid / expertise code, empty for all.
	 *
	 * @return array<int, array<string, mixed>> The availability records.
	 *
	 * @spec openspec/changes/kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
	 */
	public function getSpecialistBeschikbaarheid(string $vaardigheid = ''): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('specialist_beschikbaarheid_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$results = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: ['_limit' => 200]);
		} catch (Throwable $e) {
			$this->logger->error(
				'Procest: failed to fetch specialist beschikbaarheid: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return [];
		}

		$records = [];
		foreach ((array)$results as $result) {
			$record = $this->toArray(result: $result);
			if ($vaardigheid !== '') {
				$expertises = array_map('strval', (array)($record['expertises'] ?? []));
				if (in_array($vaardigheid, $expertises, true) === false) {
					continue;
				}
			}

			$records[] = $record;
		}

		return $records;
	}//end getSpecialistBeschikbaarheid()

	/**
	 * Map a keuzemenu selection onto a vaardigheid via belplan routing steps.
	 *
	 * @param array<string, mixed> $belplan The belplan record.
	 * @param string $menuSelection The menu selection.
	 *
	 * @return string The resolved vaardigheid (lowercased selection fallback).
	 */
	private function resolveVaardigheid(array $belplan, string $menuSelection): string {
		$normalized = strtolower(trim($menuSelection));

		foreach ((array)($belplan['routeringStappen'] ?? []) as $step) {
			if (($step['type'] ?? '') !== 'vaardigheid_match') {
				continue;
			}

			$map = (array)($step['zaaktype_to_vaardigheid'] ?? []);
			foreach ($map as $caseType => $vaardigheid) {
				if (strtolower((string)$caseType) === $normalized) {
					return (string)$vaardigheid;
				}
			}

			// Also allow the selection itself to be a vaardigheid value.
			if (in_array($normalized, array_map('strtolower', array_map('strval', $map)), true) === true) {
				return $normalized;
			}
		}

		return $normalized;
	}//end resolveVaardigheid()

	/**
	 * Read overflow thresholds, preferring per-belplan values then global config.
	 *
	 * @param array<string, mixed> $belplan The belplan record.
	 *
	 * @return array{wachttijd: int, wachtrij: int, fallbackRol: string}
	 */
	private function overflowConfig(array $belplan): array {
		$wachttijd = (int)$this->settingsService->getKccConfigValue('belplan_overflow_threshold_wachttijd');
		$queue = (int)$this->settingsService->getKccConfigValue('belplan_overflow_threshold_wachtrij_lengte');
		$fallbackRole = 'generalist';

		foreach ((array)($belplan['routeringStappen'] ?? []) as $step) {
			if (($step['type'] ?? '') === 'wachtrij_overflow') {
				$wachttijd = (int)($step['threshold_wachttijd_sec'] ?? $wachttijd);
				$fallbackRole = (string)($step['fallback_rol'] ?? $fallbackRole);
			}
		}

		return ['wachttijd' => $wachttijd, 'wachtrij' => $queue, 'fallbackRol' => $fallbackRole];
	}//end overflowConfig()

	/**
	 * Estimate the wait time across a set of specialists.
	 *
	 * @param array<int, array<string, mixed>> $specialists The availability records.
	 *
	 * @return int Estimated wait time in seconds.
	 */
	private function estimateWait(array $specialists): int {
		if (empty($specialists) === true) {
			return 0;
		}

		$totalQueue = 0;
		$totalDur = 0;
		foreach ($specialists as $specialist) {
			$totalQueue += (int)($specialist['huidigeWachtrijLengte'] ?? 0);
			$totalDur += (int)($specialist['gemiddeldeBehandelduur'] ?? 0);
		}

		$avgDur = (int)($totalDur / max(1, count($specialists)));
		return ($totalQueue * $avgDur);
	}//end estimateWait()

	/**
	 * Load all belplan records.
	 *
	 * @return array<int, array<string, mixed>> The belplan records.
	 */
	private function loadBelplannen(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return [];
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('belplan_schema');
		if ($register === '' || $schema === '') {
			return [];
		}

		try {
			$results = $this->searchObjectsAsArrays(objectService: $objectService, register: $register, schema: $schema, filters: ['_limit' => 200]);
		} catch (Throwable $e) {
			$this->logger->error(
				'Procest: failed to load belplannen: ' . $e->getMessage(),
				['app' => Application::APP_ID],
			);
			return [];
		}

		$records = [];
		foreach ((array)$results as $result) {
			$records[] = $this->toArray(result: $result);
		}

		return $records;
	}//end loadBelplannen()

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
