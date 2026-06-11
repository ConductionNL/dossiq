<?php

/**
 * Procest MapLayerService
 *
 * CRUD service for `mapLayer` configuration objects. A mapLayer describes
 * a single overlay/base layer that can be rendered on the GIS dashboards:
 * its title, type (tile/wms/wfs/geojson), URL template, attribution,
 * opacity, ordering, base-vs-overlay flag, and visibility. Layers are
 * persisted in OpenRegister so admins can edit them at runtime without
 * touching app config or rebuilding the bundle.
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
 * @spec openspec/changes/gis-integration/tasks.md#TASK-GIS-03
 */

declare(strict_types=1);

namespace OCA\Procest\Service;

use OCA\Procest\AppInfo\Application;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Service for mapLayer CRUD operations.
 */
class MapLayerService
{
    /**
     * Recognised layer types.
     */
    public const TYPES = ['tile', 'wms', 'wfs', 'geojson'];

    /**
     * Constructor.
     *
     * @param SettingsService $settingsService Settings + register/schema resolver.
     * @param LoggerInterface $logger          Logger.
     */
    public function __construct(
        private readonly SettingsService $settingsService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * List all configured map layers, ordered by `order` ascending.
     *
     * @param array<string, mixed> $filters Optional filters: { type?, isBase?, isActive? }.
     *
     * @return array<int, array<string, mixed>> The layers list.
     *
     * @spec openspec/changes/gis-integration/tasks.md#TASK-GIS-03
     */
    public function listLayers(array $filters=[]): array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return [];
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->resolveMapLayerSchema();
        if ($schema === '' || $register === '') {
            return [];
        }

        $filterArr = [];
        if (isset($filters['type']) === true && in_array($filters['type'], self::TYPES, true) === true) {
            $filterArr['type'] = $filters['type'];
        }
        if (isset($filters['isBase']) === true) {
            $filterArr['isBase'] = (bool) $filters['isBase'];
        }
        if (isset($filters['isActive']) === true) {
            $filterArr['isActive'] = (bool) $filters['isActive'];
        }

        try {
            $results = $objectService->findAll(
                register: $register,
                schema: $schema,
                filters: $filterArr
            );
        } catch (Throwable $e) {
            $this->logger->error(
                'MapLayerService::listLayers failed: '.$e->getMessage(),
                ['app' => Application::APP_ID]
            );
            return [];
        }

        $list = [];
        foreach ((array) $results as $r) {
            $list[] = $this->normalize($r);
        }

        usort(
            $list,
            static function (array $a, array $b): int {
                return ((int) ($a['order'] ?? 0)) <=> ((int) ($b['order'] ?? 0));
            }
        );

        return $list;
    }//end listLayers()

    /**
     * Fetch a single layer by id.
     *
     * @param string $id The layer id.
     *
     * @return array<string, mixed>|null The layer or null when not found.
     *
     * @spec openspec/changes/gis-integration/tasks.md#TASK-GIS-03
     */
    public function getLayer(string $id): ?array
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            return null;
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->resolveMapLayerSchema();
        if ($schema === '' || $register === '') {
            return null;
        }

        try {
            $obj = $objectService->find(id: $id, register: $register, schema: $schema);
        } catch (Throwable $e) {
            $this->logger->error(
                'MapLayerService::getLayer failed: '.$e->getMessage(),
                ['app' => Application::APP_ID, 'id' => $id]
            );
            return null;
        }

