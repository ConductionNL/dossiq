<?php

/**
 * ZGW Authentication Middleware
 *
 * Resolves the caller's credential and enforces scopes on all ZGW API endpoints.
 *
 * @category Middleware
 * @package  OCA\Dossiq\Middleware
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2024 Conduction B.V. <info@conduction.nl>
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Middleware;

use OCA\Dossiq\Controller\ZgwController;
use OCA\Dossiq\Service\Zgw\ApiCredentialResolver;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Middleware that resolves the caller and enforces ZGW scopes.
 *
 * Applied to all ZgwController requests. The caller presents a bearer JWT, an
 * API key or a certificate forwarded by a trusted proxy; ApiCredentialResolver
 * turns it into an OpenRegister consumer and binds that consumer's user. A
 * request without a credential, or whose credential does not resolve, answers
 * 401 before any controller runs. The consumer's applicatie then decides the
 * scope, whatever credential it used (403 when it does not cover the request).
 *
 * @spec openspec/changes/public-api-access-with-keys-and-certificates/specs/public-api-access/spec.md#requirement-a-calling-system-is-an-openregister-consumer-req-paa-01
 * @spec openspec/specs/zgw-api-mapping/spec.md
 */
class ZgwAuthMiddleware extends Middleware {
	/**
	 * Map of ZGW API groups to component codes.
	 *
	 * @var array<string, string>
	 */
	private const API_TO_COMPONENT = [
		'zaken' => 'zrc',
		'catalogi' => 'ztc',
		'besluiten' => 'brc',
		'documenten' => 'drc',
		'notificaties' => 'nrc',
		'autorisaties' => 'ac',
	];

	/**
	 * Canonical scope-name prefix for each ZGW component.
	 *
	 * A scope grant like "besluiten.aanmaken" is only valid for the BRC component;
	 * it must NOT satisfy a ZRC "zaken.aanmaken" request even though both share
	 * the ".aanmaken" suffix.  This map enforces the full scope-name match.
	 *
	 * @var array<string, string>
	 */
	private const COMPONENT_SCOPE_PREFIX = [
		'zrc' => 'zaken',
		'ztc' => 'catalogi',
		'brc' => 'besluiten',
		'drc' => 'documenten',
		'nrc' => 'notificaties',
		'ac' => 'autorisaties',
	];

	/**
	 * Map of HTTP methods to ZGW scope suffixes.
	 *
	 * @var array<string, string>
	 */
	private const METHOD_TO_SCOPE = [
		'GET' => 'lezen',
		'POST' => 'aanmaken',
		'PUT' => 'bijwerken',
		'PATCH' => 'bijwerken',
		'DELETE' => 'verwijderen',
	];

	/**
	 * Confidentiality levels from low to high.
	 *
	 * @var string[]
	 */
	private const CONFIDENTIALITY_ORDER = [
		'openbaar',
		'beperkt_openbaar',
		'intern',
		'zaakvertrouwelijk',
		'vertrouwelijk',
		'confidentieel',
		'geheim',
		'zeer_geheim',
	];

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The incoming request
	 * @param ApiCredentialResolver $credentials Resolves the caller's credential to a consumer
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRequest $request,
		private readonly ApiCredentialResolver $credentials,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Resolve the caller and enforce scopes before controller execution.
	 *
	 * @param \OCP\AppFramework\Controller $controller The controller instance
	 * @param string $methodName The method being called
	 *
	 * @return void
	 *
	 * @throws \OCA\Dossiq\Middleware\ZgwAuthException If authentication or authorization fails.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $methodName required by Middleware interface
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 * @spec openspec/changes/public-api-access-with-keys-and-certificates/specs/public-api-access/spec.md#requirement-every-credential-is-scoped-by-the-consumers-applicatie-req-paa-03
	 */
	public function beforeController($controller, $methodName): void {
		if (($controller instanceof ZgwController) === false) {
			return;
		}

		$authConfig = $this->consumerConfiguration();

		// Superuser bypasses all scope checks.
		if (($authConfig['superuser'] ?? false) === true) {
			return;
		}

		// Enforce scope-based authorization.
		$this->enforceScopes(authConfig: $authConfig);
	}//end beforeController()

