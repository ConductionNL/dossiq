<?php

/**
 * Dossiq AnswersRuleRefusals support trait.
 *
 * The answer the ZGW controllers give when the business rules refuse a
 * write. The using class provides a `$zgwService` property
 * ({@see \OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface}).
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
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Controller\Support;

use OCP\AppFramework\Http\JSONResponse;

/**
 * The validation error of refusing business rules, or null when they accept.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
trait AnswersRuleRefusals {

	/**
	 * The validation error the business rules answered, or null when they accepted.
	 *
	 * @param array<string, mixed> $ruleResult The rules' result: `valid`, `status` and the errors.
	 *
	 * @return JSONResponse|null
	 */
	private function ruleRefusal(array $ruleResult): ?JSONResponse {
		if ($ruleResult['valid'] === false) {
			return new JSONResponse(
				data: $this->zgwService->buildValidationError($ruleResult),
				statusCode: $ruleResult['status']
			);
		}

		return null;
	}//end ruleRefusal()
}//end trait
