<?php

/**
 * Dossiq consultation terms.
 *
 * Reads the Adviseringsbeheer settings an administrator saves on the admin
 * page and applies them: the default term of an advice request, the warning
 * offset before its deadline, the address an external advisory body answers
 * at, and the late share above which an advisory body is a bottleneck.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Consultation
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/specs/consultation-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Consultation;

use DateTimeImmutable;
use OCA\Dossiq\Service\SettingsService;

/**
 * The consultation settings, read and applied.
 *
 * @spec openspec/specs/consultation-management/spec.md
 */
class ConsultationTerms {

	/**
	 * The statuses of an advice request that still waits for its answer.
	 *
	 * @var array<int, string>
	 */
	public const WAITING = ['open', 'received', 'in_handling'];

	/**
	 * The statuses of an answered advice request.
	 *
	 * @var array<int, string>
	 */
	private const ANSWERED = ['advice_uitgebracht', 'closed'];

	/**
	 * How many days back the bottleneck report looks.
	 *
	 * @var int
	 */
	public const BOTTLENECK_WINDOW_DAYS = 30;

	/**
	 * Today, as a date.
	 *
	 * @var DateTimeImmutable
	 */
	private DateTimeImmutable $today;

	/**
	 * Constructor.
	 *
	 * @param SettingsService        $settingsService Reads the settings.
	 * @param DateTimeImmutable|null $today           Today, for a test; else the current date.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		?DateTimeImmutable $today = null,
	) {
		$this->today = ($today ?? new DateTimeImmutable('today'))->setTime(0, 0);
	}//end __construct()

	/**
	 * The deadline of an advice request: the given one, else today plus the default term.
	 *
	 * @param string|null $given The deadline the request carries (Y-m-d), or none.
	 *
	 * @return string The deadline (Y-m-d).
	 *
	 * @spec openspec/specs/consultation-management/spec.md#requirement-an-advice-request-without-a-deadline-gets-the-default-term
	 */
	public function deadlineFor(?string $given): string {
		$given = trim((string)$given);
		if ($given !== '') {
			return $given;
		}

		$days = $this->wholeDays(key: 'consultation_default_deadline_days', default: 28);
		if ($days < 1) {
			$days = 28;
		}

		return $this->today->modify('+' . $days . ' days')->format('Y-m-d');
	}//end deadlineFor()

	/**
	 * How many days before its deadline an advice request is due soon.
	 *
	 * @return int The offset.
	 *
	 * @spec openspec/specs/consultation-management/spec.md#requirement-advice-requests-due-within-the-warning-offset-are-listed
	 */
	public function warningOffsetDays(): int {
		return $this->wholeDays(key: 'consultation_warning_offset_days', default: 5);
	}//end warningOffsetDays()

	/**
	 * The waiting advice requests whose deadline is today or within the warning offset.
	 *
	 * @param array<int, array<string, mixed>> $consultations The advice requests.
	 *
	 * @return array<int, array<string, mixed>> The ones due soon, in the given order.
	 *
	 * @spec openspec/specs/consultation-management/spec.md#requirement-advice-requests-due-within-the-warning-offset-are-listed
	 */
	public function dueSoon(array $consultations): array {
		$from = $this->today->format('Y-m-d');
		$until = $this->today->modify('+' . $this->warningOffsetDays() . ' days')->format('Y-m-d');

		$due = [];
		foreach ($consultations as $consultation) {
			$deadline = (string)($consultation['latestResponseDate'] ?? '');
			if (in_array((string)($consultation['status'] ?? ''), self::WAITING, true) === true
				&& $deadline !== '' && $deadline >= $from && $deadline <= $until
			) {
				$due[] = $consultation;
			}
		}

		return $due;
	}//end dueSoon()

	/**
	 * The address an external advisory body answers at: the configured https
	 * base plus the access link's last path segment, else the link itself.
	 *
	 * @param string $linkUrl The access link.
	 *
	 * @return string The address.
	 *
	 * @spec openspec/specs/consultation-management/spec.md#requirement-the-external-invitation-uses-the-configured-response-address
	 */
	public function responseAddress(string $linkUrl): string {
		$base = trim($this->settingsService->getConfigValue('consultation_external_response_url'));
		$token = basename((string)parse_url($linkUrl, PHP_URL_PATH));
		if (str_starts_with($base, 'https://') === false || $token === '' || $token === '/') {
			return $linkUrl;
		}

		return rtrim($base, '/') . '/' . $token;
	}//end responseAddress()

