<?php

/**
 * Dormant default Dossiq BRP / Haal Centraal adapter.
 *
 * Records the would-be Haal Centraal lookup to the structured logger
 * (with BSN deliberately REDACTED — AVG / WBP article 9 prohibits
 * BSN in structured logs) and returns a synthetic LOOKUP_DEFERRED
 * result so the surrounding lifecycle (citizen zaak intake,
 * briefcode resolution, register-set seed) stays observable until
 * an openconnector-backed binding to the Haal Centraal Personen-API
 * is wired in via `Application::register()`. Mirrors the
 * `LogDigidSamlAdapter` dormant-default pattern used across the
 * Dossiq external surface.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\External\Brp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/brp-kvk-register-sets/proposal.md
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\External\Brp;

use Psr\Log\LoggerInterface;

/**
 * Dormant log-backed Dossiq BRP / Haal Centraal adapter.
 *
 * @spec openspec/changes/brp-kvk-register-sets/proposal.md
 */
class LogBrpHaalCentraalAdapter implements BrpHaalCentraalAdapterInterface {
	/**
	 * Construct the log-backed BRP adapter.
	 *
	 * @param LoggerInterface $logger Structured logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Log the (BSN-REDACTED) intent + synthesise a LOOKUP_DEFERRED
	 * result.
	 *
	 * Per AVG / WBP article 9 the BSN value is NEVER passed to the
	 * structured logger; only a redaction marker + an
	 * `bsn_length_check` boolean. The `context.correlationId` is
	 * tenant-scoped + does not contain person data.
	 *
	 * This adapter never produces a person envelope, so it has nothing to
	 * map: `persoon` is empty by contract and a secrecy indication is
	 * UNKNOWN here, not false. It names the mapping a bound adapter owes in
	 * `extras.deferredFields` instead, so the contract sits where the
	 * binding is made rather than in a mapping call that can never run.
	 *
	 * @param string $bsn 9-digit Burgerservicenummer
	 *                    — never logged.
	 * @param array<string,mixed> $context Lookup context.
	 *
	 * @return BrpLookupResult The dispatch outcome.
	 *
	 * @spec openspec/specs/brp-register/spec.md#requirement-brp-person-register-schema-exists-in-openregister
	 */
	public function lookup(string $bsn, array $context = []): BrpLookupResult {
		$this->logger->info(
			'Dossiq BRP / Haal Centraal lookup deferred (no outbound connector bound)',
			[
				'bsn' => '[REDACTED]',
				'bsn_length_check' => (strlen($bsn) === 9),
				'context' => $context,
			]
		);

		return new BrpLookupResult(
			lookupStatus: 'LOOKUP_DEFERRED',
			persoon: [],
			dormant: true,
			extras: [
				'reason' => 'no-outbound-connector-bound',
				'note' => 'Bind integriq source slug `brp-haalcentraal` (PKIoverheid Services-server cert '
					. '+ Logius/RvIG autorisatieprofiel + Haal Centraal BRP Personen API endpoint) and override '
					. 'BrpHaalCentraalAdapterInterface in Application::register() to enable real lookup. NEVER log BSN values. '
					. 'A bound adapter MUST map `geheimhoudingPersoonsgegevens` onto the boolean `indicatieGeheim` the '
					. 'brpPerson schema carries (anything but 0 is true, absent is false), the way HaalCentraalBrpAdapter does.',
				'deferredFields' => ['name', 'birth', 'residence', 'indicatieGeheim'],
			],
		);
	}//end lookup()

	/**
	 * Report whether this adapter is dormant.
	 *
	 * @inheritDoc
	 *
	 * @return bool
	 *
	 * @spec openspec/specs/brp-register/spec.md#requirement-brp-person-register-schema-exists-in-openregister
	 */
	public function isDormant(): bool {
		return true;
	}//end isDormant()
}//end class
