<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category  Sections
 * @package   OCA\Procest\Sections
 * @author    Conduction B.V. <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2
 * @link      https://github.com/ConductionNL/dossiq
 */

declare(strict_types=1);

namespace OCA\Procest\Sections;

use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

/**
 * Personal-settings section for Procest.
 *
 * A separate class from SettingsSection because Nextcloud keeps the admin and
 * personal section registries apart: reusing the admin section id for a
 * personal form leaves the form registered against a section the personal
 * settings page cannot resolve, and it renders nowhere.
 *
 * @spec openspec/changes/page-topology-cleanup/specs/personal-settings-surface/spec.md
 */
class PersonalSection implements IIconSection {


    /**
     * Constructor.
     *
     * @param IL10N         $l            The localisation service.
     * @param IURLGenerator $urlGenerator The URL generator.
     *
     * @return void
     */
    public function __construct(
        private IL10N $l,
        private IURLGenerator $urlGenerator,
    ) {

    }//end __construct()


    /**
     * Get the section id.
     *
     * @return string The section id.
     */
    public function getID(): string {
        return 'procest';

    }//end getID()


    /**
     * Get the section display name.
     *
     * @return string The translated section name.
     */
    public function getName(): string {
        return $this->l->t('Procest');

    }//end getName()


    /**
     * Get the ordering priority.
     *
     * @return int The priority.
     */
    public function getPriority(): int {
        return 75;

    }//end getPriority()


    /**
     * Get the icon path for this section.
     *
     * @return string The icon path.
     */
    public function getIcon(): string {
        return $this->urlGenerator->imagePath(appName: 'procest', file: 'app-dark.svg');

    }//end getIcon()


}//end class
