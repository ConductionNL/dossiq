<?php

/**
 * Dossiq review support: the availability check and refusal of the review stores.
 *
 * @category Review
 * @package  OCA\Dossiq\Review\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec exclude shared plumbing of the triage rule and review batch stores, covered by their own specs
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review\Support;

use OCA\Dossiq\Exception\RefusedException;
use Throwable;

/**
 * Whether a review store's schema can be used, and its refusals.
 *
 * The using class provides `$reviews` (DocumentRelevance),
 * `$settingsService` and the `SCHEMA_KEY` constant.
 *
 * @spec exclude shared plumbing of the triage rule and review batch stores, covered by their own specs
 */
trait ReviewSchemaRows {

	/**
	 * Whether OpenRegister, the review schema and this store's schema are configured.
	 *
	 * @return bool
	 */
	private function isAvailable(): bool {
		return $this->reviews->isAvailable() === true
			&& $this->settingsService->getConfigValue(self::SCHEMA_KEY) !== '';
	}//end isAvailable()

	/**
	 * A refusal, 422 unless said otherwise.
	 *
	 * @param string         $rule     The rule.
	 * @param string         $sentence The static sentence.
	 * @param int            $status   The status.
	 * @param Throwable|null $previous The cause.
	 *
	 * @return RefusedException
	 */
	private function refusal(
		string $rule,
		string $sentence,
		int $status = RefusedException::STATUS_UNPROCESSABLE,
		?Throwable $previous = null,
	): RefusedException {
		return new RefusedException(rule: $rule, sentence: $sentence, status: $status, previous: $previous);
	}//end refusal()
}//end trait
