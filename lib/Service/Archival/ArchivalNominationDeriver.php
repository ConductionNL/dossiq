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
 * the same case closed over the API — not a missing feature but a records
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
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Derives archiefnominatie and archiefactiedatum from a case's resultaattype.
 *
 * @spec openspec/specs/zgw-business-rules-compliance/spec.md
 */
class ArchivalNominationDeriver {

	use SearchesObjects;

	/**
	 * The two values `case.archiveNomination` accepts.
	 *
	 * The resultType's own `archivalAction` carries a third, `bewaren`, which
	 * the case schema does not declare. Writing it through unmapped — which is
	 * what the ZGW path did — stores a value no reader of the zaak can
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
	 * @param LoggerInterface $logger Records what could not be derived.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

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
		$baseDate = $this->resolveBaseDate(
			method: (string)($brondatum['derivationMethod'] ?? ($brondatum['afleidingswijze'] ?? '')),
			endDate: $endDate,
			case: $case,
			brondatum: $brondatum,
		);

		// zrc-021: a nomination with no derivable base date carries an EMPTY
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
		$context = $this->resolveContext(schemaKey: 'result_schema');
		if ($context === null || $caseId === '') {
			return null;
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $context['objectService'],
				register: $context['register'],
				schema: $context['schema'],
				filters: ['case' => $caseId, '_limit' => 1],
			);
		} catch (Throwable $e) {
			return null;
		}

		$row = ($rows[0] ?? []);
		$id = (string)($row['resultType'] ?? ($row['resultaattype'] ?? ''));

		return $this->uuidIn(value: $id);
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
	 * The base date a derivation method starts from.
	 *
	 * @param string $method The afleidingswijze.
	 * @param string $endDate The case end date.
	 * @param array<string, mixed> $case The case payload.
	 * @param array<string, mixed> $brondatum The brondatumArchiefprocedure.
	 *
	 * @return string|null The base date, or null when it cannot be resolved.
	 */
	private function resolveBaseDate(string $method, string $endDate, array $case, array $brondatum): ?string {
		switch ($method) {
			case 'afgehandeld':
			case 'handled':
			case 'termijn':
				return $endDate;
			case 'hoofdzaak':
				return ($this->parentCaseEndDate(case: $case) ?? $endDate);
			case 'eigenschap':
				$attribute = (string)($brondatum['objectAttribute'] ?? ($brondatum['datumkenmerk'] ?? ''));
				if ($attribute === '') {
					return $endDate;
				}

				return ($this->attributeDate(case: $case, attribute: $attribute) ?? $endDate);
			case 'ingangsdatum_besluit':
				return ($this->decisionDate(case: $case, fields: ['effectiveDate', 'ingangsdatum']) ?? $endDate);
			case 'vervaldatum_besluit':
				return ($this->decisionDate(case: $case, fields: ['expiryDate', 'vervaldatum']) ?? $endDate);
			default:
				// `ander_datumkenmerk` and anything unrecognised: the date comes
				// from outside the case and cannot be derived here.
				return null;
		}//end switch
	}//end resolveBaseDate()

	/**
	 * The parent case's end date, for afleidingswijze `hoofdzaak`.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return string|null The parent's end date, or null when there is none.
	 */
	private function parentCaseEndDate(array $case): ?string {
		$parentId = $this->uuidIn(
			value: (string)($case['parentCase'] ?? ($case['mainCase'] ?? ($case['hoofdzaak'] ?? '')))
		);
		if ($parentId === null) {
			return null;
		}

		$parent = $this->findRow(schemaKey: 'case_schema', id: $parentId);
		$endDate = (string)($parent['endDate'] ?? '');
		if ($endDate === '') {
			return null;
		}

		return substr($endDate, 0, 10);
	}//end parentCaseEndDate()

	/**
	 * A named case property's date value, for afleidingswijze `eigenschap`.
	 *
	 * @param array<string, mixed> $case The case payload.
	 * @param string $attribute The property name the resultType points at.
	 *
	 * @return string|null The date, or null when the property is absent or not a date.
	 */
	private function attributeDate(array $case, string $attribute): ?string {
		$context = $this->resolveContext(schemaKey: 'case_property_schema');
		$caseId = $this->caseId(case: $case);
		if ($context === null || $caseId === null) {
			return null;
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $context['objectService'],
				register: $context['register'],
				schema: $context['schema'],
				filters: ['case' => $caseId, 'name' => $attribute, '_limit' => 1],
			);
		} catch (Throwable $e) {
			return null;
		}

