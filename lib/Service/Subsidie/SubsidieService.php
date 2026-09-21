<?php

/**
 * Dossiq Subsidie Service.
 *
 * Core domain service for the subsidieverlening-keten — the end-to-end
 * grant lifecycle under AWB titel 4.2. Owns subsidieaanvraag CRUD, the
 * aanvraag status machine, beschikkingnummer generation, voorschot-schema
 * validation (REQ-SUB-001), AWB termijn binding (REQ-SUB-002) and
 * verplichting tracking (REQ-SUB-003). Every persistence call goes through
 * OpenRegister via SettingsService::getObjectService() using the real API
 * (find/findAll/saveObject) — never bespoke CRUD.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Subsidie
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
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Subsidie;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\OCS\OCSBadRequestException;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Core subsidy lifecycle service.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/subsidieverlening-keten/specs.md
 */
class SubsidieService {

	use SearchesObjects;

	/**
	 * Canonical aanvraag status values.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = [
		'received',
		'in_assessment',
		'assessed',
		'decision_prepared',
		'granted',
		'rejected',
		'withdrawn',
	];

	/**
	 * Allowed aanvraag status transitions (from => [to, ...]).
	 *
	 * @var array<string, array<int, string>>
	 */
	public const TRANSITIONS = [
		'received' => ['in_assessment', 'withdrawn'],
		'in_assessment' => ['assessed', 'rejected', 'withdrawn'],
		'assessed' => ['decision_prepared', 'rejected', 'withdrawn'],
		'decision_prepared' => ['granted', 'rejected', 'withdrawn'],
		'granted' => ['withdrawn'],
		'rejected' => [],
		'withdrawn' => [],
	];

