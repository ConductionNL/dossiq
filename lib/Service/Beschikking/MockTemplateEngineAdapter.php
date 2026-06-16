<?php

/**
 * Procest Mock Template-Engine Adapter.
 *
 * Deterministic, dependency-free stand-in for the Docudesk template-engine.
 * Used until the real OpenConnector/Docudesk render endpoint (task T26) is
 * wired in. Produces stable composition metadata so the Procest pipeline and
 * its tests run without a live Docudesk instance.
 *
 * @category Service
 * @package  OCA\Procest\Service\Beschikking
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
 * @link https://procest.nl
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T26
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Beschikking;

/**
 * Mock implementation of the template-engine adapter.
 *
 * @spec openspec/changes/beschikking-generatie/tasks.md#T26
 */
class MockTemplateEngineAdapter implements TemplateEngineAdapterInterface
{
    /**
     * {@inheritDoc}
     *
     * @param string               $templateId The template identifier.
     * @param array<string, mixed> $context    The render context.
     *
     * @return array{format: string, bestandId: string, checksumSha256: string, paginas: int}
     *
     * @spec openspec/changes/beschikking-generatie/tasks.md#T26
     */
    public function render(string $templateId, array $context): array
    {
        $payload = json_encode([$templateId, $context], JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            $payload = $templateId;
        }

        return [
            'format'         => 'pdf-a3',
            'bestandId'      => 'doc-'.substr(hash('sha256', $payload), 0, 12),
            'checksumSha256' => hash('sha256', $payload),
            'paginas'        => 4,
        ];
    }//end render()

    /**
     * {@inheritDoc}
     *
     * @param string $templateId    The template identifier.
     * @param string $effectiveDate The effective date.
     *
     * @return array{templateId: string, version: string, ingangsdatum: string}
     *
     * @spec openspec/changes/beschikking-generatie/tasks.md#T26
     */
    public function resolveVersion(string $templateId, string $effectiveDate): array
    {
        return [
            'templateId'   => $templateId,
            'version'      => 'v1',
            'ingangsdatum' => $effectiveDate,
        ];
    }//end resolveVersion()
}//end class
