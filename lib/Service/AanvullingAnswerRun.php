<?php

/**
 * Dossiq AanvullingAnswerRun.
 *
 * One answer to an aanvullingsverzoek while it is being recorded: the case,
 * the open request, what arrived and when, who recorded it, and the items as
 * they stand after marking.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
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
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use DateTimeImmutable;

/**
 * The state of one recorded answer, built and read by
 * AanvullingsverzoekResolutionService only.
 *
 * @spec openspec/specs/termijn-pause-extension/spec.md
 */
final class AanvullingAnswerRun {
	/**
	 * The case UUID.
	 *
	 * @var string
	 */
	public readonly string $caseId;

	/**
	 * The open request, as it stood before the answer.
	 *
	 * @var array<string, mixed>
	 */
	public readonly array $request;

	/**
	 * When the answer arrived.
	 *
	 * @var DateTimeImmutable
	 */
	public DateTimeImmutable $moment;

	/**
	 * The items that arrived, by their text.
	 *
	 * @var array<int, string>
	 */
	public array $received = [];

	/**
	 * Who recorded the answer.
	 *
	 * @var string
	 */
	public string $userId = '';

	/**
	 * The request's items with the arrivals marked.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	public array $items = [];

	/**
	 * The items still outstanding, by their text.
	 *
	 * @var array<int, string>
	 */
	public array $outstanding = [];

	/**
	 * Start recording an answer to a case's open request.
	 *
	 * @param string               $caseId  The case UUID.
	 * @param array<string, mixed> $request The open request.
	 */
	public function __construct(string $caseId, array $request) {
		$this->caseId = $caseId;
		$this->request = $request;
	}//end __construct()
}//end class
