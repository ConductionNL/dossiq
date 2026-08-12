<?php

/**
 * Procest Owning-Case Resolver.
 *
 * Resolves the case that a child object belongs to, so an endpoint whose route
 * carries only a child id can still be authorised per case.
 *
 * Several `#[NoAdminRequired]` routes name a child object rather than a case —
 * `POST /api/appointments/{appointmentId}/no-show`,
 * `GET /api/berichtenbox/messages/{messageId}`,
 * `POST /api/bezwaar/hearings/{sessionId}/attendance`. There is nothing in
 * those signatures to authorise against, so each one first resolves the owning
 * case and then applies the ordinary `CaseAccessGuard` check. Three services
 * needed exactly the same lookup; it lives here once rather than three times.
 *
 * FAILS CLOSED. Every branch that cannot establish the owning case returns
 * null, and every caller treats null as DENY — so an unknown or unresolvable
 * child id is refused identically to one the caller may not touch, and none of
 * these endpoints is an existence oracle.
 *
 * @category Service
 * @package  OCA\Procest\Service\Support
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Support;

use OCA\Procest\Service\SettingsService;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolves the owning case of a child object, failing closed.
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */
class OwningCaseResolver
{
    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Register/schema resolver.
     * @param LoggerInterface $logger          The logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Resolve the case UUID a child object belongs to.
     *
     * @param string $objectId  The child object UUID.
     * @param string $schemaKey The settings key naming the child's schema.
     * @param string $caseField The property on the child holding the case ref.
     *
     * @return string|null The owning case UUID, or null when unresolvable.
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function resolve(string $objectId, string $schemaKey, string $caseField='caseId'): ?string
    {
        $data = $this->loadChild(objectId: $objectId, schemaKey: $schemaKey);
        if ($data === null) {
            return null;
        }

        $caseId = (string) ($data[$caseField] ?? '');
        if ($caseId === '') {
            return null;
        }

        return $caseId;
    }//end resolve()

    /**
     * Resolve the owning case across two hops.
     *
     * Some child objects do not reference their case directly. A
     * `dwangsomBerekening`, for example, carries only `termijnInstance`, and it
     * is the `termijnInstance` that carries `zaak`. Guarding those endpoints
     * needs the whole chain, and every unresolvable link must DENY rather than
     * fall through — a missing intermediate is not permission.
     *
     * @param string $objectId    The child object UUID.
     * @param string $schemaKey   The settings key naming the child's schema.
     * @param string $linkField   The property on the child holding the intermediate ref.
     * @param string $viaSchemaKey The settings key naming the intermediate's schema.
     * @param string $caseField   The property on the intermediate holding the case ref.
     *
     * @return string|null The owning case UUID, or null when unresolvable.
     *
     * @spec openspec/specs/authz-bypass-fixes/spec.md
     */
    public function resolveVia(
        string $objectId,
        string $schemaKey,
        string $linkField,
        string $viaSchemaKey,
        string $caseField='zaak'
    ): ?string {
        $child = $this->loadChild(objectId: $objectId, schemaKey: $schemaKey);
        if ($child === null) {
            return null;
        }

        $intermediateId = (string) ($child[$linkField] ?? '');
        if ($intermediateId === '') {
            return null;
        }

        return $this->resolve(
            objectId: $intermediateId,
            schemaKey: $viaSchemaKey,
            caseField: $caseField
        );
    }//end resolveVia()

    /**
     * Load the child object as a plain array.
     *
     * @param string $objectId  The child object UUID.
     * @param string $schemaKey The settings key naming the child's schema.
     *
     * @return array<string, mixed>|null The child object, or null when unresolvable.
     */
    private function loadChild(string $objectId, string $schemaKey): ?array
    {
        if ($objectId === '') {
            return null;
        }

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->settingsService->getConfigValue($schemaKey);
        if ($register === '' || $schema === '') {
            return null;
        }

        try {
            $object = $objectService->find($objectId, register: (int) $register, schema: (int) $schema);
        } catch (Throwable $e) {
            $this->logger->warning(
                'Procest: owning-case lookup failed — denying: '.$e->getMessage()
            );
            return null;
        }

        return $this->toArray(value: $object);
    }//end loadChild()

    /**
     * Normalise an OpenRegister result into a plain array.
     *
     * `is_callable()` rather than `method_exists()`: OpenRegister's
     * `ObjectEntity` reaches several accessors through `Entity::__call()`, and
     * `method_exists()` is false for those.
     *
     * @param mixed $value The value returned by ObjectService.
     *
     * @return array<string, mixed>|null The array form, or null.
     */
    private function toArray(mixed $value): ?array
    {
        if (is_array($value) === true) {
            return $value;
        }

        if (is_object($value) === false || is_callable([$value, 'jsonSerialize']) === false) {
            return null;
        }

        $serialised = $value->jsonSerialize();
        if (is_array($serialised) === false) {
            return null;
        }

        return $serialised;
    }//end toArray()
}//end class
