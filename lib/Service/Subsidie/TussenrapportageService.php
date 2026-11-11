<?php

/**
 * Dossiq Tussenrapportage Service.
 *
 * Interim-report (tussenrapportage) workflow within a grant execution
 * (REQ-SUB-004). Owns auto-creation cadence (jaarlijks/halfjaarlijks),
 * the report status lifecycle, assessment deadline (termijn) binding,
 * approval — which releases conditionally dependent voorschotten — and the
 * partial-approval (gedeeltelijk_goedgekeurd) resubmission path. The
 * cadence and termijn math are pure and unit-tested; persistence delegates
 * to OpenRegister via SettingsService.
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
use OCA\Dossiq\Service\Support\CallerIdentity;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Interim-report cadence, termijn binding and approval.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/subsidieverlening-keten/specs.md
 */
class TussenrapportageService {

	use SearchesObjects;

	/**
	 * Valid report status values.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = [
		'expected',
		'submitted',
		'in_assessment',
		'approved',
		'rejected',
		'gedeeltelijk_approved',
	];

	/**
	 * Default assessment term for an interim report, in weeks.
	 */
	public const DEFAULT_TERMIJN_WEKEN = 22;

	/**
	 * Per assessment status, the operation a failed write is logged under and the sentence it refuses with.
	 *
	 * @var array<string, array{0: string, 1: string}>
	 */
	private const ASSESSMENT_FAILURES = [
		'approved' => ['approveReport', 'Kon tussenrapportage niet goedkeuren'],
		'gedeeltelijk_approved' => ['partialApprove', 'Kon tussenrapportage niet gedeeltelijk goedkeuren'],
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge.
	 * @param IUserSession $userSession Acting identity source.
	 * @param LoggerInterface $logger Logger.
	 * @param TermijnTimerService|null $timerService The engine calendar bridge; a
	 *        statutory term end lands on a day the administered calendar works.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
		private readonly ?TermijnTimerService $timerService = null,
	) {
	}//end __construct()

	/**
	 * Compute the assessment deadline for an interim report (REQ-SUB-004):
	 * the reporting period end plus the regeling-configured term.
	 *
	 * @param DateTimeImmutable $periodEnd The reporting period end.
	 * @param int $termWeken The regeling assessment term.
	 *
	 * @return DateTimeImmutable The assessment deadline, on a working day.
	 *
	 * @spec openspec/changes/every-term-on-the-engine-calendar/specs/termijnbewaking-schemas/spec.md
	 */
	public function computeBeoordelingstermijn(DateTimeImmutable $periodEnd, int $termWeken): DateTimeImmutable {
		$termWeken = max(1, $termWeken);
		$deadline = $periodEnd->add(new DateInterval('P' . ($termWeken * 7) . 'D'));

		return ($this->timerService?->rollTermEndFor(date: $deadline) ?? $deadline);
	}//end computeBeoordelingstermijn()

	/**
	 * Compute the reporting-period boundaries for a frequentie within a year
	 * (REQ-SUB-004). Returns one period per cadence step; "on_milestone" and
	 * "none" yield no automatic periods.
	 *
	 * @param string $frequency The cadence (jaarlijks/halfjaarlijks/...).
	 * @param int $year The calendar year.
	 *
	 * @return array<int, array{start: string, eind: string}> The reporting periods.
	 *
	 * @spec openspec/changes/subsidieverlening-keten/specs.md
	 */
	public function periodsForFrequentie(string $frequency, int $year): array {
		if ($frequency === 'annually') {
			return [['start' => sprintf('%d-01-01', $year), 'eind' => sprintf('%d-12-31', $year)]];
		}

		if ($frequency === 'halfjaarlijks') {
			return [
				['start' => sprintf('%d-01-01', $year), 'eind' => sprintf('%d-06-30', $year)],
				['start' => sprintf('%d-07-01', $year), 'eind' => sprintf('%d-12-31', $year)],
			];
		}

		return [];
	}//end periodsForFrequentie()

	/**
	 * Create an interim report in status "expected" (REQ-SUB-004).
	 *
	 * @param string $uitvoeringId The execution id.
	 * @param array<string, mixed> $payload The report properties.
	 *
	 * @return array<string, mixed> The created report record.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable/unconfigured.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-004-tussenrapportage-as-typed-sub-zaak
	 */
	public function createExpected(string $uitvoeringId, array $payload): array {
		$payload = array_diff_key($payload, CallerIdentity::KEYS);
		[$objectService, $register, $schema] = $this->resolve();

		$record = array_merge(
			$payload,
			[
				'subsidieuitvoering' => $uitvoeringId,
				'status' => 'expected',
				'amendmentNumerator' => 0,
			]
		);

		try {
			return ($this->saveObjectAsArray(objectService: $objectService, register: $register, schema: $schema, object: $record) ?? $record);
		} catch (Throwable $e) {
			$this->logger->error('Dossiq subsidie: createExpected tussenrapportage failed: ' . $e->getMessage());
			throw new OCSBadRequestException('Kon tussenrapportage niet aanmaken');
		}
	}//end createExpected()

