<?php

/**
 * Contract of SignedPayoutCallback
 *
 * The public surface of SignedPayoutCallback that other components call, so they depend
 * on this contract instead of the implementation.
 *
 * @category  Kernel
 * @package   OCA\Dossiq\Kernel\Contracts
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT: <git_id>
 * @link      https://github.com/ConductionNL/dossiq
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Kernel\Contracts;

use InvalidArgumentException;
use OCA\Dossiq\Service\Money\MoneyConfigKeys;
use OCP\IRequest;

/**
 * Contract of SignedPayoutCallback.
 *
 * @category Kernel
 * @package  OCA\Dossiq\Kernel\Contracts
 * @author   Conduction b.v. <info@conduction.nl>
 * @license  EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @link     https://github.com/ConductionNL/dossiq
 *
 * @spec exclude Structural contract, no functional requirement.
 */
interface SignedPayoutCallbackInterface {

	/**
	 * The app config key of the shared secret. A stored key: frozen.
	 *
	 * @var string
	 */
	public const SECRET_KEY = MoneyConfigKeys::PAYOUT_CALLBACK_SECRET_KEY;

	/**
	 * Whether a secret is configured at all.
	 *
	 * @return bool True when set.
	 *
	 * @spec openspec/specs/payout/spec.md
	 */
	public function hasSecret(): bool;

	/**
	 * Whether the raw body carries a valid signature in either header.
	 *
	 * Fails closed: without a configured secret nothing is signed.
	 *
	 * @param string   $rawBody The raw request body.
	 * @param IRequest $request The request, for the headers.
	 *
	 * @return bool True when one of the headers matches.
	 *
	 * @spec openspec/specs/payout/spec.md
	 */
	public function isSigned(string $rawBody, IRequest $request): bool;

	/**
	 * Read the callback body.
	 *
	 * @param string $rawBody The raw request body.
	 *
	 * @return array{reference: string, status: string, actualPaymentDate: string, bankReference: string, payableId: string}
	 *         The fields, with the status mapped onto the stored vocabulary.
	 *
	 * @throws InvalidArgumentException When the body is not JSON, misses the reference or status, or names an unknown status.
	 *
	 * @spec openspec/specs/payout/spec.md
	 */
	public function read(string $rawBody): array;
}//end interface
