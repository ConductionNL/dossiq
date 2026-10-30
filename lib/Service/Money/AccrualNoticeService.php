<?php

/**
 * Dossiq AccrualNoticeService.
 *
 * Registers a notice that a term lapsed and, when it is valid, opens the
 * accruing amount over it. For Awb 4:17 that is the ingebrekestelling: validates the notice
 * against the lapsed TermijnInstance, sets gevalideerd + geldigheidStatus,
 * and (on first valid notice) auto-creates a DwangsomBerekening with
 * startDatum = ontvangstDatum + 14 days grace.
 *
 * One-dwangsom guard: when TermijnInstance.relevantIngbrekes is already
 * set, subsequent notices are recorded but do NOT spawn a second
 * berekening (REQ-TERM-005).
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Money
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
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-05-ingebrekestelling/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermijnTimerService;
use OCA\Dossiq\Service\TermKind;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * AWB 4:17 ingebrekestelling registration + DwangsomBerekening creation.
 *
 * @spec openspec/changes/termijnbewaking-dwangsom-engine-05-ingebrekestelling/tasks.md
 */
class AccrualNoticeService {
	use SearchesObjects;

	public const TARIFF_AWB_PLAFOND = 144200;
	public const TARIFF_AWB_GRACE = 14;

	/**
	 * The Awb 4:17 first-band daily rate in cents, the fallback of a deviating regime without one.
	 */
	public const TARIFF_AWB_FIRST_BAND = 2300;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings service.
	 * @param TermijnService $termService TermijnService.
	 * @param LoggerInterface $logger Logger.
	 * @param TermijnTimerService|null $timerService The engine calendar bridge; the
	 *        grace period lands on a day the administered calendar works.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly TermijnService $termService,
		private readonly LoggerInterface $logger,
		private readonly ?TermijnTimerService $timerService = null,
	) {
	}//end __construct()

	/**
	 * The term instance a notice would be registered against.
	 *
	 * @param string $termInstanceId The TermijnInstance id.
	 *
	 * @return array<string, mixed>|null The instance, or null when it does not exist.
	 *
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
	 */
	public function termInstance(string $termInstanceId): ?array {
		return $this->termService->getTermijnInstance($termInstanceId);
	}//end termInstance()

	/**
	 * The statutory decision term of a case, the one a notice of default is given on.
	 *
	 * A case carries several clocks (statutory, planned, internal, phase); only
	 * the statutory term is owed to the applicant, so only it can be late in
	 * the sense of Awb 4:17. The newest statutory instance wins.
	 *
	 * @param string $caseId The case id.
	 *
	 * @return array<string, mixed>|null The instance, or null when the case has none.
	 *
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
	 */
	public function statutoryTermOf(string $caseId): ?array {
		foreach ($this->termService->instancesForCase($caseId) as $instance) {
			if (TermKind::ofInstance(instance: $instance) === TermKind::STATUTORY) {
				return $instance;
			}
		}

		return null;
	}//end statutoryTermOf()

	/**
	 * Why a notice received on this day would be premature, or '' when the term has passed.
	 *
	 * @param array<string, mixed> $instance    The term instance.
	 * @param DateTimeImmutable    $receiptDate The day the notice was received.
	 *
	 * @return string The reason, in the handler's terms, or '' when the notice may be registered.
	 *
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
	 */
	public function prematureReason(array $instance, DateTimeImmutable $receiptDate): string {
		if ($this->termHasPassed(instance: $instance, receiptDate: $receiptDate) === true) {
			return '';
		}

		$deadline = (string)($instance['endDateCurrent'] ?? '');
		if ($deadline === '') {
			return 'The decision term has no end date yet, so a notice of default cannot count.';
		}

		return 'The decision term runs until '.$deadline.'. A notice of default only counts once the term has passed (Awb 4:17).';
	}//end prematureReason()

	/**
	 * Whether the term was exceeded before the notice came in.
	 *
	 * @param array<string, mixed> $instance    The term instance.
	 * @param DateTimeImmutable    $receiptDate The day the notice was received.
	 *
	 * @return bool True when the notice is in time to count.
	 */
	private function termHasPassed(array $instance, DateTimeImmutable $receiptDate): bool {
		$status = (string)($instance['status'] ?? '');
		$deadline = (string)($instance['endDateCurrent'] ?? '');

		return ($status === 'exceeded' && $deadline !== '' && $deadline < $receiptDate->format('Y-m-d'));
	}//end termHasPassed()

	/**
	 * Register an ingebrekestelling against a TermijnInstance.
	 *
	 * @param string $termInstanceId TermijnInstance id.
	 * @param DateTimeImmutable $receiptDate Receipt date.
	 * @param string $channel Receipt channel.
	 * @param string $documentLink Document link.
	 * @param string $sender Who sent the notice; empty when not recorded.
	 *
	 * @return array<string, mixed> The ingebrekestelling row (with possibly null/created berekening).
	 *
	 * @throws RuntimeException When the instance is missing.
	 *
	 * @spec openspec/changes/termijnbewaking-dwangsom-engine-05-ingebrekestelling/tasks.md
	 * @spec openspec/changes/notice-of-default-from-the-case/specs/ingebrekestelling/spec.md#requirement-a-handler-registers-a-notice-of-default-on-the-case-req-ing-case-01
	 */
	public function registerNotice(
		string $termInstanceId,
		DateTimeImmutable $receiptDate,
		string $channel,
		string $documentLink = '',
		string $sender = '',
	): array {
		$instance = $this->termService->getTermijnInstance($termInstanceId);
		if ($instance === null) {
			throw new RuntimeException('TermijnInstance not found: ' . $termInstanceId);
		}

		$receipt = $receiptDate->format('Y-m-d');
		$isValid = $this->termHasPassed(instance: $instance, receiptDate: $receiptDate);

		$row = [
			'deadlineInstance' => $termInstanceId,
			'receiptDate' => $receipt,
			'notificationChannel' => $channel,
			'gevalideerd' => $isValid,
			'documentLink' => $documentLink,
		];
		if ($sender !== '') {
			$row['sender'] = $sender;
		}

		$row['validityStatus'] = 'premaat';
		if ($isValid === true) {
			$row['validityStatus'] = 'valid';
		}

		$saved = $this->saveSchema(schemaConfigKey: MoneyConfigKeys::NOTICE_OF_DEFAULT_SCHEMA, object: $row);
		$row['id'] = (string)($saved['id'] ?? '');

		if ($isValid === false) {
			$this->logger->info(
				'Premature ingebrekestelling rejected',
				['deadlineInstance' => $termInstanceId, 'receiptDate' => $receipt]
			);
			return $row;
		}

		// One-dwangsom guard: if an earlier valid notice already exists,
		// record the receipt but do NOT spawn a second berekening.
		$existing = (string)($instance['relevantIngbrekes'] ?? '');
		if ($existing !== '') {
			$this->logger->info(
				'Additional ingebrekestelling recorded; first remains the dwangsom basis',
				['deadlineInstance' => $termInstanceId, 'firstNotice' => $existing]
			);
			return $row;
		}

		// First valid notice: link it and start a DwangsomBerekening.
		$row['penaltyPaymentCalculation'] = $this->startPenaltyPaymentCalculation(
			termInstanceId: $termInstanceId,
			instance: $instance,
			ingebrekestellingId: (string)$row['id'],
			receiptDate: $receiptDate,
			channel: $channel,
			documentLink: $documentLink,
		);

		return $row;
	}//end registerNotice()

	/**
	 * Link the first valid notice to its instance and open the DwangsomBerekening.
	 *
	 * @param string $termInstanceId TermijnInstance id.
	 * @param array<string, mixed> $instance TermijnInstance row.
	 * @param string $ingebrekestellingId Id of the saved ingebrekestelling.
	 * @param DateTimeImmutable $receiptDate Receipt date.
	 * @param string $channel Receipt channel.
	 * @param string $documentLink Document link.
	 *
	 * @return array<string, mixed> The created DwangsomBerekening row.
	 */
	private function startPenaltyPaymentCalculation(
		string $termInstanceId,
		array $instance,
		string $ingebrekestellingId,
		DateTimeImmutable $receiptDate,
		string $channel,
		string $documentLink,
	): array {
		$this->termService->updateTermijnInstance(
			$termInstanceId,
			['relevantIngbrekes' => $ingebrekestellingId]
		);

		$regime = $this->resolveRegime(instance: $instance);

		// Awb 4:17: the grace period is a statutory term, so its end goes
		// through the calendar the organisation administers before it becomes
		// the day the dwangsom starts running. The regime's validity rules
		// above are untouched: only the day this date lands on moves.
		$graceEnd = $receiptDate->modify('+' . ((int)$regime['grace']) . ' days');
		$startAt = ($this->timerService?->rollTermEndFor(date: $graceEnd) ?? $graceEnd)->format('Y-m-d');

		$regimeLabel = 'awb-default';
		if ($regime['custom'] === true) {
			$regimeLabel = 'afwijkend';
		}

		$calculation = $this->saveSchema(
			schemaConfigKey: MoneyConfigKeys::ACCRUING_AMOUNT_SCHEMA,
			object: [
				'noticeOfDefault' => $ingebrekestellingId,
				'deadlineInstance' => $termInstanceId,
				'startDate' => $startAt,
				'plafondCalculated' => (int)$regime['plafond'],
				'status' => 'lopend',
				'regime' => $regimeLabel,
				// The day count, rate, amount and ceiling flag are stored calculations over rateBands; empty bands are the
				// Awb 4:17 bands, a deviating regime pays one flat daily rate.
				'rateBands' => $this->rateBands(regime: $regime),
			]
		);

		$this->termService->recordEvent(
			termInstanceId: $termInstanceId,
			type: 'ingebrekestelling-received',
			basis: 'AWB 4:17',
			rationale: 'Ingebrekestelling ontvangen via ' . $channel,
			daysImpact: 0,
			moment: $receiptDate,
			documentLink: $documentLink,
		);

		$this->termService->recordEvent(
			termInstanceId: $termInstanceId,
			type: 'penaltypayment-gestart',
			basis: 'AWB 4:17',
			rationale: 'Dwangsom-berekening gestart na grace period',
			daysImpact: 0,
			moment: $receiptDate,
		);

		return $calculation;
	}//end startDwangsomBerekening()

	/**
	 * The rate bands a regime accrues over: none for the Awb default, one
	 * flat band for a deviating regime (the Awb first-band rate when it names none).
	 *
	 * @param array<string, mixed> $regime The resolved regime.
	 *
	 * @return list<array{rate: int}> The bands.
	 */
	private function rateBands(array $regime): array {
		if ($regime['custom'] !== true) {
			return [];
		}

		$rate = (int)($regime['dailyTariff'] ?? 0);
		if ($rate <= 0) {
			$rate = self::TARIFF_AWB_FIRST_BAND;
		}

		return [['rate' => $rate]];
	}//end rateBands()

	/**
	 * Resolve the dwangsom regime (AWB-default or custom from definition).
	 *
	 * @param array<string, mixed> $instance TermijnInstance row.
	 *
	 * @return array{plafond:int,grace:int,custom:bool,dailyTariff?:int}
	 */
	private function resolveRegime(array $instance): array {
		$defId = (string)($instance['deadlineDefinition'] ?? '');
		if ($defId === '') {
			return ['plafond' => self::TARIFF_AWB_PLAFOND, 'grace' => self::TARIFF_AWB_GRACE, 'custom' => false];
		}

		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('termijn_definitie_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return ['plafond' => self::TARIFF_AWB_PLAFOND, 'grace' => self::TARIFF_AWB_GRACE, 'custom' => false];
		}

		try {
			$def = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $defId);
		} catch (\Throwable $e) {
			return ['plafond' => self::TARIFF_AWB_PLAFOND, 'grace' => self::TARIFF_AWB_GRACE, 'custom' => false];
		}

		if ($def === null) {
			return ['plafond' => self::TARIFF_AWB_PLAFOND, 'grace' => self::TARIFF_AWB_GRACE, 'custom' => false];
		}

		$regime = $def['deviatingPenaltyPaymentRegime'] ?? null;
		if (is_array($regime) === false) {
			return ['plafond' => self::TARIFF_AWB_PLAFOND, 'grace' => self::TARIFF_AWB_GRACE, 'custom' => false];
		}

		return [
			'plafond' => (int)($regime['plafond'] ?? self::TARIFF_AWB_PLAFOND),
			'grace' => (int)($regime['grace'] ?? self::TARIFF_AWB_GRACE),
			'dailyTariff' => (int)($regime['dailyTariff'] ?? 0),
			'custom' => true,
		];
	}//end resolveRegime()

	/**
	 * Save to a configured schema.
	 *
	 * @param string $schemaConfigKey Config key.
	 * @param array<string, mixed> $object Payload.
	 *
	 * @return array<string, mixed>
	 */
	private function saveSchema(string $schemaConfigKey, array $object): array {
		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue($schemaConfigKey);
		if ($objectService === null || $register === '' || $schema === '') {
			return $object;
		}

		try {
			$saved = $this->saveObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				object: $object
			);
			return ($saved ?? $object);
		} catch (\Throwable $e) {
			$this->logger->error(
				'AccrualNoticeService persist failed',
				['schemaConfigKey' => $schemaConfigKey, 'error' => $e->getMessage()]
			);
			return $object;
		}
	}//end saveSchema()
}//end class
