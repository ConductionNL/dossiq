<?php

/**
 * Procest Supplier Scope Service
 *
 * Resolves the current supplier from a session token, lists supplier-scoped
 * resources, and guards cross-supplier access. Masks sensitive fields
 * (IBAN, email, phone) for audit logging.
 *
 * @category Service
 * @package  OCA\Procest\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://procest.nl
 *
 * @spec openspec/changes/leverancier-zaakportaal-04-supplier-scope-security/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

/**
 * Supplier scoping + masking helpers.
 */
class SupplierScopeService
{
    /**
     * Supplier-scoped schemas (all carry `supplierRef`).
     *
     * @var array<int, string>
     */
    public const SUPPLIER_SCHEMAS = ['supplierTender', 'supplierContract', 'supplierInvoice', 'supplierMessage', 'supplierKpi', 'supplierUser'];

    /**
     * Constructor.
     *
     * @param IAppManager        $appManager App manager.
     * @param ContainerInterface $container  Service container.
     * @param TenantJwtService   $jwt        Tenant JWT service.
     * @param LoggerInterface    $logger     Logger.
     */
    public function __construct(
        private readonly IAppManager $appManager,
        private readonly ContainerInterface $container,
        private readonly TenantJwtService $jwt,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the current supplier from a bearer token.
     *
     * @param string $bearer Authorization header value (`Bearer <jwt>`).
     *
     * @return array{supplierRef:string, supplierUserId:string, role:string}|null
     */
    public function resolveFromBearer(string $bearer): ?array
    {
        if (str_starts_with($bearer, 'Bearer ') === false) {
            return null;
        }

        $token = trim(substr($bearer, 7));
        try {
            $claims = $this->jwt->validate($token);
        } catch (Throwable $e) {
            return null;
        }

        $roles = (array) ($claims['roles'] ?? []);
        $role  = 'read_only';
        foreach ($roles as $r) {
            if (is_string($r) === true && str_starts_with($r, 'supplier:') === true) {
                $role = substr($r, strlen('supplier:'));
                break;
            }
        }

        return [
            'supplierRef'    => (string) ($claims['tenant_slug'] ?? ''),
            'supplierUserId' => (string) ($claims['sub'] ?? ''),
            'role'           => $role,
        ];
    }//end resolveFromBearer()

    /**
     * List supplier-scoped objects for a given schema.
     *
     * @param string               $supplierRef  Supplier UUID.
     * @param string               $schema       Schema slug.
     * @param array<string, mixed> $extraFilters Additional filters.
     *
     * @return array<int, array<string,mixed>>
     */
    public function listSupplierObjects(string $supplierRef, string $schema, array $extraFilters=[]): array
    {
        if (in_array($schema, self::SUPPLIER_SCHEMAS, true) === false) {
            throw new RuntimeException('Schema is not supplier-scoped: '.$schema);
        }

        $os = $this->getObjectService();
        if ($os === null) {
            return [];
        }

        try {
            $rows = $os->findAll(
                register: TenantSaasService::REGISTER,
                schema: $schema,
                limit: 200,
                offset: 0,
                filters: array_merge(['supplierRef' => $supplierRef], $extraFilters)
            );
            if (is_array($rows) === true) {
                return array_values($rows);
            }

            return [];
        } catch (Throwable $e) {
            return [];
        }
    }//end listSupplierObjects()

    /**
     * Validate cross-supplier access — returns true when allowed.
     *
     * @param array<string,mixed> $resource    Object row (must carry `supplierRef`).
     * @param string              $supplierRef Current supplier.
     *
     * @return bool
     */
    public function validateSupplierAccess(array $resource, string $supplierRef): bool
    {
        $own = (string) ($resource['supplierRef'] ?? '');
        return $own === $supplierRef && $own !== '';
    }//end validateSupplierAccess()

    /**
     * Mask a row's sensitive fields for audit logs.
     *
     * @param array<string,mixed> $row Row.
     *
     * @return array<string,mixed>
     */
    public function maskSensitive(array $row): array
    {
        if (isset($row['iban']) === true) {
            $row['iban'] = $this->maskIban(iban: (string) $row['iban']);
        }

        if (isset($row['email']) === true) {
            $row['email'] = $this->maskEmail(email: (string) $row['email']);
        }

        if (isset($row['phone']) === true) {
            $row['phone'] = $this->maskPhone(phone: (string) $row['phone']);
        }

        return $row;
    }//end maskSensitive()

    /**
     * Mask IBAN — show last 4 only.
     *
     * @param string $iban IBAN.
     *
     * @return string
     */
    public function maskIban(string $iban): string
    {
        $iban = str_replace(' ', '', $iban);
        if (strlen($iban) < 5) {
            return '****';
        }

        return str_repeat('*', strlen($iban) - 4).substr($iban, -4);
    }//end maskIban()

    /**
     * Mask email — keep domain only.
     *
     * @param string $email Email.
     *
     * @return string
     */
    public function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        if (count($parts) !== 2) {
            return '***';
        }

        return '***@'.$parts[1];
    }//end maskEmail()

    /**
     * Mask phone — keep last 3 digits.
     *
     * @param string $phone Phone.
     *
     * @return string
     */
    public function maskPhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone) ?? '';
        if (strlen($digits) < 4) {
            return '***';
        }

        return str_repeat('*', strlen($digits) - 3).substr($digits, -3);
    }//end maskPhone()

    /**
     * Resolve the OpenRegister ObjectService when available.
     *
     * @return mixed|null The ObjectService instance, or null when unavailable.
     */
    private function getObjectService()
    {
        $installed = $this->appManager->getInstalledApps();
        if (is_array($installed) === false || in_array('openregister', $installed, true) === false) {
            return null;
        }

        try {
            return $this->container->get('OCA\\OpenRegister\\Service\\ObjectService');
        } catch (Throwable $e) {
            return null;
        }
    }//end getObjectService()
}//end class
