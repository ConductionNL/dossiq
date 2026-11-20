<?php

/**
 * Dossiq RefusesUnreadableCaseTypes support trait.
 *
 * The read guard of the endpoints that answer about one case type. The using
 * class provides `$readable`
 * ({@see \OCA\Dossiq\Kernel\Contracts\ReadableObjectsInterface}).
 *
 * @category Controller
 * @package  OCA\Dossiq\Controller\Support
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
 * @spec exclude shared read guard of the case type endpoints, covered by their own specs
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use Throwable;

/**
 * The 401, 503 or 404 of a case type the caller may not read, else null.
 *
 * @spec exclude shared read guard of the case type endpoints, covered by their own specs
 */
trait RefusesUnreadableCaseTypes {

	/**
	 * The refusal of a caller who is not signed in or cannot read this case type, else null.
	 *
	 * @param string $id The case type id.
	 *
	 * @return JSONResponse|null
	 */
	private function caseTypeRefusal(string $id): ?JSONResponse {
		if ($this->readable->hasCaller() === false) {
			return new JSONResponse(['error' => 'Sign in to read this case type.'], Http::STATUS_UNAUTHORIZED);
		}

		try {
			$readable = $this->readable->findsAsCaller(schema: 'caseType', id: $id);
		} catch (Throwable) {
			return new JSONResponse(['error' => 'The case type could not be read. Try again later.'], Http::STATUS_SERVICE_UNAVAILABLE);
		}

		if ($readable === false) {
			return new JSONResponse(['error' => 'Case type not found'], Http::STATUS_NOT_FOUND);
		}

		return null;
	}//end caseTypeRefusal()
}//end trait
