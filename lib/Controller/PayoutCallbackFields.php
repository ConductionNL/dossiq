<?php

/**
 * Dossiq Payout Callback Fields.
 *
 * What a signed payout callback says, for `PayoutCallbackController`: the
 * fields the signed reader takes from the body, with the actual payment date
 * parsed through the one date write path. A malformed date is the caller's
 * fault and answers 400; it is never replaced by today.
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller
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
 * @spec openspec/changes/method-decomposition/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller;

use InvalidArgumentException;
use OCA\Dossiq\Kernel\Contracts\CaseDateNormaliserInterface;
use OCA\Dossiq\Kernel\Contracts\SignedPayoutCallbackInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * Reads a payout callback's fields and parses its payment date.
 *
 * @spec openspec/changes/method-decomposition/tasks.md
 */
class PayoutCallbackFields {
	/**
	 * Constructor.
	 *
	 * @param SignedPayoutCallbackInterface $callback Reads the body.
	 * @param CaseDateNormaliserInterface   $dates    The one date write path.
	 *
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function __construct(
		private readonly SignedPayoutCallbackInterface $callback,
		private readonly CaseDateNormaliserInterface $dates,
	) {
	}//end __construct()

	/**
	 * The callback's fields with its payment date parsed, or the 400 answer.
	 *
	 * @param string $rawBody The raw request body.
	 *
	 * @return array<string, mixed>|JSONResponse The fields plus `paymentDate` (null when absent).
	 *
	 * @spec openspec/specs/payout/spec.md
	 * @spec openspec/changes/method-decomposition/tasks.md
	 */
	public function read(string $rawBody): array|JSONResponse {
		try {
			$fields = $this->callback->read(rawBody: $rawBody);
			$fields['paymentDate'] = null;
			if ($fields['actualPaymentDate'] !== '') {
				$fields['paymentDate'] = $this->dates->parse($fields['actualPaymentDate'], 'actualPaymentDate');
			}
		} catch (InvalidArgumentException $e) {
			// A malformed date used to be replaced by today, booking the payment on the wrong day.
			return new JSONResponse(['message' => $e->getMessage()], Http::STATUS_BAD_REQUEST);
		}

		return $fields;
	}//end read()
}//end class