	/**
	 * The message that asks an external advisory body for its advice.
	 *
	 * @param array<string, mixed> $consultation The advice request.
	 * @param string               $address      Where the body answers.
	 *
	 * @return string The message, in Dutch: the advisory bodies are Dutch organisations.
	 *
	 * @spec openspec/specs/consultation-management/spec.md#requirement-the-external-invitation-uses-the-configured-response-address
	 */
	public function invitationMessage(array $consultation, string $address): string {
		$subject = trim((string)($consultation['subject'] ?? ''));
		$deadline = trim((string)($consultation['latestResponseDate'] ?? ''));

		return 'Wij vragen uw advies over: ' . $subject . '.' . "\n"
			. 'U kunt reageren tot en met ' . $deadline . ' via ' . $address;
	}//end invitationMessage()

	/**
	 * Per advisory body, over the requests created in the last 30 days: the
	 * total, the late ones, the late share, and whether it passes the threshold.
	 * Late is waiting past the deadline, or answered after it.
	 *
	 * @param array<int, array<string, mixed>> $consultations The advice requests.
	 *
	 * @return array{threshold: float, windowDays: int, bodies: array<int, array<string, mixed>>} The report.
	 *
	 * @spec openspec/specs/consultation-management/spec.md#requirement-advisory-bodies-past-the-bottleneck-threshold-are-flagged
	 */
	public function bottlenecks(array $consultations): array {
		$threshold = $this->share(key: 'consultation_bottleneck_threshold', default: 0.2);
		$since = $this->today->modify('-' . self::BOTTLENECK_WINDOW_DAYS . ' days')->format('Y-m-d');
		$today = $this->today->format('Y-m-d');

		$bodies = [];
		foreach ($consultations as $consultation) {
			$created = substr((string)($consultation['createdAt'] ?? ''), 0, 10);
			$body = trim((string)($consultation['adviceAuthority'] ?? ''));
			if ($created === '' || $created < $since || $body === '') {
				continue;
			}

			$bodies[$body] ??= ['adviceAuthority' => $body, 'total' => 0, 'late' => 0];
			$bodies[$body]['total']++;
			if ($this->isLate(consultation: $consultation, today: $today) === true) {
				$bodies[$body]['late']++;
			}
		}

		$report = [];
		foreach ($bodies as $row) {
			$row['lateShare'] = round($row['late'] / $row['total'], 4);
			$row['flagged'] = ($row['lateShare'] > $threshold);
			$report[] = $row;
		}

		return ['threshold' => $threshold, 'windowDays' => self::BOTTLENECK_WINDOW_DAYS, 'bodies' => $report];
	}//end bottlenecks()

	/**
	 * Whether an advice request is late on this day.
	 *
	 * @param array<string, mixed> $consultation The advice request.
	 * @param string               $today        Today (Y-m-d).
	 *
	 * @return bool True when late.
	 */
	private function isLate(array $consultation, string $today): bool {
		$deadline = (string)($consultation['latestResponseDate'] ?? '');
		if ($deadline === '') {
			return false;
		}

		$status = (string)($consultation['status'] ?? '');
		if (in_array($status, self::WAITING, true) === true) {
			return $deadline < $today;
		}

		$answered = substr((string)($consultation['closedAt'] ?? ''), 0, 10);

		return in_array($status, self::ANSWERED, true) === true && $answered !== '' && $answered > $deadline;
	}//end isLate()

	/**
	 * A whole number of days from a setting, else the default.
	 *
	 * @param string $key     The setting.
	 * @param int    $default The default.
	 *
	 * @return int The days.
	 */
	private function wholeDays(string $key, int $default): int {
		$value = trim($this->settingsService->getConfigValue($key));
		if (preg_match('/^\d+$/', $value) !== 1) {
			return $default;
		}

		return (int)$value;
	}//end wholeDays()

	/**
	 * A share between 0 and 1 from a setting, else the default.
	 *
	 * @param string $key     The setting.
	 * @param float  $default The default.
	 *
	 * @return float The share.
	 */
	private function share(string $key, float $default): float {
		$value = str_replace(',', '.', trim($this->settingsService->getConfigValue($key)));
		if (is_numeric($value) === false || (float)$value < 0 || (float)$value > 1) {
			return $default;
		}

		return (float)$value;
	}//end share()
}//end class
