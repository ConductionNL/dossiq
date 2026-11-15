<?php

/**
 * Dossiq GuardsZgwRequests support trait.
 *
 * The JWT and scope checks every ZGW controller runs before it hands a
 * request to the shared ZGW service, and the audit-trail endpoints that are
 * the same on every register. The using class provides a `$zgwService`
 * property ({@see \OCA\Dossiq\Kernel\Contracts\ZgwServiceInterface}), the
 * controller's `$request`, a `ZGW_API` constant naming its register and the
 * `scopeDeniedResponse()` of {@see \OCA\Dossiq\Controller\ZgwController}.
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

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;

/**
 * JWT and scope guards plus the audit-trail endpoints shared by the ZGW controllers.
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
trait GuardsZgwRequests {
	/**
	 * The refusal for a request without a valid ZGW JWT, else null.
	 *
	 * @return JSONResponse|null The refusal, or null when the token is valid.
	 */
	private function requireValidJwt(): ?JSONResponse {
		return $this->zgwService->validateJwtAuth($this->request);
	}//end requireValidJwt()

	/**
	 * The JWT refusal, else the refusal for a consumer without the scope, else null.
	 *
	 * The JWT is checked first and the scope only for a valid token, so a
	 * write is refused before anything is read or written.
	 *
	 * @param string $component The ZGW component that grants the scope (e.g. drc).
	 * @param string $scope     The scope the write needs (canonical format).
	 *
	 * @return JSONResponse|null The refusal, or null when the write may go ahead.
	 */
	private function scopedWriteRefusal(string $component, string $scope): ?JSONResponse {
		$authError = $this->requireValidJwt();
		if ($authError !== null) {
			return $authError;
		}

		if ($this->zgwService->consumerHasScope($this->request, $component, $scope) === false) {
			return $this->scopeDeniedResponse(scope: $scope);
		}

		return null;
	}//end scopedWriteRefusal()

	/**
	 * The audit trail of a resource, behind the JWT check.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid     The resource UUID.
	 *
	 * @return JSONResponse
	 */
	private function audittrailList(string $resource, string $uuid): JSONResponse {
		return $this->requireValidJwt() ?? $this->zgwService->handleAudittrailIndex($this->request, self::ZGW_API, $resource, $uuid);
	}//end audittrailList()

	/**
	 * One audit trail entry of a resource, behind the JWT check.
	 *
	 * @param string $resource  The ZGW resource name.
	 * @param string $uuid      The resource UUID.
	 * @param string $auditUuid The audit trail entry UUID.
	 *
	 * @return JSONResponse
	 */
	private function audittrailEntry(string $resource, string $uuid, string $auditUuid): JSONResponse {
		return $this->requireValidJwt() ?? $this->zgwService->handleAudittrailShow(
			$this->request,
			self::ZGW_API,
			$resource,
			$uuid,
			$auditUuid
		);
	}//end audittrailEntry()

	/**
	 * A 404 when the resource no longer exists in its source register, else null.
	 *
	 * Without an object service or a mapping there is nothing to look in, so
	 * the request goes ahead.
	 *
	 * @param string $resource The ZGW resource name.
	 * @param string $uuid     The resource UUID.
	 * @param string $detail   The detail text of the 404.
	 *
	 * @return JSONResponse|null The 404, or null when the resource exists or cannot be checked.
	 */
	private function missingResourceRefusal(string $resource, string $uuid, string $detail): ?JSONResponse {
		$objectService = $this->zgwService->getObjectService();
		if ($objectService === null) {
			return null;
		}

		$mappingConfig = $this->zgwService->loadMappingConfig(self::ZGW_API, $resource);
		if ($mappingConfig === null) {
			return null;
		}

		try {
			$objectService->find(
				$uuid,
				register: $mappingConfig['sourceRegister'],
				schema: $mappingConfig['sourceSchema']
			);
		} catch (\Throwable $e) {
			return new JSONResponse(
				data: ['detail' => $detail],
				statusCode: Http::STATUS_NOT_FOUND
			);
		}

		return null;
	}//end missingResourceRefusal()

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
