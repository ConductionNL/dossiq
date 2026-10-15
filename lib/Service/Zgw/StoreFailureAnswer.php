<?php

/**
 * What a ZGW request answers when OpenRegister did not do what it asked
 *
 * A read, update or delete of a stored object can fail in three ways that
 * mean different things to the caller: the object is not there, the caller
 * may not touch it, or the store could not be reached. Each gets its own
 * answer, so a delete that never happened does not read as "not found".
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Zgw
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
 * @spec openspec/changes/guards-refuse-when-unsure/specs/zgw-business-rules-compliance/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Zgw;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sorts a store failure into not found (404), not allowed (403) and not reached (503).
 *
 * @spec openspec/changes/guards-refuse-when-unsure/specs/zgw-business-rules-compliance/spec.md
 */
class StoreFailureAnswer {
	/**
	 * The code of the answer when the store could not be reached.
	 */
	public const NOT_COMPLETED_CODE = 'store-not-reached';

	/**
	 * OpenRegister's refusal of a permission. Named as a string: OpenRegister is optional at runtime.
	 */
	private const NOT_AUTHORIZED = 'OCA\\OpenRegister\\Exception\\NotAuthorizedException';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger The logger.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The answer for a failed store call, logged with what was asked of the store.
	 *
	 * OpenRegister says "not found" with a DoesNotExistException (a null answer is
	 * read as one by the caller, {@see self::notFound()}), and "not allowed" with
	 * its NotAuthorizedException or any failure carrying code 403. Everything
	 * else means the store did not complete the call, and nothing may be assumed
	 * about the object.
	 *
	 * @param Throwable $error The failure.
	 * @param string $operation What was asked (`ZGW delete`).
	 * @param string $object Which object (`besluiten 1f0c...`).
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/guards-refuse-when-unsure/specs/zgw-business-rules-compliance/spec.md
	 */
	public function respond(Throwable $error, string $operation, string $object): JSONResponse {
		$context = ['operation' => $operation, 'object' => $object, 'exception' => $error];

		if ($error instanceof DoesNotExistException) {
			$this->logger->info('{operation}: {object} not found', $context);
			return $this->notFound();
		}

		if (is_a($error, self::NOT_AUTHORIZED) === true || $error->getCode() === Http::STATUS_FORBIDDEN) {
			$this->logger->warning('{operation}: not allowed on {object}: ' . $error->getMessage(), $context);
			return new JSONResponse(
				data: [
					'type' => 'PermissionDenied',
					'code' => 'permission_denied',
					'title' => 'U heeft geen toestemming voor deze actie.',
					'status' => Http::STATUS_FORBIDDEN,
					'detail' => 'U heeft geen toestemming voor deze actie.',
				],
				statusCode: Http::STATUS_FORBIDDEN
			);
		}

		$this->logger->error('{operation}: the store did not complete the call for {object}: ' . $error->getMessage(), $context);
		return new JSONResponse(
			data: [
				'code' => self::NOT_COMPLETED_CODE,
				'detail' => 'De registratie kon de opdracht niet voltooien. Probeer het later opnieuw.',
			],
			statusCode: ZgwRuleResults::NOT_VERIFIED_STATUS
		);
	}//end respond()

	/**
	 * The answer for an object the store does not hold.
	 *
	 * @return JSONResponse
	 *
	 * @spec openspec/changes/guards-refuse-when-unsure/specs/zgw-business-rules-compliance/spec.md
	 */
	public function notFound(): JSONResponse {
		return new JSONResponse(data: ['detail' => 'Not found'], statusCode: Http::STATUS_NOT_FOUND);
	}//end notFound()
}//end class