	/**
	 * The authorization configuration of the consumer behind the request.
	 *
	 * @return array The consumer's authorization configuration.
	 *
	 * @throws ZgwAuthException 401 when the request carries no credential or one that does not resolve.
	 *
	 * @spec openspec/changes/public-api-access-with-keys-and-certificates/specs/public-api-access/spec.md#requirement-a-calling-system-is-an-openregister-consumer-req-paa-01
	 */
	private function consumerConfiguration(): array {
		try {
			$consumer = $this->credentials->resolve(request: $this->request);
		} catch (\Throwable $e) {
			$this->logger->warning('ZGW auth failed: ' . $e->getMessage());
			throw $this->unauthenticated(message: ApiCredentialResolver::refusalMessage(failure: $e));
		}

		if ($consumer === null) {
			throw $this->unauthenticated(message: 'Authenticatiegegevens zijn niet opgegeven.');
		}

		$authConfig = $consumer->getAuthorizationConfiguration();
		if (is_array($authConfig) === false) {
			return [];
		}

		return $authConfig;
	}//end consumerConfiguration()

	/**
	 * A 401 refusal: the caller is not known.
	 *
	 * @param string $message What the caller is told.
	 *
	 * @return ZgwAuthException
	 *
	 * @spec openspec/changes/public-api-access-with-keys-and-certificates/specs/public-api-access/spec.md#requirement-a-calling-system-is-an-openregister-consumer-req-paa-01
	 */
	private function unauthenticated(string $message): ZgwAuthException {
		return new ZgwAuthException(
			message: $message,
			statusCode: Http::STATUS_UNAUTHORIZED
		);
	}//end unauthenticated()

	/**
	 * The 403 the gate refuses with.
	 *
	 * @param string $message Why.
	 *
	 * @return ZgwAuthException
	 */
	private function forbidden(string $message): ZgwAuthException {
		return new ZgwAuthException(
			message: $message,
			statusCode: Http::STATUS_FORBIDDEN
		);
	}//end forbidden()

	/**
	 * Handle exceptions thrown during beforeController.
	 *
	 * @param \OCP\AppFramework\Controller $controller The controller
	 * @param string $methodName The method name
	 * @param \Exception $exception The exception
	 *
	 * @return JSONResponse
	 *
	 * @throws \Exception Re-throws any non-ZGW-auth exception for the next middleware.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $controller/$methodName required by Middleware interface
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function afterException($controller, $methodName, \Exception $exception): JSONResponse {
		if ($exception instanceof ZgwAuthException && $exception->getStatusCode() === Http::STATUS_UNAUTHORIZED) {
			return new JSONResponse(
				data: [
					'type' => 'https://datatracker.ietf.org/doc/html/rfc7235#section-3.1',
					'code' => 'not_authenticated',
					'title' => 'Authenticatiegegevens zijn niet geldig.',
					'status' => Http::STATUS_UNAUTHORIZED,
					'detail' => $exception->getMessage(),
				],
				statusCode: Http::STATUS_UNAUTHORIZED
			);
		}

		if ($exception instanceof ZgwAuthException) {
			return new JSONResponse(
				data: [
					'type' => 'https://datatracker.ietf.org/doc/html/rfc7231#section-6.5.3',
					'code' => 'permission_denied',
					'title' => 'U heeft geen toestemming om deze actie uit te voeren.',
					'status' => $exception->getStatusCode(),
					'detail' => $exception->getMessage(),
				],
				statusCode: $exception->getStatusCode()
			);
		}

		// Per the Nextcloud middleware contract, an afterException() handler
		// MUST return a Response or re-throw — it must never return null.
		// MiddlewareDispatcher::afterException() does `return $mw->afterException(...)`
		// against a non-nullable Response type, so a null return raises an
		// uncaught TypeError ("null returned") that becomes a hard 500 on ANY
		// unowned exception (this masked every non-ZGW controller error,
		// e.g. the POST /transition endpoint). Re-throw so the dispatcher
		// offers the exception to the next middleware / NC's core handler.
		throw $exception;
	}//end afterException()

	/**
	 * Enforce ZGW scope-based authorization.
	 *
	 * The ZGW component is derived from the request URL path, which always
	 * contains the API group name as the third path segment after "/api/zgw/".
	 * For example: /index.php/apps/dossiq/api/zgw/zaken/v1/zaken → "zaken".
	 * This replaces the dead `getParam('zgwApi')` lookup: no route in
	 * appinfo/routes.php declares a {zgwApi} placeholder, so that call always
	 * returned '' and the middleware short-circuited to 403 for every non-
	 * superuser request (SB1 from wave-11 calibration review).
	 *
	 * Fail-closed: if the component cannot be derived from the URL, deny.
	 *
	 * @param array $authConfig The consumer's authorization configuration
	 *
	 * @return void
	 *
	 * @throws ZgwAuthException If the scope check fails.
	 */
	private function enforceScopes(array $authConfig): void {
		$scopes = $authConfig['scopes'] ?? [];
		$method = $this->request->getMethod();
		$component = $this->deriveComponentFromUrl();

		if ($component === null) {
			throw $this->forbidden(message: 'Unknown API component');
		}

		$requiredSuffix = self::METHOD_TO_SCOPE[$method] ?? null;
		if ($requiredSuffix === null) {
			throw $this->forbidden(message: 'Unsupported HTTP method');
		}

		// Check if any scope grants cover this request.
		foreach ($scopes as $scopeGrant) {
			if ($this->scopeGrantCovers(
				scopeGrant: $scopeGrant,
				component: $component,
				requiredSuffix: $requiredSuffix
			) === true
			) {
				return;
			}
		}

		throw $this->forbidden(message: "Scope '{$component}.{$requiredSuffix}' is required for this operation");
	}//end enforceScopes()