        if ($obj === null) {
            return null;
        }
        return $this->normalize($obj);
    }//end getLayer()

    /**
     * Create a new map layer.
     *
     * @param array<string, mixed> $payload The layer payload.
     *
     * @return array<string, mixed> The created layer.
     *
     * @throws \InvalidArgumentException When payload validation fails.
     * @throws \RuntimeException        When OR is unavailable.
     *
     * @spec openspec/changes/gis-integration/tasks.md#TASK-GIS-03
     */
    public function createLayer(array $payload): array
    {
        $this->validatePayload(payload: $payload, requireUrl: true);

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->resolveMapLayerSchema();

        $payload['isActive'] = $payload['isActive'] ?? true;

        $obj = $objectService->saveObject(
            object: $payload,
            register: $register,
            schema: $schema,
        );

        return $this->normalize($obj);
    }//end createLayer()

    /**
     * Update an existing map layer.
     *
     * @param string               $id      The layer id.
     * @param array<string, mixed> $payload The patch payload (partial update).
     *
     * @return array<string, mixed> The updated layer.
     *
     * @throws \InvalidArgumentException When payload validation fails.
     * @throws \RuntimeException        When OR is unavailable or layer not found.
     *
     * @spec openspec/changes/gis-integration/tasks.md#TASK-GIS-03
     */
    public function updateLayer(string $id, array $payload): array
    {
        $existing = $this->getLayer(id: $id);
        if ($existing === null) {
            throw new \RuntimeException('Map layer not found: '.$id);
        }

        $merged = array_merge($existing, $payload);
        $this->validatePayload(payload: $merged, requireUrl: false);

        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->resolveMapLayerSchema();

        $obj = $objectService->saveObject(
            object: $merged,
            register: $register,
            schema: $schema,
        );

        return $this->normalize($obj);
    }//end updateLayer()

    /**
     * Delete a map layer by id.
     *
     * @param string $id The layer id.
     *
     * @return bool True on success.
     *
     * @throws \RuntimeException When OR is unavailable.
     *
     * @spec openspec/changes/gis-integration/tasks.md#TASK-GIS-03
     */
    public function deleteLayer(string $id): bool
    {
        $objectService = $this->settingsService->getObjectService();
        if ($objectService === null) {
            throw new \RuntimeException('OpenRegister is not available');
        }

        $register = $this->settingsService->getConfigValue('register');
        $schema   = $this->resolveMapLayerSchema();

        try {
            $objectService->deleteObject(id: $id, register: $register, schema: $schema);
        } catch (Throwable $e) {
            $this->logger->error(
                'MapLayerService::deleteLayer failed: '.$e->getMessage(),
                ['app' => Application::APP_ID, 'id' => $id]
            );
            return false;
        }

        return true;
    }//end deleteLayer()

    /**
     * Validate an inbound layer payload. Used by create + update.
     *
     * @param array<string, mixed> $payload    The (possibly merged) payload.
     * @param bool                 $requireUrl Whether the url field must be present.
     *
     * @return void
     *
     * @throws \InvalidArgumentException When the payload is invalid.
     *
     * @spec openspec/changes/gis-integration/tasks.md#TASK-GIS-03
     */
    public function validatePayload(array $payload, bool $requireUrl=true): void
    {
        $title = (string) ($payload['title'] ?? '');
        if ($title === '') {
            throw new \InvalidArgumentException('title is required');
        }

        $type = (string) ($payload['type'] ?? '');
        if (in_array($type, self::TYPES, true) === false) {
            throw new \InvalidArgumentException(
                'type must be one of: '.implode(', ', self::TYPES)
            );
        }

        $url = (string) ($payload['url'] ?? '');
        if ($requireUrl === true && $url === '') {
            throw new \InvalidArgumentException('url is required');
        }

        if ($url !== '') {
            // Tile templates may use {z}/{x}/{y} placeholders that aren't valid
            // URLs by themselves; accept either parseable URLs or templates
            // that contain {z} + {x} + {y}.
            $isTemplate = str_contains($url, '{z}')
                && str_contains($url, '{x}')
                && str_contains($url, '{y}');
            if ($isTemplate === false && filter_var($url, FILTER_VALIDATE_URL) === false) {
                throw new \InvalidArgumentException('url is not a valid URL or tile template');
            }
        }

        if (isset($payload['opacity']) === true) {
            $opacity = (float) $payload['opacity'];
            if ($opacity < 0.0 || $opacity > 1.0) {
                throw new \InvalidArgumentException('opacity must be in [0, 1]');
            }
        }
    }//end validatePayload()

    /**
     * Resolve the configured `mapLayer` schema id from settings.
     *
     * @return string The schema id or empty string when not configured.
     */
    private function resolveMapLayerSchema(): string
    {
        $schema = $this->settingsService->getConfigValue('map_layer_schema');
        if ($schema === '' || $schema === null) {
            return '';
        }
        return (string) $schema;
    }//end resolveMapLayerSchema()

    /**
     * Normalise a returned object (OR DTO or array) into a flat array.
     *
     * @param mixed $obj The raw object.
     *
     * @return array<string, mixed> The normalised array.
     */
    private function normalize(mixed $obj): array
    {
        if (is_array($obj) === true) {
            return $obj;
        }
        if (is_object($obj) === true && method_exists($obj, 'jsonSerialize') === true) {
            return $obj->jsonSerialize();
        }
        return (array) $obj;
    }//end normalize()
}//end class
