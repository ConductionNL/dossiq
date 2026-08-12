<?php

/**
 * Procest Tussenrapportage Service.
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
 * @package  OCA\Procest\Service\Subsidie
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
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Subsidie;

use DateInterval;
use DateTimeImmutable;
use OCA\Procest\Service\SettingsService;
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
	/**
	 * Valid report status values.
	 *
	 * @var array<int, string>
	 */
	public const STATUSES = [
		'verwacht',
		'ingediend',
		'in_beoordeling',
		'goedgekeurd',
		'afgekeurd',
		'gedeeltelijk_goedgekeurd',
	];

	/**
	 * Default assessment term for an interim report, in weeks.
	 */
	public const DEFAULT_TERMIJN_WEKEN = 22;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Schema/register bridge.
	 * @param IUserSession $userSession Acting identity source.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IUserSession $userSession,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Compute the assessment deadline for an interim report (REQ-SUB-004):
	 * the reporting period end plus the regeling-configured term.
	 *
	 * @param DateTimeImmutable $periodeEind The reporting period end.
	 * @param int $termijnWeken The regeling assessment term.
	 *
	 * @return DateTimeImmutable The assessment deadline.
	 */
	public function computeBeoordelingstermijn(DateTimeImmutable $periodeEind, int $termijnWeken): DateTimeImmutable {
		$termijnWeken = max(1, $termijnWeken);
		return $periodeEind->add(new DateInterval('P' . ($termijnWeken * 7) . 'D'));
	}//end computeBeoordelingstermijn()

	/**
	 * Compute the reporting-period boundaries for a frequentie within a year
	 * (REQ-SUB-004). Returns one period per cadence step; "op_mijlpaal" and
	 * "geen" yield no automatic periods.
	 *
	 * @param string $frequentie The cadence (jaarlijks/halfjaarlijks/...).
	 * @param int $year The calendar year.
	 *
	 * @return array<int, array{start: string, eind: string}> The reporting periods.
	 */
	public function periodsForFrequentie(string $frequentie, int $year): array {
		if ($frequentie === 'jaarlijks') {
			return [['start' => sprintf('%d-01-01', $year), 'eind' => sprintf('%d-12-31', $year)]];
		}

		if ($frequentie === 'halfjaarlijks') {
			return [
				['start' => sprintf('%d-01-01', $year), 'eind' => sprintf('%d-06-30', $year)],
				['start' => sprintf('%d-07-01', $year), 'eind' => sprintf('%d-12-31', $year)],
			];
		}

		return [];
	}//end periodsForFrequentie()

	/**
	 * Create an interim report in status "verwacht" (REQ-SUB-004).
	 *
	 * @param string $uitvoeringId The execution id.
	 * @param array<string, mixed> $payload The report properties.
	 *
	 * @return array<string, mixed> The created report record.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable/unconfigured.
	 */
	public function createExpected(string $uitvoeringId, array $payload): array {
		[$objectService, $register, $schema] = $this->resolve();

		$record = array_merge(
			$payload,
			[
				'subsidieuitvoering' => $uitvoeringId,
				'status' => 'verwacht',
				'amendementTeller' => 0,
			]
		);

		try {
			return $objectService->saveObject(object: $record, register: $register, schema: $schema);
		} catch (Throwable $e) {
			$this->logger->error('Procest subsidie: createExpected tussenrapportage failed: ' . $e->getMessage());
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
	 * @param float|null $ingekeurdeBedrag Optional approved amount.
	 *
	 * @return array<string, mixed> The approved report record.
	 *
	 * @throws OCSBadRequestException When unauthenticated or persistence fails.
	 */
	public function approveReport(string $reportId, ?string $beoordelingsoordeel = null, ?float $ingekeurdeBedrag = null): array {
		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSBadRequestException('Authenticatie vereist om te beoordelen');
		}

		[$objectService, $register, $schema] = $this->resolve();

		$patch = [
			'status' => 'goedgekeurd',
			'beoordelaar' => $user->getUID(),
			'beoordelingsdatum' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
		];
		if ($beoordelingsoordeel !== null) {
			$patch['beoordelingsoordeel'] = $beoordelingsoordeel;
		}

		if ($ingekeurdeBedrag !== null) {
			$patch['ingekeurdeBedrag'] = $ingekeurdeBedrag;
		}

		try {
			return $objectService->saveObject(object: $patch, register: $register, schema: $schema, uuid: (string)$reportId);
		} catch (Throwable $e) {
			$this->logger->error('Procest subsidie: approveReport failed: ' . $e->getMessage());
			throw new OCSBadRequestException('Kon tussenrapportage niet goedkeuren');
		}
	}//end approveReport()

	/**
	 * Partially approve an interim report with required corrections
	 * (REQ-SUB-004), permitting resubmission and incrementing the amendment
	 * counter.
	 *
	 * @param string $reportId The report id.
	 * @param string $correctieverzoek The required-corrections text.
	 * @param int $huidigeTeller The current amendment count.
	 *
	 * @return array<string, mixed> The updated report record.
	 *
	 * @throws OCSBadRequestException When the corrections text is empty or persistence fails.
	 */
	public function partialApprove(string $reportId, string $correctieverzoek, int $huidigeTeller): array {
		if (trim($correctieverzoek) === '') {
			throw new OCSBadRequestException('Een correctieverzoek is verplicht bij gedeeltelijke goedkeuring');
		}

		$user = $this->userSession->getUser();
		if ($user === null) {
			throw new OCSBadRequestException('Authenticatie vereist om te beoordelen');
		}

		[$objectService, $register, $schema] = $this->resolve();

		$patch = [
			'status' => 'gedeeltelijk_goedgekeurd',
			'correctieverzoek' => $correctieverzoek,
			'amendementTeller' => ($huidigeTeller + 1),
			'beoordelaar' => $user->getUID(),
			'beoordelingsdatum' => (new DateTimeImmutable())->format(DateTimeImmutable::ATOM),
		];

		try {
			return $objectService->saveObject(object: $patch, register: $register, schema: $schema, uuid: (string)$reportId);
		} catch (Throwable $e) {
			$this->logger->error('Procest subsidie: partialApprove failed: ' . $e->getMessage());
			throw new OCSBadRequestException('Kon tussenrapportage niet gedeeltelijk goedkeuren');
		}
	}//end partialApprove()

	/**
	 * Resolve the ObjectService and register/schema ids.
	 *
	 * @return array{0: object, 1: string, 2: string} ObjectService, register, schema.
	 *
	 * @throws OCSBadRequestException When OpenRegister is unavailable or unconfigured.
	 */
	private function resolve(): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new OCSBadRequestException('OpenRegister is niet beschikbaar');
		}

		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('tussenrapportage_schema');
		if ($register === '' || $schema === '') {
			throw new OCSBadRequestException('Tussenrapportage-schema is niet geconfigureerd');
		}

		return [$objectService, $register, $schema];
	}//end resolve()
}//end class