	/**
	 * Check if a scope grant covers the required component and action.
	 *
	 * @param array $scopeGrant The scope grant configuration
	 * @param string $component The ZGW component code (zrc, ztc, etc.)
	 * @param string $requiredSuffix The required scope suffix (lezen, aanmaken, etc.)
	 *
	 * @return bool True if the scope grant covers the request
	 */
	private function scopeGrantCovers(
		array $scopeGrant,
		string $component,
		string $requiredSuffix,
	): bool {
		// Check component match.
		if (($scopeGrant['component'] ?? '') !== $component) {
			return false;
		}

		// Check scope includes the required action with the correct resource prefix.
		// A grant "besluiten.aanmaken" must NOT satisfy a ZRC "zaken.aanmaken" request;
		// verify that both the suffix (action) AND the prefix (resource) match.
		$expectedPrefix = self::COMPONENT_SCOPE_PREFIX[$component] ?? $component;
		$grantedScopes = $scopeGrant['scopes'] ?? [];

		foreach ($grantedScopes as $scope) {
			$parts = explode(separator: '.', string: $scope);
			if (count(value: $parts) === 2
				&& $parts[0] === $expectedPrefix
				&& $parts[1] === $requiredSuffix
			) {
				return true;
			}
		}

		return false;
	}//end scopeGrantCovers()

	/**
	 * Derive the ZGW component code from the request URL path.
	 *
	 * Dossiq ZGW routes all follow the pattern:
	 *   /apps/dossiq/api/zgw/{apiGroup}/v1/...
	 * (or with index.php prefix in some NC configurations)
	 *
	 * The API group name ("zaken", "catalogi", "besluiten", etc.) is extracted
	 * via regex and mapped to its component code via API_TO_COMPONENT.
	 *
	 * Returns null (fail-closed) if the URL does not match the expected pattern
	 * or if the extracted group is not a known ZGW API group.
	 *
	 * @return string|null The component code (e.g. 'zrc'), or null if unknown.
	 */
	private function deriveComponentFromUrl(): ?string {
		$uri = $this->request->getRequestUri();

		// Match /api/zgw/{apiGroup}/v1/... anywhere in the path.
		if (preg_match('#/api/zgw/([^/]+)/v1#', $uri, $matches) !== 1) {
			$this->logger->warning(
				'ZgwAuthMiddleware: could not derive ZGW API group from URI',
				['uri' => $uri]
			);
			return null;
		}

		$apiGroup = $matches[1];
		return self::API_TO_COMPONENT[$apiGroup] ?? null;
	}//end deriveComponentFromUrl()

	/**
	 * Compare confidentiality levels.
	 *
	 * @param string $actual The actual confidentiality level
	 * @param string $max The maximum allowed level
	 *
	 * @return bool True if actual is at or below max
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function isConfidentialityAllowed(string $actual, string $max): bool {
		$actualIndex = array_search(needle: $actual, haystack: self::CONFIDENTIALITY_ORDER);
		$maxIndex = array_search(needle: $max, haystack: self::CONFIDENTIALITY_ORDER);

		if ($actualIndex === false || $maxIndex === false) {
			return false;
		}

		return $actualIndex <= $maxIndex;
	}//end isConfidentialityAllowed()
}//end class
