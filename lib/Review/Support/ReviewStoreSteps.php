<?php

/**
 * Dossiq review store steps: whether a review schema is reachable, and its refusals.
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
 * @spec exclude shared private steps; each using class carries its own spec
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Review\Support;

use OCA\Dossiq\Exception\RefusedException;
use Throwable;

/**
 * For a class holding `$this->reviews`, `$this->settingsService` and a `SCHEMA_KEY` constant.
 *
 * @spec exclude shared private steps; each using class carries its own spec
 */
trait ReviewStoreSteps {

	/**
	 * Whether the review store and the class's schema can be read and written.
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
	 * @return RefusedException The refusal.
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
