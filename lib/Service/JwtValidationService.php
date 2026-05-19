<?php

/**
 * JWT Validation Service
 *
 * Handles JWT-based authentication and consumer scope/authorisation checks
 * extracted from ZgwService to keep JWT logic in one place.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2024 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-2
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCP\AppFramework\Http;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/**
 * Service for validating JWT authentication and consumer authorisations.
 *
 * @psalm-suppress UnusedClass
 *
 * @spec openspec/changes/method-decomposition/tasks.md#task-2
 */
class JwtValidationService
{
    /**
     * Constructor.
     *
     * @param object|null     $consumerMapper       The OpenRegister ConsumerMapper (nullable)
     * @param object|null     $authorizationService The OpenRegister AuthorizationService (nullable)
     * @param LoggerInterface $logger               The logger
     *
     * @return void
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-2
     */
    public function __construct(
        private readonly ?object $consumerMapper,
        private readonly ?object $authorizationService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Validate JWT-ZGW authentication from the Authorization header.
     *
     * @param IRequest $request The request object
     *
     * @return JSONResponse|null 401 response on failure, null on success
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-2
     */
    public function validateJwtAuth(IRequest $request): ?JSONResponse
    {
        $authHeader = $request->getHeader('Authorization');

        if ($authHeader === '') {
            return new JSONResponse(
                data: [
                    'type'   => 'NotAuthenticated',
                    'code'   => 'not_authenticated',
                    'title'  => 'Authenticatiegegevens zijn niet opgegeven.',
                    'status' => 401,
                    'detail' => 'Authenticatiegegevens zijn niet opgegeven.',
                ],
                statusCode: Http::STATUS_UNAUTHORIZED
            );
        }

        try {
            $this->authorizationService->authorizeJwt(
                authorization: $authHeader
            );
        } catch (\Exception $e) {
            return new JSONResponse(
                data: [
                    'type'   => 'NotAuthenticated',
                    'code'   => 'not_authenticated',
                    'title'  => 'Authenticatiegegevens zijn niet geldig.',
                    'status' => 403,
                    'detail' => $e->getMessage(),
                ],
                statusCode: Http::STATUS_FORBIDDEN
            );
        }

        return null;
    }//end validateJwtAuth()

    /**
     * Check if the current JWT consumer has a specific scope.
     *
     * @param IRequest $request   The request object
     * @param string   $component The ZGW component (e.g. 'zrc', 'ztc', 'brc', 'drc')
     * @param string   $scope     The required scope
     *
     * @return bool True if the consumer has the scope or heeftAlleAutorisaties
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) — multiple JWT validation paths
     * @SuppressWarnings(PHPMD.NPathComplexity)      — multiple JWT validation paths
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-2
     */
    public function consumerHasScope(IRequest $request, string $component, string $scope): bool
    {
        if ($this->consumerMapper === null) {
            return true;
        }

        try {
            $authHeader = $request->getHeader('Authorization');
            $token      = str_replace('Bearer ', '', $authHeader);
            $parts      = explode('.', $token);
            if (count($parts) !== 3) {
                return true;
            }

            $payload  = json_decode(base64_decode($parts[1]), true);
            $clientId = $payload['client_id'] ?? ($payload['iss'] ?? null);
            if ($clientId === null) {
                return true;
            }

            $consumers = $this->consumerMapper->findAll(
                filters: ['name' => $clientId]
            );
            if (empty($consumers) === true) {
                return true;
            }

            $consumer   = $consumers[0];
            $authConfig = [];
            if (method_exists($consumer, 'getAuthorizationConfiguration') === true) {
                $authConfig = $consumer->getAuthorizationConfiguration() ?? [];
            }

            if (($authConfig['superuser'] ?? false) === true) {
                return true;
            }

            $scopes = $authConfig['scopes'] ?? [];
            foreach ($scopes as $auth) {
                $authComponent = $auth['component'] ?? '';
                $authScopes    = $auth['scopes'] ?? [];
                if ($authComponent === $component
                    && in_array($scope, $authScopes, true) === true
                ) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable $e) {
            $this->logger->warning(
                'Could not check consumer scope: '.$e->getMessage()
            );
            return true;
        }//end try
    }//end consumerHasScope()

    /**
     * Get the consumer's authorization details for a component (for zrc-006).
     *
     * Returns the authorization entries (autorisaties) for the given component,
     * or null if the consumer has full access (superuser / no restrictions).
     *
     * @param IRequest $request   The request object
     * @param string   $component The ZGW component (e.g. 'zrc')
     *
     * @return array|null Array of autorisatie entries, or null if unrestricted
     *
     * @SuppressWarnings(PHPMD.CyclomaticComplexity) — multiple JWT validation paths
     *
     * @spec openspec/changes/method-decomposition/tasks.md#task-2
     */
    public function getConsumerAuthorisaties(IRequest $request, string $component): ?array
    {
        if ($this->consumerMapper === null) {
            return null;
        }

        try {
            $authHeader = $request->getHeader('Authorization');
            $token      = str_replace('Bearer ', '', $authHeader);
            $parts      = explode('.', $token);
            if (count($parts) !== 3) {
                return null;
            }

            $payload  = json_decode(base64_decode($parts[1]), true);
            $clientId = $payload['client_id'] ?? ($payload['iss'] ?? null);
            if ($clientId === null) {
                return null;
            }

            $consumers = $this->consumerMapper->findAll(
                filters: ['name' => $clientId]
            );
            if (empty($consumers) === true) {
                return null;
            }

            $consumer   = $consumers[0];
            $authConfig = [];
            if (method_exists($consumer, 'getAuthorizationConfiguration') === true) {
                $authConfig = $consumer->getAuthorizationConfiguration() ?? [];
            }

            if (($authConfig['superuser'] ?? false) === true) {
                return null;
            }

            $result = [];
            $scopes = $authConfig['scopes'] ?? [];
            foreach ($scopes as $auth) {
                $authComponent = $auth['component'] ?? '';
                if ($authComponent === $component) {
                    $result[] = $auth;
                }
            }

            return $result;
        } catch (\Exception $e) {
            $this->logger->warning(
                'Could not get consumer autorisaties: '.$e->getMessage()
            );
            return [];
        }//end try
    }//end getConsumerAuthorisaties()
}//end class
