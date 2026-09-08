<?php

/**
 * The one derivation of a closed case's archival future.
 *
 * ZGW business rule zrc-021 says that when a zaak is closed with a resultaat,
 * the zaak's `archiefnominatie` and `archiefactiedatum` follow from the
 * resultaattype: the nomination is copied from it, and the action date is the
 * resultaattype's `brondatumArchiefprocedure` base date plus its
 * `archiefactietermijn`. Two hops, `case.result` to `result.resultType` to
 * `resultType.archivalPeriod`, which is why no schema calculation can express it.
 *
 * 🔴 THIS CLASS EXISTS SO THERE IS EXACTLY ONE OF IT. The rule used to live in
 * `ZrcController` as four private methods, reachable only from the ZGW API. A
 * case closed in the app therefore ended up in a different archival state from
 * the same case closed over the API, not a missing feature but a records
 * management defect, because which of the two a case went through is invisible
 * afterwards. The fix is one implementation both paths call, not a second
 * implementation that agrees today.
 *
 * What this class does NOT do: destroy anything, or decide whether a case may
 * be destroyed. Retention and destruction are OpenRegister's, declared on the
 * case schema as `x-openregister-archival` (ADR-022). This only records what
 * the resultaattype says the case is nominated for and when that falls due,
 * which is the ZGW zaak contract a consumer reads.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Archival
 *
 * @author    Conduction Development Team <dev@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Archival;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derives archiefnominatie and archiefactiedatum from a case's resultaattype.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ArchivalNominationDeriver {

	use ReadsConfiguredRows;

	/**
	 * The two values `case.archiveNomination` accepts.
	 *
	 * The resultType's own `archivalAction` carries a third, `bewaren`, which
	 * the case schema does not declare. Writing it through unmapped, which is
	 * what the ZGW path did, stores a value no reader of the zaak can
	 * interpret and that OpenRegister may reject on the enum. `bewaren` and
	 * `blijvend_bewaren` mean the same thing to an archivist, so it maps.
	 *
	 * @var array<string, string>
	 */
	private const NOMINATION_MAP = [
		'blijvend_bewaren' => 'blijvend_bewaren',
		'bewaren' => 'blijvend_bewaren',
		'vernietigen' => 'vernietigen',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister plus config.
	 * @param ArchivalBaseDateResolver $baseDates Which date the term counts from.
	 * @param LoggerInterface $logger Records what could not be derived.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly ArchivalBaseDateResolver $baseDates,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The settings bridge, for {@see ReadsConfiguredRows}.
	 *
	 * @return SettingsService The bridge to OpenRegister plus app config.
	 */
	protected function settings(): SettingsService {
		return $this->settingsService;
	}//end settings()

	/**
	 * The archival fields a closing case takes from its resultaattype.
	 *
	 * Returns only the keys it could establish, so a caller merges rather than
	 * replaces: a resultaattype that names a nomination but no derivable base
	 * date yields the nomination alone, which is the zrc-021 behaviour and is
	 * better than blanking a date somebody set by hand.
	 *
	 * Never throws. An unreadable resultaattype means the case closes with no
	 * archival claim on it, which an archivist can see and correct; refusing
	 * the close would strand the case instead.
	 *
	 * @param array<string, mixed> $case The case payload, as it will be saved.
	 * @param string $resultTypeId The chosen resultType UUID.
	 * @param string $endDate The case's end date (Y-m-d), the zrc-021 einddatum.
	 *
	 * @return array<string, string|null> `archiveNomination` and/or `archiveActionDate`.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function derive(array $case, string $resultTypeId, string $endDate): array {
		if ($resultTypeId === '' || $endDate === '') {
			return [];
		}

		$resultType = $this->findRow(schemaKey: 'result_type_schema', id: $resultTypeId);
		if ($resultType === []) {
			$this->logger->info(
				'zrc-021: no resultType row, case closes with no archival nomination',
				['resultType' => $resultTypeId]
			);
			return [];
		}

		$derived = [];
		$nomination = $this->nomination(resultType: $resultType);
		if ($nomination !== null) {
			$derived['archiveNomination'] = $nomination;
		}

		$brondatum = $this->sourceDateProcedure(resultType: $resultType);
		$baseDate = $this->baseDates->resolve(
			method: (string)($brondatum['derivationMethod'] ?? ($brondatum['afleidingswijze'] ?? '')),
			endDate: $endDate,
			case: $case,
			brondatum: $brondatum,
		);

		// Zrc-021: a nomination with no derivable base date carries an EMPTY
		// action date rather than none, so the pair is never half-written.
		if ($baseDate === null) {
			$derived['archiveActionDate'] = null;

			return $derived;
		}

		$derived['archiveActionDate'] = $this->addPeriod(
			baseDate: $baseDate,
			period: (string)($resultType['archivalPeriod'] ?? ($resultType['archiefactietermijn'] ?? '')),
		);

		$this->logger->info(
			'zrc-021: derived archival future for a closing case',
			[
				'archiveActionDate' => $derived['archiveActionDate'],
				'archiveNomination' => ($derived['archiveNomination'] ?? null),
			]
		);

		return $derived;
	}//end derive()

	/**
	 * The resultType a case's written result points at.
	 *
	 * The ZGW path reaches the case after the resultaat was POSTed and knows
	 * only the zaak, so it needs this hop; the in-app path is handed the
	 * resultType by the handler who picked it and does not.
	 *
	 * @param string $caseId Case UUID.
	 *
	 * @return string|null The resultType UUID, or null when the case has no result.
	 *
	 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
	 */
	public function resultTypeForCase(string $caseId): ?string {
		if ($caseId === '') {
			return null;
		}

		$rows = $this->findRows(schemaKey: 'result_schema', filters: ['case' => $caseId, '_limit' => 1]);
		$row = ($rows[0] ?? []);

		return $this->uuidIn(value: (string)($row['resultType'] ?? ($row['resultaattype'] ?? '')));
	}//end resultTypeForCase()

	/**
	 * The nomination the resultType declares, mapped onto the case's enum.
	 *
	 * @param array<string, mixed> $resultType The resultType row.
	 *
	 * @return string|null The nomination, or null when it declares none.
	 */
	private function nomination(array $resultType): ?string {
		$raw = (string)(
			$resultType['archivalAction']
			?? ($resultType['archiveNomination'] ?? ($resultType['archiefnominatie'] ?? ''))
		);

		return (self::NOMINATION_MAP[$raw] ?? null);
	}//end nomination()

	/**
	 * The resultType's brondatumArchiefprocedure, decoded.
	 *
	 * It is declared as a string on the schema and stored either as JSON text
	 * or as an already-decoded object, depending on which writer put it there.
	 *
	 * @param array<string, mixed> $resultType The resultType row.
	 *
	 * @return array<string, mixed> The procedure, empty when absent or unreadable.
	 */
	private function sourceDateProcedure(array $resultType): array {
		$raw = ($resultType['sourceDateArchiveProcedure'] ?? ($resultType['brondatumArchiefprocedure'] ?? null));
		if (is_string($raw) === true) {
			$raw = json_decode($raw, true);
		}

		if (is_array($raw) === false) {
			return [];
		}

		return $raw;
	}//end sourceDateProcedure()

	/**
	 * The base date plus the resultType's archival period.
	 *
	 * A period that is absent or not an ISO 8601 duration leaves the base date
	 * standing, so an unparseable term reads as "due at close" rather than as
	 * no date at all.
	 *
	 * @param string $baseDate The resolved base date (Y-m-d).
	 * @param string $period The ISO 8601 duration, e.g. `P5Y`.
	 *
	 * @return string The action date (Y-m-d).
	 */
	private function addPeriod(string $baseDate, string $period): string {
		if ($period === '') {
			return $baseDate;
		}

		try {
			return (new DateTimeImmutable($baseDate))->add(new DateInterval($period))->format('Y-m-d');
		} catch (Throwable $e) {
			$this->logger->warning(
				'zrc-021: unparseable archivalPeriod, archiveActionDate falls back to the base date',
				['archivalPeriod' => $period]
			);

			return $baseDate;
		}
	}//end addPeriod()
}//end class
