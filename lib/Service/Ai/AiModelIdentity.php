<?php

/**
 * Procest AI model identity.
 *
 * Resolves the human-readable identifier of the currently configured AI model
 * (`<type>/<name>`) from app config. Stamped onto every oversight audit entry
 * and reported by the AI health check, so the Algoritmeregister trail always
 * says WHICH model produced a suggestion.
 *
 * Extracted from {@see \OCA\Procest\Service\AiService} so that the model
 * orchestration layer and the oversight layer ({@see AiAuditService}) read the
 * identifier from one place and can never drift on the config keys involved.
 *
 * @category Service
 * @package  OCA\Procest\Service\Ai
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/ai-oversight-log/tasks.md#1.1
 */

declare(strict_types=1);

namespace OCA\Procest\Service\Ai;

use OCA\Procest\AppInfo\Application;
use OCP\IAppConfig;

/**
 * Resolves the configured AI model identifier.
 *
 * @spec openspec/changes/ai-oversight-log/tasks.md#1.1
 */
class AiModelIdentity
{
    /**
     * Constructor.
     *
     * @param IAppConfig $appConfig The app configuration service.
     *
     * @return void
     */
    public function __construct(
        private IAppConfig $appConfig,
    ) {
    }//end __construct()

    /**
     * Get the configured AI model identifier.
     *
     * @return string The identifier in `<type>/<name>` form.
     *
     * @spec openspec/changes/ai-oversight-log/tasks.md#1.1
     */
    public function identifier(): string
    {
        $type = $this->appConfig->getValueString(Application::APP_ID, 'ai_model_type', 'local');
        $name = $this->appConfig->getValueString(Application::APP_ID, 'ai_model_name', 'unknown');

        return $type.'/'.$name;
    }//end identifier()
}//end class
