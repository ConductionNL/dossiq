<?php

/**
 * Which template adapter answers for a configured value.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Beschikking
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/beschikking-renders-through-filinq-when-installed/tasks.md#1-the-default
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Beschikking;

use OCA\Dossiq\Support\FleetAppId;
use OCP\App\IAppManager;

/**
 * ONE RULE FOR THE SEAM AND THE CARD. A named class is what the admin chose;
 * an empty `beschikking_template_adapter` binds filinq's adapter when filinq is
 * enabled and the mock otherwise. The template seam
 * ({@see \OCA\Dossiq\AppInfo\Registrar\SubstitutableAdapterRegistrar}) and the
 * Document templates card ({@see \OCA\Dossiq\Service\IntegrationStatusService})
 * both ask this class, so the card cannot read Live while the seam binds the
 * mock. The probe goes through FleetAppId, never a literal app id: filinq
 * renamed from docudesk and both names are in the field.
 *
 * @spec openspec/changes/beschikking-renders-through-filinq-when-installed/tasks.md#1-the-default
 */
class TemplateAdapterChoice {

	/**
	 * Constructor.
	 *
	 * @param IAppManager $appManager Tells whether filinq is enabled.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
	) {
	}//end __construct()

	/**
	 * The adapter class that answers for a configured value.
	 *
	 * @param string $named The value of `beschikking_template_adapter`.
	 *
	 * @return string The adapter class.
	 *
	 * @spec openspec/changes/beschikking-renders-through-filinq-when-installed/tasks.md#1-the-default
	 */
	public function adapterFor(string $named): string {
		$named = trim($named);
		if ($named !== '') {
			return $named;
		}

		if (FleetAppId::isEnabledForUser(appManager: $this->appManager, canonical: 'filinq') === true) {
			return FilinqTemplateEngineAdapter::class;
		}

		return MockTemplateEngineAdapter::class;
	}//end adapterFor()
}//end class
