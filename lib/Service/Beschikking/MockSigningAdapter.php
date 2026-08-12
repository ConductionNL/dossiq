<?php

/**
 * Procest Mock Signing Adapter.
 *
 * Deterministic stand-in for the OpenConnector eIDAS-TSP signing flow. Used
 * until the real TSP adapter (task T23) is available. Returns stable
 * signature metadata and a fabricated validatierapport so the onderteken
 * transition and audit export are testable in isolation.
 *
 * @category Service
 * @package  OCA\Procest\Service\Beschikking
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
 * @spec openspec/changes/beschikking-generatie/tasks.md#T23
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Beschikking;

use DateTimeImmutable;

/**
 * Mock implementation of the signing adapter.
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T23
 */
class MockSigningAdapter implements SigningAdapterInterface {
	/**
	 * {@inheritDoc}
	 *
	 * @param string $bestandId The PDF file id.
	 * @param string $ondertekenaar The signer UID.
	 * @param string $tspProvider The TSP provider slug.
	 *
	 * @return array<string, string> Keys: signedBestandId, validatieRapportId, certificaatSerienummer, tspProviderEidasId, ondertekeningTijdstip.
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T23
	 */
	public function sign(string $bestandId, string $ondertekenaar, string $tspProvider): array {
		$seed = $bestandId . '|' . $ondertekenaar . '|' . $tspProvider;

		return [
			'signedBestandId' => 'signed-' . substr(hash('sha256', $seed), 0, 12),
			'validatieRapportId' => 'val-' . substr(hash('sha256', 'rapport' . $seed), 0, 12),
			'certificaatSerienummer' => '0x' . substr(hash('sha256', 'cert' . $seed), 0, 16),
			'tspProviderEidasId' => 'NL-TSP-0001',
			'ondertekeningTijdstip' => (new DateTimeImmutable())->format('c'),
		];
	}//end sign()

	/**
	 * {@inheritDoc}
	 *
	 * @param string $validatieRapportId The validatierapport id.
	 *
	 * @return array<string, mixed>
	 *
	 * @spec openspec/changes/beschikking-generatie/tasks.md#T23
	 */
	public function fetchValidationReport(string $validatieRapportId): array {
		return [
			'validatieRapportId' => $validatieRapportId,
			'soort' => 'tsp-handtekening-rapport',
			'norm' => 'ETSI EN 319 102-1',
			'geldig' => true,
			'gegenereerdOp' => (new DateTimeImmutable())->format('c'),
		];
	}//end fetchValidationReport()
}//end class
