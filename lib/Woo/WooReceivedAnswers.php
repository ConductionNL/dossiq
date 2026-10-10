<?php

/**
 * Dossiq Woo request answers in opencatalogi's shape
 *
 * The intake opencatalogi had read a Woo request as the answer keys of a portal
 * form: `requestedInformation`, `requesterName`, `requesterEmail`,
 * `requesterPhone`, `requesterAddress` and an optional `channel`. dossiq now
 * owns the Woo request (decision D1), so the same keys arrive here, and this
 * class turns them into the request WooRequestForm checks and the case keeps.
 * The form submit creates the case directly (decision 179): there is no
 * intake record in between, so what this class answers is the case's own
 * `wooRequest` input, nothing else.
 *
 * @category Woo
 * @package  OCA\Dossiq\Woo
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-dossiq-receives-a-woo-request-in-opencatalogis-shape-req-wto-001
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use DateTimeImmutable;
use Throwable;

/**
 * Maps opencatalogi's Woo answer keys onto the request a Woo case keeps.
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-dossiq-receives-a-woo-request-in-opencatalogis-shape-req-wto-001
 */
class WooReceivedAnswers {

	/**
	 * The longest subject a case title takes, in characters.
	 */
	public const SUBJECT_LENGTH = 120;

	/**
	 * opencatalogi's channel values onto the case's `intakeChannel` enum.
	 */
	public const CHANNELS = [
		'web' => 'website',
		'email' => 'email',
		'post' => 'post',
		'counter' => 'balie',
		'phone' => 'phone',
	];

	/**
	 * The six keys every answer of receive() carries, each a string.
	 */
	public const EMPTY_ANSWER = [
		'outcome' => '',
		'requestId' => '',
		'reference' => '',
		'dueAt' => '',
		'message' => '',
		'caseUrl' => '',
	];

	/**
	 * The request WooRequestForm::normalise() reads, from the answers.
	 *
	 * @param array<string, mixed> $answers The form answers in opencatalogi's keys.
	 * @param string               $origin  `portal-form` or `opencatalogi`.
	 *
	 * @return array<string, mixed> The request.
	 *
	 * @throws WooRequestRefused INVALID when the origin is not one of the two,
	 *                           nothing is asked for, or the channel is unknown.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-dossiq-receives-a-woo-request-in-opencatalogis-shape-req-wto-001
	 */
	public function toRequest(array $answers, string $origin): array {
		if (in_array($origin, WooRequestIntake::SUBJECTLESS_ORIGINS, true) === false) {
			throw new WooRequestRefused(
				WooRequestRefused::INVALID,
				'The origin must be one of: ' . implode(', ', WooRequestIntake::SUBJECTLESS_ORIGINS) . '.'
			);
		}

		$asked = $this->text(value: ($answers['requestedInformation'] ?? null));
		if ($asked === '') {
			throw new WooRequestRefused(WooRequestRefused::INVALID, 'Say which information you are asking for.');
		}

		return [
			'subjectRef' => $this->text(value: ($answers['subjectRef'] ?? null)),
			'onderwerp' => $this->subject(text: $asked),
			'omschrijving' => $asked,
			'origin' => $origin,
			'originReference' => $this->text(value: ($answers['originReference'] ?? null)),
			'verzoekerNaam' => $this->text(value: ($answers['requesterName'] ?? null)),
			'verzoekerEmail' => $this->text(value: ($answers['requesterEmail'] ?? null)),
			'verzoekerTelefoon' => $this->text(value: ($answers['requesterPhone'] ?? null)),
			'verzoekerAdres' => $this->text(value: ($answers['requesterAddress'] ?? null)),
		];
	}//end toRequest()

	/**
	 * The case's `intakeChannel` for the answers.
	 *
	 * @param array<string, mixed> $answers The form answers.
	 * @param string               $origin  The origin, whose channel applies when none was given.
	 *
	 * @return string One value of the case schema's `intakeChannel` enum.
	 *
	 * @throws WooRequestRefused INVALID when a channel is given that opencatalogi did not know.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-dossiq-receives-a-woo-request-in-opencatalogis-shape-req-wto-001
	 */
	public function intakeChannel(array $answers, string $origin): string {
		$channel = $this->text(value: ($answers['channel'] ?? null));
		if ($channel === '') {
			return WooRequestIntake::INTAKE_CHANNEL[$origin];
		}

		if (array_key_exists($channel, self::CHANNELS) === false) {
			throw new WooRequestRefused(
				WooRequestRefused::INVALID,
				'The channel must be one of: ' . implode(', ', array_keys(self::CHANNELS)) . '.'
			);
		}

		return self::CHANNELS[$channel];
	}//end intakeChannel()

	/**
	 * How the request came in, as the case keeps it.
	 *
	 * `startDate` and `receivedAt` are the moment the requester sent it, not
	 * the moment a delivery job reached dossiq (Awb 4:1, REQ-WTO-002); now
	 * only when no readable moment was given.
	 *
	 * @param array<string, mixed> $answers    The form answers.
	 * @param string               $origin     The origin.
	 * @param string               $receivedAt When the requester sent it, ISO 8601, or ''.
	 *
	 * @return array<string, string> `{startDate, receivedAt, intakeChannel, portalSubject?}`.
	 *
	 * @throws WooRequestRefused INVALID when the channel is unknown.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-armed-means-a-term-runs-counted-from-when-the-requester-sent-it-req-wto-002
	 */
	public function intake(array $answers, string $origin, string $receivedAt): array {
		$moment = $this->moment(receivedAt: $receivedAt);
		$intake = [
			'startDate' => $moment->format('Y-m-d'),
			'receivedAt' => $moment->format('c'),
			'intakeChannel' => $this->intakeChannel(answers: $answers, origin: $origin),
		];

		$subjectRef = $this->text(value: ($answers['subjectRef'] ?? null));
		if ($subjectRef !== '') {
			$intake['portalSubject'] = $subjectRef;
		}

		return $intake;
	}//end intake()

	/**
	 * The moment the requester sent the request.
	 *
	 * @param string $receivedAt What the caller said, ISO 8601.
	 *
	 * @return DateTimeImmutable That moment, or now when it is empty or does not read as a date.
	 */
	private function moment(string $receivedAt): DateTimeImmutable {
		$receivedAt = trim($receivedAt);
		if ($receivedAt !== '') {
			try {
				return new DateTimeImmutable($receivedAt);
			} catch (Throwable $e) {
				// Unreadable: the term counts from now, as an empty one does.
				unset($e);
			}
		}

		return new DateTimeImmutable();
	}//end moment()

	/**
	 * The subject: the request itself, cut on a word boundary when it is long.
	 *
	 * @param string $text What the requester asked for.
	 *
	 * @return string At most SUBJECT_LENGTH characters.
	 */
	private function subject(string $text): string {
		$text = (string)preg_replace('/\s+/u', ' ', $text);
		if (mb_strlen($text) <= self::SUBJECT_LENGTH) {
			return $text;
		}

		$cut = mb_substr($text, 0, self::SUBJECT_LENGTH + 1);
		$space = mb_strrpos($cut, ' ');
		if ($space === false || $space === 0) {
			return mb_substr($text, 0, self::SUBJECT_LENGTH);
		}

		return rtrim(mb_substr($cut, 0, $space));
	}//end subject()

	/**
	 * A scalar as trimmed text, anything else as ''.
	 *
	 * @param mixed $value The value.
	 *
	 * @return string
	 */
	private function text(mixed $value): string {
		if (is_scalar($value) === false) {
			return '';
		}

		return trim((string)$value);
	}//end text()
}//end class
