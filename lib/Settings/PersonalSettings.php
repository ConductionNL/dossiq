<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Settings
 * @package   OCA\Procest\Settings
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Procest\Settings;

use OCA\Procest\AppInfo\Application;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\Settings\ISettings;

/**
 * Personal settings registration for self-service substitution (vervanging).
 *
 * Registering your own absence is a personal preference, not an app workspace:
 * it concerns exactly one user's own record and nobody else's work. It shipped
 * as a top-level app page with its own entry in the daily navigation, which put
 * a one-user setting beside the case list.
 *
 * The COORDINATOR console (/substitution-admin) is deliberately NOT moved here.
 * It registers substitutions on behalf of others, revokes them and reassigns
 * work in bulk — it operates on other people's records, so it is not a personal
 * setting and stays an app page behind its coordinator-role check.
 *
 * Scoping is enforced server-side by SubstitutionAccessGuard::listVisibleTo():
 * a non-coordinator only ever receives rows where they are the absentee or the
 * substitute. This form does not widen that.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
 */
class PersonalSettings implements ISettings {

    /**
     * Get the settings form template.
     *
     * @return TemplateResponse The rendered personal-settings form.
     *
     * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
     */
    public function getForm(): TemplateResponse {
        return new TemplateResponse(
            Application::APP_ID,
            'settings/personal',
            []
        );

    }//end getForm()


    /**
     * Get the section this form belongs to.
     *
     * @return string The personal-settings section id.
     *
     * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
     */
    public function getSection(): string {
        return 'procest';

    }//end getSection()


    /**
     * Get the priority for ordering within the section.
     *
     * @return int The ordering priority.
     *
     * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
     */
    public function getPriority(): int {
        return 50;

    }//end getPriority()


}//end class
