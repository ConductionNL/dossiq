<?php

/**
 * ZGW Authentication Middleware
 *
 * Validates JWT tokens and enforces scopes on all ZGW API endpoints.
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
use OCA\Dossiq\Kernel\Contracts\ZgwJwtValidatorInterface;
use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Middleware;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Middleware that validates JWT tokens and enforces ZGW scopes.
 *
 * Applied to all ZgwController requests. Validates the Authorization header,
 * checks JWT signature via OpenRegister's AuthorizationService, and verifies
 * the authenticated applicatie has the required scope for the request.
 *
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
	 * The ZGW JWT validator.
	 *
	 * @var ZgwJwtValidatorInterface
	 */
	private ZgwJwtValidatorInterface $jwtValidator;

	/**
	 * The OpenRegister ConsumerMapper (loaded dynamically).
	 *
	 * @var object|null
	 */
	private $consumerMapper = null;

	/**
	 * Constructor.
	 *
	 * @param IRequest $request The incoming request
	 * @param ZgwJwtValidatorInterface $jwtValidator The ZGW JWT validator
	 * @param LoggerInterface $logger The logger
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IRequest $request,
		ZgwJwtValidatorInterface $jwtValidator,
		private readonly LoggerInterface $logger,
	) {
		$this->jwtValidator = $jwtValidator;
		$this->loadOpenRegisterServices();
	}//end __construct()

	/**
	 * Load OpenRegister services dynamically.
	 *
	 * @return void
	 */
	private function loadOpenRegisterServices(): void {
		try {
			$container = \OC::$server;
			$this->consumerMapper = $container->get(
				'OCA\OpenRegister\Db\ConsumerMapper'
			);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ZgwAuthMiddleware: OpenRegister auth services not available',
				['exception' => $e->getMessage()]
			);
		}
	}//end loadOpenRegisterServices()

	/**
	 * Validate JWT and enforce scopes before controller execution.
	 *
	 * @param \OCP\AppFramework\Controller $controller The controller instance
	 * @param string $methodName The method being called
	 *
	 * @return void
	 *
	 * @throws \OCA\Dossiq\Middleware\ZgwAuthException If authorization fails.
	 *
	 * @SuppressWarnings(PHPMD.UnusedFormalParameter) — $methodName required by Middleware interface
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	public function beforeController($controller, $methodName): void {
		if (($controller instanceof ZgwController) === false) {
			return;
		}

		$authorization = $this->request->getHeader(name: 'Authorization');
		$issuer = $this->verifiedIssuer(authorization: $authorization);
		$authConfig = $this->consumerConfiguration(issuer: $issuer);

		// Superuser bypasses all scope checks.
		if (($authConfig['superuser'] ?? false) === true) {
			return;
		}

		// Enforce scope-based authorization.
		$this->enforceScopes(authConfig: $authConfig);
	}//end beforeController()

	/**
	 * The issuer of a present, well-formed token whose signature validates.
	 *
	 * @param string $authorization The Authorization header.
	 *
	 * @return mixed The `iss` claim.
	 *
	 * @throws ZgwAuthException When the header is missing, the payload unreadable or the signature invalid.
	 *
	 * @spec openspec/specs/zgw-api-mapping/spec.md
	 */
	private function verifiedIssuer(string $authorization): mixed {
		$payload = $this->tokenPayload(authorization: $authorization);
		$this->validateSignature(authorization: $authorization);
		return $payload['iss'];
	}//end verifiedIssuer()

	/**
	 * The token's payload, when the header carries one with an issuer.
	 *
	 * @param string $authorization The Authorization header.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ZgwAuthException When the header is missing or the payload has no issuer.
	 */
	private function tokenPayload(string $authorization): array {
		if ($authorization === '') {
			throw $this->forbidden(message: 'Authorization header is required');
		}

		// Extract and validate JWT payload.
		$payload = $this->decodeJwtPayload(token: substr(string: $authorization, offset: strlen(string: 'Bearer ')));
		if ($payload === null || isset($payload['iss']) === false) {
			throw $this->forbidden(message: 'Invalid token payload');
		}

		return $payload;
	}//end tokenPayload()

	/**
	 * Validate the JWT signature via the dossiq-owned ZgwJwtValidator.
	 *
	 * M3: Log detailed message server-side; surface only a generic message to caller.
	 * Catch \Throwable: a misconfigured dependency raises \Error (not \Exception),
	 * which previously escaped as a 500 instead of a clean 403.
	 *
	 * @param string $authorization The Authorization header.
	 *
	 * @return void
	 *
	 * @throws ZgwAuthException When the signature does not validate.
	 */
	private function validateSignature(string $authorization): void {
		try {
			$this->jwtValidator->validate(authorization: $authorization);
		} catch (\Throwable $e) {
			$this->logger->warning(
				'ZGW auth failed: ' . $e->getMessage()
			);
			throw $this->forbidden(message: 'Authenticatiegegevens zijn niet geldig.');
		}
	}//end validateSignature()

	/**
	 * The authorization configuration of the consumer the issuer names.
	 *
	 * @param mixed $issuer The token's issuer.
	 *
	 * @return array<string, mixed>
	 *
	 * @throws ZgwAuthException When no consumer answers to the issuer.
	 */
	private function consumerConfiguration(mixed $issuer): array {
		$consumer = $this->findConsumerByIssuer(issuer: $issuer);
		if ($consumer === null) {
			throw $this->forbidden(message: 'Unknown issuer');
		}

		return $consumer->getAuthorizationConfiguration();
	}//end consumerConfiguration()

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
	 * Decode the JWT payload without verification (already verified by authorizeJwt).
	 *
	 * @param string $token The JWT token string
	 *
	 * @return array|null The decoded payload or null on failure
	 */
	private function decodeJwtPayload(string $token): ?array {
		$parts = explode(separator: '.', string: $token);
		if (count(value: $parts) !== 3) {
			return null;
		}

		$payload = base64_decode(string: $parts[1], strict: true);
		if ($payload === false) {
			return null;
		}

		$decoded = json_decode(json: $payload, associative: true);
		if (is_array(value: $decoded) === false) {
			return null;
		}

		return $decoded;
	}//end decodeJwtPayload()

	/**
	 * Find a Consumer entity by its issuer name.
	 *
	 * @param string $issuer The JWT issuer (maps to Consumer name)
	 *
	 * @return object|null The Consumer entity or null
	 */
	private function findConsumerByIssuer(string $issuer): ?object {
		try {
			$consumers = $this->consumerMapper->findAll(
				filters: ['name' => $issuer]
			);
			if (count(value: $consumers) > 0) {
				return $consumers[0];
			}
		} catch (\Exception $e) {
			$this->logger->warning(
				'Failed to find consumer for issuer: ' . $issuer,
				['exception' => $e->getMessage()]
			);
		}

		return null;
	}//end findConsumerByIssuer()

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
