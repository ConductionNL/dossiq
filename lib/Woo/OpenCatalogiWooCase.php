<?php

/**
 * Dossiq case for an opencatalogi Woo request
 *
 * The Woo case one stored opencatalogi `wooRequest` becomes when dossiq takes
 * the request over (decision D1). Pure: it reads the source row and answers
 * the case payload, the status it lands in, whether its term is open or
 * suspended, and the deadline the source already reports. The import writes
 * it; this class decides nothing about whether a case already exists.
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
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-every-stored-opencatalogi-request-is-imported-exactly-once-req-wto-004
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Woo;

use OCA\Dossiq\Service\CaseDateNormaliser;

/**
 * Maps one opencatalogi `wooRequest` onto a dossiq Woo case.
 *
 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-every-stored-opencatalogi-request-is-imported-exactly-once-req-wto-004
 */
class OpenCatalogiWooCase {

	/**
	 * The application name a former reference carries.
	 */
	public const APPLICATION = 'opencatalogi';

	/**
	 * opencatalogi's status onto the seeded Woo case type's status uuid
	 * (register.d/81-woo-verzoek.json): Ontvangst, Beoordelen documenten,
	 * Beoordeling ontvankelijkheid, and Afgehandeld twice.
	 */
	public const STATUS = [
		'received' => '3c0f5a00-0000-4000-a000-00000000b001',
		'in_progress' => '3c0f5a00-0000-4000-a000-00000000b004',
		'awaiting_clarification' => '3c0f5a00-0000-4000-a000-00000000b002',
		'decided' => '3c0f5a00-0000-4000-a000-00000000b008',
		'withdrawn' => '3c0f5a00-0000-4000-a000-00000000b008',
	];

	/**
	 * The result type a withdrawn request ends with: Ingetrokken.
	 */
	public const RESULT_WITHDRAWN = '3c0f5a00-0000-4000-a000-00000000c004';

	/**
	 * The statuses whose term still runs or waits.
	 */
	public const OPEN = ['received', 'in_progress', 'awaiting_clarification'];

	/**
	 * Constructor.
	 *
	 * @param CaseDateNormaliser $dates The one reader of a date and the tenant's time zone.
	 */
	public function __construct(
		private readonly CaseDateNormaliser $dates,
	) {
	}//end __construct()

	/**
	 * The case one source request becomes.
	 *
	 * @param array<string, mixed> $source     The opencatalogi `wooRequest` row.
	 * @param string               $sourceUuid Its uuid.
	 *
	 * @return array{case: array<string, mixed>, open: bool, suspended: bool, deadline: string, result: string}
	 *
	 * @throws WooRequestRefused INVALID when the row cannot be a Woo case: no
	 *                           question, an unknown status or channel, or no
	 *                           readable receipt moment.
	 *
	 * @spec openspec/changes/woo-request-takes-over-from-opencatalogi/specs/woo-request-intake/spec.md#requirement-every-stored-opencatalogi-request-is-imported-exactly-once-req-wto-004
	 */
	public function fromSource(array $source, string $sourceUuid): array {
		$status = trim((string)($source['status'] ?? ''));
		if (array_key_exists($status, self::STATUS) === false) {
			throw new WooRequestRefused(WooRequestRefused::INVALID, 'The request has an unknown status: ' . $status . '.');
		}

		$receivedAt = trim((string)($source['receivedAt'] ?? ''));
		if ($receivedAt === '' || $this->dates->tryParse(value: $receivedAt) === null) {
			throw new WooRequestRefused(WooRequestRefused::INVALID, 'The request has no readable receipt moment.');
		}

		$answers = [
			'requestedInformation' => ($source['requestedInformation'] ?? null),
			'requesterName' => ($source['requesterName'] ?? null),
			'requesterEmail' => ($source['requesterEmail'] ?? null),
			'requesterPhone' => ($source['requesterPhone'] ?? null),
			'requesterAddress' => ($source['requesterAddress'] ?? null),
			'channel' => ($source['channel'] ?? null),
			'originReference' => $sourceUuid,
		];
		$map = new WooReceivedAnswers(dates: $this->dates);
		$wooRequest = (new WooRequestForm())->normalise(request: $map->toRequest(answers: $answers, origin: self::APPLICATION));

		$case = [
			'title' => $wooRequest['onderwerp'],
			'description' => $wooRequest['omschrijving'],
			'caseType' => WooRequestIntake::CASE_TYPE_ID,
		] + $map->intake(answers: $answers, origin: self::APPLICATION, receivedAt: $receivedAt) + [
			'status' => self::STATUS[$status],
			'wooRequest' => $wooRequest,
			'formerReferences' => $this->formerReferences(source: $source),
			'extensionCount' => max(0, (int)($source['extensionCount'] ?? 0)),
		];

		$decidedAt = $this->dates->toCalendarDateOrNull(value: ($source['decidedAt'] ?? null));
		if ($status === 'decided' && $decidedAt !== null) {
			$case['endDate'] = $decidedAt;
		}

		$result = '';
		if ($status === 'withdrawn') {
			$result = self::RESULT_WITHDRAWN;
		}

		return [
			'case' => $case,
			'open' => in_array($status, self::OPEN, true),
			'suspended' => ($status === 'awaiting_clarification'),
			'deadline' => (string)$this->dates->toCalendarDateOrNull(value: ($source['dueAt'] ?? null)),
			'result' => $result,
		];
	}//end fromSource()

	/**
	 * The source's own reference, kept so the requester's old number still finds the case.
	 *
	 * @param array<string, mixed> $source The source row.
	 *
	 * @return array<int, array{application: string, reference: string}>
	 */
	private function formerReferences(array $source): array {
		$reference = trim((string)($source['reference'] ?? ''));
		if ($reference === '') {
			return [];
		}

		return [['application' => self::APPLICATION, 'reference' => $reference]];
	}//end formerReferences()
}//end class