	/**
	 * Default AWB 4:13 decision term in weeks when the regeling is silent.
	 */
	public const DEFAULT_AANVRAAG_TERMIJN_WEKEN = 13;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge.
	 * @param LoggerInterface $logger Logger.
	 * @param TermijnTimerService|null $timerService The engine calendar bridge; a
	 *        statutory term end lands on a day the administered calendar works.
	 * @param CofinancieringValidator $cofinanciering Whether the budget adds up
	 *        (REQ-SUB-008). Defaulted rather than required: this service is
	 *        constructed directly in several suites, and a required argument
	 *        would make wiring the validator a test-rewriting exercise instead
	 *        of a wiring one.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly ?TermijnTimerService $timerService = null,
		private readonly CofinancieringValidator $cofinanciering = new CofinancieringValidator(),
	) {
	}//end __construct()

	/**
	 * Whether a status transition is permitted by the aanvraag state machine.
	 *
	 * @param string $from Current status.
	 * @param string $to Target status.
	 *
	 * @return bool True when the transition is allowed.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function isTransitionAllowed(string $from, string $to): bool {
		$allowed = self::TRANSITIONS[$from] ?? null;
		if ($allowed === null) {
			return false;
		}

		return in_array($to, $allowed, true);
	}//end isTransitionAllowed()

	/**
	 * Generate a deterministic beschikkingnummer (SUB-YYYY-NNNNNN).
	 *
	 * @param int $sequence The running sequence number.
	 * @param DateTimeImmutable|null $now Clock injection for tests.
	 *
	 * @return string The formatted beschikkingnummer.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function generateBeschikkingnummer(int $sequence, ?DateTimeImmutable $now = null): string {
		$now = ($now ?? new DateTimeImmutable());
		$year = $now->format('Y');

		return sprintf('SUB-%s-%06d', $year, max(1, $sequence));
	}//end generateBeschikkingnummer()

	/**
	 * Compute the AWB decision deadline for an aanvraag.
	 *
	 * @param DateTimeImmutable $registration The registration date.
	 * @param int $weken The regeling term in weeks.
	 * @param array<string, mixed> $definitie The term definition, when one is known.
	 *
	 * @return DateTimeImmutable The decision deadline, on a working day.
	 *
	 * @spec openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
	 */
	public function computeBeslistermijn(DateTimeImmutable $registration, int $weken, array $definitie = []): DateTimeImmutable {
		$weken = max(1, $weken);
		$deadline = $registration->add(new DateInterval('P' . ($weken * 7) . 'D'));

		return ($this->timerService?->rollTermEndFor(date: $deadline, definitie: $definitie) ?? $deadline);
	}//end computeBeslistermijn()

	/**
	 * Validate that a voorschot-schema sums to the verleend bedrag
	 * (REQ-SUB-001). Tolerates sub-cent floating-point drift.
	 *
	 * @param array<int, array<string, mixed>> $advanceSchema Disbursement rows.
	 * @param float $grantedAmount The granted amount.
	 *
	 * @return bool True when the schedule reconciles to the granted amount.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function voorschotSchemaReconciles(array $advanceSchema, float $grantedAmount): bool {
		$sum = 0.0;
		foreach ($advanceSchema as $voorschot) {
			$sum += (float)($voorschot['amount'] ?? 0);
		}

		return abs($sum - $grantedAmount) < 0.01;
	}//end voorschotSchemaReconciles()

	/**
	 * Decide whether a conditional voorschot may be released (REQ-SUB-001).
	 *
	 * A voorschot with no voorwaarde is unconditional. A voorwaarde of the
	 * form "tussenrapportage:{id}" requires that id to appear in the set of
	 * approved tussenrapportage ids.
	 *
	 * @param array<string, mixed> $voorschot The disbursement row.
	 * @param array<int, string> $approvedReports Approved tussenrapportage ids.
	 *
	 * @return bool True when the voorschot is releasable.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function isVoorschotReleasable(array $voorschot, array $approvedReports): bool {
		$voorwaarde = trim((string)($voorschot['voorwaarde'] ?? ''));
		if ($voorwaarde === '' || $voorwaarde === 'unconditional') {
			return true;
		}

		if (str_starts_with($voorwaarde, 'tussenrapportage:') === true) {
			$required = substr($voorwaarde, strlen('tussenrapportage:'));
			return in_array($required, $approvedReports, true);
		}

		// Unknown condition shapes fail closed — never auto-release.
		return false;
	}//end isVoorschotReleasable()

	/**
	 * Identify verplichtingen that are not yet voldaan (REQ-SUB-003). These
	 * become korting-grounds at vaststelling.
	 *
	 * @param array<int, array<string, mixed>> $verplichtingen Condition rows.
	 *
	 * @return array<int, array<string, mixed>> The unmet conditions.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function unmetVerplichtingen(array $verplichtingen): array {
		$unmet = [];
		foreach ($verplichtingen as $commitment) {
			$status = (string)($commitment['status'] ?? 'open');
			if ($status !== 'voldaan') {
				$unmet[] = $commitment;
			}
		}

		return $unmet;
	}//end unmetVerplichtingen()

	/**
	 * Create a subsidieaanvraag in status "received", binding the AWB
	 * decision term (REQ-SUB-002).
	 *
	 * @param array<string, mixed> $payload The aanvraag properties.
	 * @param int $termWeken The regeling decision term.
	 *
	 * @return array<string, mixed> The created aanvraag record.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable/unconfigured.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-002-awb-termijn-binding-for-each-phase
	 */
	public function createAanvraag(array $payload, int $termWeken = self::DEFAULT_AANVRAAG_TERMIJN_WEKEN): array {
		[$objectService, $register, $schema] = $this->resolve(schemaConfigKey: 'subsidie_aanvraag_schema');

		if (((string)($payload['subsidyScheme'] ?? '')) === '') {
			throw new OCSBadRequestException('subsidieregeling is verplicht');
		}

		$this->assertCofinancieringReconciles(payload: $payload);

		$now = new DateTimeImmutable();
		$record = array_merge(
			$payload,
			[
				'status' => 'received',
				'beslistermijn' => $this->computeBeslistermijn(registration: $now, weken: $termWeken)->format('Y-m-d'),
			]
		);
		// The aanvrager BSN is special-category data and is never persisted raw.
		if (isset($record['applicantBsnRef']) === true) {
			$record['applicantBsnRef'] = $this->maskBsn(bsn: (string)$record['applicantBsnRef']);
		}

		try {
			return ($this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $schema, object: $record) ?? $record);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq subsidie: createAanvraag failed: ' . $e->getMessage());
			throw new OCSBadRequestException('Kon subsidieaanvraag niet aanmaken');
		}
	}//end createAanvraag()

	/**
	 * Refuse an application whose co-financing does not add up.
	 *
	 * `CofinancieringValidator` has answered this since the subsidy chain
	 * shipped and nothing asked it, so a budget that did not reconcile was
	 * accepted at intake and discovered, if at all, at the beschikking, by
	 * which point a decision term has been running for weeks.
	 *
	 * 🔴 IT ONLY FIRES ON AN APPLICATION THAT DECLARED BOTH HALVES. An
	 * application with no `coFinancingList`, or no project total, is passed
	 * through untouched: those are the applications every caller sends today,
	 * and refusing them would be this wiring inventing a requirement rather
	 * than enforcing one.
	 *
	 * The error code is the validator's own (`COFIN_SUM_MISMATCH`,
	 * `COFIN_PROJECT_TOTAL_INVALID`) and travels in the message, because an
	 * applicant told only "that did not work" cannot tell a typo in the project
	 * total from a missing contribution.
	 *
	 * @param array<string, mixed> $payload The application as submitted.
	 *
	 * @return void
	 *
	 * @throws OCSBadRequestException When the declared budget does not reconcile.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md
	 */
	private function assertCofinancieringReconciles(array $payload): void {
		$rows = $this->rowsOf(value: ($payload['coFinancingList'] ?? null));
		if ($rows === []) {
			return;
		}

		// 🔴 `budget` IS NOT A NUMBER. The schema declares it as a JSON array of
		// cost items, `[{kostenpost, bedrag, eenheid}]`, so casting it to float
		// yields 0 and this guard would return early on every real application:
		// wired that way it would have looked wired and done nothing, which is
		// the failure this whole sweep is about. The project total is the sum of
		// the cost items.
		$projectTotal = $this->cofinanciering->sumBedragen(rows: $this->rowsOf(value: ($payload['budget'] ?? null)));
		if ($projectTotal <= 0.0) {
			return;
		}

		$verdict = $this->cofinanciering->validate(
			subsidyAmount: (float)($payload['requestedAmount'] ?? 0),
			cofinanciering: array_values($rows),
			projectTotal: $projectTotal,
		);

		if ($verdict['valid'] === false) {
			throw new OCSBadRequestException(
				'De cofinanciering sluit niet aan op het projecttotaal (' . (string)$verdict['error'] . ')'
			);
		}
	}//end assertCofinancieringReconciles()

	/**
	 * One declared list as rows, however it was stored.
	 *
	 * Both `coFinancingList` and `budget` are declared as JSON STRINGS holding
	 * an array, so a caller may hand over either the string or the decoded
	 * array. Reading only one shape is how a guard ends up looking wired and
	 * doing nothing.
	 *
	 * @param mixed $value The stored value.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 */
	private function rowsOf(mixed $value): array {
		if (is_string($value) === true) {
			$value = json_decode($value, true);
		}

		if (is_array($value) === false) {
			return [];
		}

		$rows = [];
		foreach ($value as $row) {
			if (is_array($row) === true) {
				$rows[] = $row;
			}
		}

		return $rows;
	}//end rowsOf()

	/**
	 * Transition an aanvraag to a new status, enforcing the state machine.
	 *
	 * @param string $id The aanvraag id.
	 * @param string $toStatus The target status.
	 *
	 * @return array<string, mixed> The updated aanvraag record.
	 *
	 * @throws OCSBadRequestException When the transition is illegal or persistence fails.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-002-awb-termijn-binding-for-each-phase
	 */
	public function transitionAanvraag(string $id, string $toStatus): array {
		[$objectService, $register, $schema] = $this->resolve(schemaConfigKey: 'subsidie_aanvraag_schema');

		if (in_array($toStatus, self::STATUSES, true) === false) {
			throw new OCSBadRequestException('Onbekende status: ' . $toStatus);
		}

		$current = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: (string)$id);
		if ($current === null) {
			throw new OCSBadRequestException('Subsidieaanvraag niet gevonden');
		}

		$from = (string)($current['status'] ?? 'received');
		if ($this->isTransitionAllowed(from: $from, to: $toStatus) === false) {
			throw new OCSBadRequestException('Statusovergang ' . $from . ' -> ' . $toStatus . ' is niet toegestaan');
		}

		try {
			return ($this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: (string)$id,
				changes: ['status' => $toStatus]
			) ?? array_merge($current, ['status' => $toStatus]));
		} catch (Throwable $e) {
			$this->logger->error('Dossiq subsidie: transitionAanvraag failed: ' . $e->getMessage());
			throw new OCSBadRequestException('Kon status niet bijwerken');
		}
	}//end transitionAanvraag()

	/**
	 * List subsidieaanvragen, optionally filtered.
	 *
	 * @param array<string, mixed> $filters Optional status/regeling/handler filters.
	 *
	 * @return array<int, array<string, mixed>> The aanvragen.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable/unconfigured.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-002-awb-termijn-binding-for-each-phase
	 */
	public function listAanvragen(array $filters = []): array {
		[$objectService, $register, $schema] = $this->resolve(schemaConfigKey: 'subsidie_aanvraag_schema');

		$query = ['register' => (int)$register, 'schema' => (int)$schema];
		foreach (['status', 'subsidyScheme', 'handler'] as $field) {
			if (isset($filters[$field]) === true && $filters[$field] !== '') {
				$query[$field] = (string)$filters[$field];
			}
		}

		return $objectService->findAll(['filters' => $query]);
	}//end listAanvragen()

	/**
	 * Mask a BSN, keeping only the trailing three digits for audit linkage.
	 *
	 * @param string $bsn The raw BSN.
	 *
	 * @return string The masked reference.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-002-awb-termijn-binding-for-each-phase
	 */
	public function maskBsn(string $bsn): string {
		$digits = preg_replace('/\D/', '', $bsn);
		if ($digits === null || strlen($digits) < 3) {
			return '***';
		}

		return str_repeat('*', (strlen($digits) - 3)) . substr($digits, -3);
	}//end maskBsn()

	/**
	 * Resolve the ObjectService and register/schema ids for a config key.
	 *
	 * @param string $schemaConfigKey The schema config key.
	 *
	 * @return array{0: object, 1: string, 2: string} ObjectService, register, schema.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable or unconfigured.
	 */
	private function resolve(string $schemaConfigKey): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new OCSBadRequestException('OpenRegister is niet beschikbaar');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue($schemaConfigKey);
		if ($register === '' || $schema === '') {
			throw new OCSBadRequestException('Subsidie-schema is niet geconfigureerd');
		}

		return [$objectService, $register, $schema];
	}//end resolve()
}//end class
