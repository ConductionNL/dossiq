<?php

/**
 * Dossiq Belplan Routing Service.
 *
 * Datagedreven inbound-telephony routing: resolves the active belplan for a
 * dialed number, maps a keuzemenu selection to a vaardigheid, and picks the
 * available specialist with the shortest wachtrij. When every specialist is
 * busy beyond the configured thresholds it overflows to a generalist with an
 * escalatie-flag.
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
 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\Kernel\AppIdentity;
use OCA\Dossiq\Kernel\Contracts\BelplanRoutingServiceInterface;
use OCA\Dossiq\Service\Support\CastArray;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Routes inbound calls onto available specialists per belplan.
 *
 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
 */
class BelplanRoutingService implements BelplanRoutingServiceInterface {
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
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
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

			$triggers = (array)($belplan['triggerNumber'] ?? []);
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
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
	 */
	public function routeCall(string $phoneNumber, string $menuSelection): array {
		$belplan = $this->getActiveBelplan(phoneNumber: $phoneNumber);
		if ($belplan === null) {
			throw new RuntimeException('No active belplan for number');
		}

		$vaardigheid = $this->resolveVaardigheid(belplan: $belplan, menuSelection: $menuSelection);
		$specialists = $this->getSpecialistBeschikbaarheid(vaardigheid: $vaardigheid);

		$available = self::availableByQueue(specialists: $specialists);

		$overflow = $this->overflowConfig(belplan: $belplan);

		if (empty($available) === true || (int)($available[0]['currentQueueLength'] ?? 0) > $overflow['wachtrij']) {
			// All busy or queue too long → overflow to generalist with escalatie.
			$waitTime = $this->estimateWait(specialists: $specialists);
			return self::overflowRoute(vaardigheid: $vaardigheid, waitTime: $waitTime, fallbackRol: $overflow['fallbackRol']);
		}

		return self::chosenRoute(chosen: $available[0], vaardigheid: $vaardigheid);
	}//end routeCall()

	/**
	 * The available specialists, shortest queue first.
	 *
	 * @param array<int, array<string, mixed>> $specialists The availability records.
	 *
	 * @return array<int, array<string, mixed>> The available ones, by queue length.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
	 */
	private static function availableByQueue(array $specialists): array {
		$available = array_values(array_filter($specialists, static function (array $specialist): bool {
			return (($specialist['status'] ?? '') === 'beschikbaar');
		}));

		usort(
			$available,
			static function (array $a, array $b): int {
				return ((int)($a['currentQueueLength'] ?? 0) <=> (int)($b['currentQueueLength'] ?? 0));
			}
		);

		return $available;
	}//end availableByQueue()

	/**
	 * The route of a call that overflows to a generalist, with escalatie.
	 *
	 * @param string      $vaardigheid The resolved vaardigheid.
	 * @param int         $waitTime    The estimated wait.
	 * @param string|null $fallbackRol The overflow's fallback role.
	 *
	 * @return array{destinationSpecialistId: ?string, vaardigheid: string, escalatieFlag: bool, estimatedWaitTime: int, fallbackRol: ?string}
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
	 */
	private static function overflowRoute(string $vaardigheid, int $waitTime, ?string $fallbackRol): array {
		return [
			'destinationSpecialistId' => null,
			'vaardigheid' => $vaardigheid,
			'escalatieFlag' => true,
			'estimatedWaitTime' => $waitTime,
			'fallbackRol' => $fallbackRol,
		];
	}//end overflowRoute()

	/**
	 * The route of a call to the chosen specialist.
	 *
	 * @param array<string, mixed> $chosen      The specialist with the shortest queue.
	 * @param string               $vaardigheid The resolved vaardigheid.
	 *
	 * @return array{destinationSpecialistId: ?string, vaardigheid: string, escalatieFlag: bool, estimatedWaitTime: int, fallbackRol: ?string}
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
	 */
	private static function chosenRoute(array $chosen, string $vaardigheid): array {
		return [
			'destinationSpecialistId' => (string)($chosen['employeeId'] ?? ''),
			'vaardigheid' => $vaardigheid,
			'escalatieFlag' => false,
			'estimatedWaitTime' => ((int)($chosen['currentQueueLength'] ?? 0) * (int)($chosen['averageHandlingDuration'] ?? 0)),
			'fallbackRol' => null,
		];
	}//end chosenRoute()

	/**
	 * Fetch specialist availability records for a vaardigheid.
	 *
	 * @param string $vaardigheid The vaardigheid / expertise code, empty for all.
	 *
	 * @return array<int, array<string, mixed>> The availability records.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
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
				'Dossiq: failed to fetch specialist beschikbaarheid: ' . $e->getMessage(),
				['app' => AppIdentity::APP_ID],
			);
			return [];
		}

		return $this->withExpertise(results: $results, vaardigheid: $vaardigheid);
	}//end getSpecialistBeschikbaarheid()

	/**
	 * The availability records, kept only when they list the vaardigheid (all of them for an empty one).
	 *
	 * @param mixed  $results     The search results.
	 * @param string $vaardigheid The vaardigheid / expertise code, empty for all.
	 *
	 * @return array<int, array<string, mixed>> The availability records.
	 *
	 * @spec openspec/changes/archive/2026-06-13-kcc-werkplek-zaaksysteem-bridge/tasks.md#T06
	 */
	private function withExpertise(mixed $results, string $vaardigheid): array {
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
	}//end withExpertise()

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

		foreach ((array)($belplan['routingSteps'] ?? []) as $step) {
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

		foreach ((array)($belplan['routingSteps'] ?? []) as $step) {
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
			$totalQueue += (int)($specialist['currentQueueLength'] ?? 0);
			$totalDur += (int)($specialist['averageHandlingDuration'] ?? 0);
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
				'Dossiq: failed to load belplannen: ' . $e->getMessage(),
				['app' => AppIdentity::APP_ID],
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
		return CastArray::of(value: $result);
	}//end toArray()
}//end class
