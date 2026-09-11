<?php

/**
 * Live Dossiq BRP / Haal Centraal Personen adapter (external-integrations-test-environments).
 *
 * Calls the Haal Centraal BRP Personen bevragen API — the same
 * koppelvlak served offline by `ghcr.io/brp-api/personen-mock` (port
 * 5010, `/haalcentraal/api/brp/personen`) and by the official
 * proefomgeving (`https://proefomgeving.haalcentraal.nl/haalcentraal/api/brp`).
 * Selected by `integration.brp.mode` ∈ {mock, test}; the base URL and
 * X-API-KEY come from `integration.brp.baseUrl` / `integration.brp.apiKey`.
 *
 * Never logs the BSN (AVG / WBP art. 9). Any transport/HTTP failure
 * degrades to a `LOOKUP_ERROR` result (never throws into the lifecycle),
 * mirroring the dormant Log adapter's fail-soft contract.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Brp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 * @link https://github.com/BRP-API/Haal-Centraal-BRP-bevragen
 *
 * @spec openspec/specs/external-integration-test-wiring/spec.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Brp;

use OCA\Dossiq\Service\External\IntegrationMode;
use OCP\Http\Client\IClientService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Live BRP Personen bevragen adapter (mock / proefomgeving tiers).
 *
 * @spec openspec/specs/external-integration-test-wiring/spec.md
 */
class HaalCentraalBrpAdapter implements BrpHaalCentraalAdapterInterface {
	/**
	 * Default base URL — the offline docker mock's koppelvlak.
	 */
	private const DEFAULT_BASE_URL = 'http://localhost:5010/haalcentraal/api/brp';

	/**
	 * Person fields requested from the API (no more than the lifecycle needs).
	 *
	 * `geheimhoudingPersoonsgegevens` is asked for because a handler has to
	 * be told that a person's data is protected before they read the BSN;
	 * it maps to the `indicatieGeheim` flag on the mapped row.
	 *
	 * @var array<int, string>
	 */
	private const FIELDS = ['citizenServiceNumber', 'name', 'birth', 'residence', 'geheimhoudingPersoonsgegevens'];

	/**
	 * Constructor.
	 *
	 * @param IClientService $clientService HTTP client factory.
	 * @param IntegrationMode $mode Config-tier resolver.
	 * @param LoggerInterface $logger Structured logger (BSN never passed).
	 */
	public function __construct(
		private readonly IClientService $clientService,
		private readonly IntegrationMode $mode,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Look up a natural person by BSN against the configured BRP tier.
	 *
	 * @param string $bsn 9-digit Burgerservicenummer — never logged.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return BrpLookupResult
	 *
	 * @spec openspec/specs/external-integration-test-wiring/spec.md
	 */
	public function lookup(string $bsn, array $context = []): BrpLookupResult {
		$baseUrl = $this->mode->setting(integration: 'brp', key: 'baseUrl', default: self::DEFAULT_BASE_URL);
		$apiKey = $this->mode->setting(integration: 'brp', key: 'apiKey');

		$payload = [
			'type' => 'RaadpleegMetBurgerservicenummer',
			'citizenServiceNumber' => [$bsn],
			'fields' => self::FIELDS,
		];

		$headers = ['Content-Type' => 'application/json', 'Accept' => 'application/json'];
		if ($apiKey !== '') {
			$headers['X-API-KEY'] = $apiKey;
		}

		try {
			$response = $this->clientService->newClient()->post(
				rtrim($baseUrl, '/') . '/personen',
				[
					'timeout' => 10,
					'json' => $payload,
					'headers' => $headers,
				]
			);

			$data = json_decode((string)$response->getBody(), true);
			$personen = [];
			if (is_array($data) === true) {
				$personen = (array)($data['personen'] ?? []);
			}

			if ($personen === []) {
				return new BrpLookupResult(lookupStatus: 'NOT_FOUND', persoon: [], dormant: false);
			}

			$persoon = (array)$personen[0];
			// Strip the BSN back out — the caller already holds it and it
			// MUST NOT persist beyond the autorisatieprofiel-protected need.
			unset($persoon['citizenServiceNumber']);

			// Carry the secrecy indication onto the row under the name the
			// brpPerson schema uses, so one flag answers "is this person
			// protected?" everywhere downstream.
			$persoon['indicatieGeheim'] = $this->mapIndicatieGeheim(persoon: $persoon);
			unset($persoon['geheimhoudingPersoonsgegevens']);

			return new BrpLookupResult(
				lookupStatus: 'FOUND',
				persoon: $persoon,
				dormant: false,
				extras: ['tier' => $this->mode->resolve(integration: 'brp', allowed: [IntegrationMode::MOCK, IntegrationMode::TEST])]
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq BRP / Haal Centraal lookup failed',
				[
					'bsn' => '[REDACTED]',
					'error' => $e->getMessage(),
					'context' => $context,
				]
			);

			return new BrpLookupResult(
				lookupStatus: 'LOOKUP_ERROR',
				persoon: [],
				dormant: false,
				extras: ['reason' => 'transport-error']
			);
		}//end try

	}//end lookup()

	/**
	 * Map Haal Centraal `geheimhoudingPersoonsgegevens` onto the boolean
	 * `indicatieGeheim` the `brpPerson` schema carries.
	 *
	 * Haal Centraal answers with a GBA code, not a boolean: `0` means the
	 * person asked for nothing, and every other value is a form of
	 * protection. So the rule is "anything but 0 is true", and an absent
	 * field is false — a person the BRP says nothing about is not protected.
	 * Reading it as a plain truthiness test would make the string `"0"` the
	 * mock returns come out TRUE, which is the wrong way round for a flag
	 * that hides a BSN.
	 *
	 * @param array<string,mixed> $persoon The mapped person envelope.
	 *
	 * @return bool TRUE when the person's data is protected.
	 *
	 * @spec openspec/specs/brp-register/spec.md
	 */
	private function mapIndicatieGeheim(array $persoon): bool {
		if (array_key_exists('geheimhoudingPersoonsgegevens', $persoon) === false) {
			return false;
		}

		$raw = $persoon['geheimhoudingPersoonsgegevens'];
		if ($raw === null || $raw === '' || is_array($raw) === true) {
			return false;
		}

		if (is_bool($raw) === true) {
			return $raw;
		}

		return ((int)$raw !== 0);
	}//end mapIndicatieGeheim()

	/**
	 * A configured live adapter is not dormant.
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/external-integration-test-wiring/spec.md
	 */
	public function isDormant(): bool {
		return false;
	}//end isDormant()
}//end class