		return $this->asDate(value: (string)(($rows[0] ?? [])['value'] ?? ''));
	}//end attributeDate()

	/**
	 * The earliest date a case's decisions carry in one of the named fields.
	 *
	 * @param array<string, mixed> $case The case payload.
	 * @param array<int, string> $fields The decision fields to read, in order.
	 *
	 * @return string|null The date, or null when no decision carries one.
	 */
	private function decisionDate(array $case, array $fields): ?string {
		$context = $this->resolveContext(schemaKey: 'decision_schema');
		$caseId = $this->caseId(case: $case);
		if ($context === null || $caseId === null) {
			return null;
		}

		try {
			$rows = $this->searchObjectsAsArrays(
				objectService: $context['objectService'],
				register: $context['register'],
				schema: $context['schema'],
				filters: ['case' => $caseId, '_limit' => 100],
			);
		} catch (Throwable $e) {
			return null;
		}

		foreach ($rows as $row) {
			foreach ($fields as $field) {
				$date = $this->asDate(value: (string)($row[$field] ?? ''));
				if ($date !== null) {
					return $date;
				}
			}
		}

		return null;
	}//end decisionDate()

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

	/**
	 * A value that parses as a date, as Y-m-d.
	 *
	 * @param string $value The raw value.
	 *
	 * @return string|null The date, or null when the value is not one.
	 */
	private function asDate(string $value): ?string {
		if ($value === '' || strtotime($value) === false) {
			return null;
		}

		return substr($value, 0, 10);
	}//end asDate()

	/**
	 * The case's own UUID.
	 *
	 * @param array<string, mixed> $case The case payload.
	 *
	 * @return string|null The UUID, or null when the payload carries none.
	 */
	private function caseId(array $case): ?string {
		$id = (string)($case['id'] ?? ($case['@self']['id'] ?? ''));
		if ($id === '') {
			return null;
		}

		return $id;
	}//end caseId()

	/**
	 * The UUID inside a value that may be a bare id or a ZGW URL.
	 *
	 * @param string $value The raw reference.
	 *
	 * @return string|null The UUID, or null when the value holds none.
	 */
	private function uuidIn(string $value): ?string {
		$pattern = '/([0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12})/i';
		if (preg_match($pattern, $value, $matches) !== 1) {
			return null;
		}

		return $matches[1];
	}//end uuidIn()

	/**
	 * Read one row of a configured schema by id.
	 *
	 * @param string $schemaKey The app-config key naming the schema.
	 * @param string $id The row's UUID.
	 *
	 * @return array<string, mixed> The row, empty when unresolvable.
	 */
	private function findRow(string $schemaKey, string $id): array {
		$context = $this->resolveContext(schemaKey: $schemaKey);
		if ($context === null || $id === '') {
			return [];
		}

		try {
			$row = $this->findObjectAsArray(
				objectService: $context['objectService'],
				register: $context['register'],
				schema: $context['schema'],
				id: $id,
			);
		} catch (Throwable $e) {
			return [];
		}

		if (is_array($row) === false) {
			return [];
		}

		return $row;
	}//end findRow()

	/**
	 * The object service plus the register/schema pair a read needs.
	 *
	 * The schema ids come from the same app-config keys the ZGW mappings are
	 * seeded from (`LoadDefaultZgwMappings` reads `case_schema`,
	 * `result_type_schema` and the rest), so both paths read the same rows.
	 *
	 * @param string $schemaKey The app-config key naming the schema.
	 *
	 * @return array{objectService: mixed, register: string, schema: string}|null
	 *         The context, or null when OpenRegister or the schema is unconfigured.
	 */
	private function resolveContext(string $schemaKey): ?array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: $schemaKey);
		if ($register === '' || $schema === '') {
			return null;
		}

		return ['objectService' => $objectService, 'register' => $register, 'schema' => $schema];
	}//end resolveContext()
}//end class
