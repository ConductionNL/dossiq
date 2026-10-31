<?php

/**
 * Dossiq SignedPayoutCallback.
 *
 * Verifies and reads a signed callback about a payout: from the finance app
 * (shillinq, header X-Shillinq-Signature) or from an external payment provider
 * (the frozen header X-Procest-Signature), both HMAC-SHA256 of the raw body
 * under the one callback secret.
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
 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Money;

use InvalidArgumentException;
use OCA\Dossiq\AppInfo\Application;
use OCP\IAppConfig;
use OCP\IRequest;

/**
 * Signature check, body parsing and status mapping for payout callbacks.
 *
 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
 */
class SignedPayoutCallback {

	/**
	 * The app config key of the shared secret. A stored key: frozen.
	 *
	 * @var string
	 */
	public const SECRET_KEY = MoneyConfigKeys::PAYOUT_CALLBACK_SECRET;

	/**
	 * The header the finance app signs with.
	 *
	 * @var string
	 */
	public const FINANCE_HEADER = 'X-Shillinq-Signature';

	/**
	 * FROZEN HEADER NAME. The external payment provider signs its callbacks
	 * with a header name configured on its side; renaming it here does not
	 * rename it there, and every real callback would be rejected as unsigned.
	 *
	 * @var string
	 */
	public const PROVIDER_HEADER = 'X-Procest-Signature';

	/**
	 * Payout statuses as the callback names them, to the status the payout row stores.
	 *
	 * The finance app says approved, paid or rejected; the provider has sent the
	 * stored values themselves and, in older payloads, Dutch ones.
	 *
	 * @var array<string, string>
	 */
	public const STATUS_MAP = [
		'approved' => 'in-handling',
		'paid' => 'paid',
		'rejected' => 'rejected',
		'in-handling' => 'in-handling',
		'in-behandeling' => 'in-handling',
		'betaald' => 'paid',
		'afgewezen' => 'rejected',
	];

	/**
	 * Constructor.
	 *
	 * @param IAppConfig $appConfig Holds the secret.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

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
	 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
	 */
	public function isSigned(string $rawBody, IRequest $request): bool {
		$secret = $this->secret();
		if ($secret === '') {
			return false;
		}

		$expected = hash_hmac('sha256', $rawBody, $secret);
		foreach ([self::FINANCE_HEADER, self::PROVIDER_HEADER] as $header) {
			$supplied = (string)$request->getHeader($header);
			if ($supplied !== '' && hash_equals($expected, $supplied) === true) {
				return true;
			}
		}

		return false;
	}//end isSigned()

	/**
	 * Whether a secret is configured at all.
	 *
	 * @return bool True when set.
	 *
	 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
	 */
	public function hasSecret(): bool {
		return $this->secret() !== '';
	}//end hasSecret()

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
	 * @spec openspec/changes/payout-goes-through-finance/specs/payout/spec.md
	 */
	public function read(string $rawBody): array {
		$body = json_decode($rawBody, true);
		if (is_array($body) === false) {
			throw new InvalidArgumentException('Invalid JSON body');
		}

		$reference = trim((string)($body['reference'] ?? ''));
		$status = strtolower(trim((string)($body['status'] ?? '')));
		if ($reference === '' || $status === '') {
			throw new InvalidArgumentException('reference and status are required');
		}

		if (array_key_exists($status, self::STATUS_MAP) === false) {
			throw new InvalidArgumentException('Unknown payout status: ' . $status);
		}

		$bankReference = (string)($body['bankReference'] ?? '');
		if ($bankReference === '') {
			$bankReference = (string)($body['betalingsreferentie'] ?? '');
		}

		return [
			'reference' => $reference,
			'status' => self::STATUS_MAP[$status],
			'actualPaymentDate' => trim((string)($body['actualPaymentDate'] ?? '')),
			'bankReference' => $bankReference,
			'payableId' => (string)($body['payableId'] ?? ''),
		];
	}//end read()

	/**
	 * The configured secret, from the legacy key while the current one is missing.
	 *
	 * @return string The secret, or empty.
	 */
	private function secret(): string {
		$secret = trim($this->appConfig->getValueString(Application::APP_ID, self::SECRET_KEY, ''));
		if ($secret === '' && $this->appConfig->hasKey(Application::APP_ID, self::SECRET_KEY) === false) {
			$secret = trim($this->appConfig->getValueString(Application::APP_ID, MoneyConfigKeys::LEGACY_KEYS[self::SECRET_KEY], ''));
		}

		return $secret;
	}//end secret()
}//end class