	/**
	 * Approve an interim report (REQ-SUB-004). Records the assessor (from
	 * session, never the body), the assessment date, and sets the status to
	 * goedgekeurd. The caller surfaces the report id so the voorschot engine
	 * can release conditionally dependent disbursements.
	 *
	 * @param string $reportId The report id.
	 * @param string|null $beoordelingsoordeel Optional assessment narrative.
	 * @param float|null $approvedAmount Optional approved amount.
	 *
	 * @return array<string, mixed> The approved report record.
	 *
	 * @throws OCSBadRequestException When unauthenticated or persistence fails.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-004-tussenrapportage-as-typed-sub-zaak
	 */
	public function approveReport(string $reportId, ?string $beoordelingsoordeel = null, ?float $approvedAmount = null): array {
		$patch = [
			'status' => 'approved',
			'assessor' => $this->assessorId(),
			'beoordelingsdatum' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
		];
		if ($beoordelingsoordeel !== null) {
			$patch['beoordelingsoordeel'] = $beoordelingsoordeel;
		}

		if ($approvedAmount !== null) {
			$patch['approvedAmount'] = $approvedAmount;
		}

		return $this->assessed(reportId: $reportId, patch: $patch);
	}//end approveReport()

	/**
	 * Partially approve an interim report with required corrections
	 * (REQ-SUB-004), permitting resubmission and incrementing the amendment
	 * counter.
	 *
	 * @param string $reportId The report id.
	 * @param string $correctionRequest The required-corrections text.
	 * @param int $currentTeller The current amendment count.
	 *
	 * @return array<string, mixed> The updated report record.
	 *
	 * @throws OCSBadRequestException When the corrections text is empty or persistence fails.
	 *
	 * @spec openspec/specs/subsidieverlening-keten/spec.md#requirement-req-sub-004-tussenrapportage-as-typed-sub-zaak
	 */
	public function partialApprove(string $reportId, string $correctionRequest, int $currentTeller): array {
		if (trim($correctionRequest) === '') {
			throw new OCSBadRequestException('Een correctieverzoek is verplicht bij gedeeltelijke goedkeuring');
		}

		return $this->assessed(
			reportId: $reportId,
			patch: [
				'status' => 'gedeeltelijk_approved',
				'correctionRequest' => $correctionRequest,
				'amendmentNumerator' => ($currentTeller + 1),
				'assessor' => $this->assessorId(),
				'beoordelingsdatum' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
			]
		);
	}//end partialApprove()

	/**
	 * The signed-in assessor's uid, or a refusal.
	 *
	 * @return string The uid.
	 *
	 * @throws OCSBadRequestException When nobody is signed in.
	 */
	private function assessorId(): string {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSBadRequestException('Authenticatie vereist om te beoordelen');
		}

		return $user->getUID();
	}//end assessorId()

	/**
	 * Write an assessment onto a report, or refuse in the assessment's own words.
	 *
	 * @param string               $reportId The report id.
	 * @param array<string, mixed> $patch    The assessment; its `status` names the failure sentence.
	 *
	 * @return array<string, mixed> The stored report, or the patch when nothing was read back.
	 *
	 * @throws OCSBadRequestException When OpenRegister or the schema is missing, or the write fails.
	 */
	private function assessed(string $reportId, array $patch): array {
		[$objectService, $register, $schema] = $this->resolve();
		try {
			return ($this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $reportId,
				changes: $patch
			) ?? $patch);
		} catch (Throwable $e) {
			throw $this->assessmentRefusal(status: $patch['status'], e: $e);
		}
	}//end assessed()

	/**
	 * Log a failed assessment write under its operation, and the refusal in its own words.
	 *
	 * @param string    $status The assessment status written.
	 * @param Throwable $e      Why the write failed.
	 *
	 * @return OCSBadRequestException The refusal to throw.
	 */
	private function assessmentRefusal(string $status, Throwable $e): OCSBadRequestException {
		[$operation, $sentence] = self::ASSESSMENT_FAILURES[$status];
		$this->logger->error('Dossiq subsidie: ' . $operation . ' failed: ' . $e->getMessage());

		return new OCSBadRequestException($sentence);
	}//end assessmentRefusal()

	/**
	 * Resolve the ObjectService and register/schema ids.
	 *
	 * @return array{0: object, 1: string, 2: string} ObjectService, register, schema.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable or unconfigured.
	 */
	private function resolve(): array {
		$lookup = new SubsidieSchemaLookup(settingsService: $this->settingsService);
		return $lookup->resolve(schemaConfigKey: 'tussenrapportage_schema', unconfiguredMessage: 'Tussenrapportage-schema is niet geconfigureerd');
	}//end resolve()
}//end class
