<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Workflow;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;

/**
 * Where a workflow backfill writes: the object service, the register and its schemas.
 *
 * @spec openspec/specs/workflow-definition-model/spec.md
 */
final class BackfillTarget {

	/**
	 * The register.
	 *
	 * @var string
	 */
	public readonly string $register;

	/**
	 * The schemas.
	 *
	 * @var BackfillSchemas
	 */
	public readonly BackfillSchemas $schemas;

	/**
	 * Read the configured register and schemas.
	 *
	 * @param object          $objectService OpenRegister's object service.
	 * @param SettingsServiceInterface $settings      The app settings.
	 */
	public function __construct(
		public readonly object $objectService,
		SettingsServiceInterface $settings,
	) {
		$this->register = $settings->getConfigValue('register');
		$this->schemas = new BackfillSchemas(
			caseType: $settings->getConfigValue('case_type_schema'),
			status: $settings->getConfigValue('status_type_schema'),
			template: $settings->getConfigValue('workflow_template_schema'),
			cases: $settings->getConfigValue('case_schema'),
		);
	}//end __construct()

	/**
	 * Whether the register and the case type, status type and workflow
	 * template schemas are all configured.
	 *
	 * @return bool True when the backfill can run.
	 *
	 * @spec openspec/specs/workflow-definition-model/spec.md
	 */
	public function isConfigured(): bool {
		return in_array('', [$this->register, $this->schemas->caseType, $this->schemas->status, $this->schemas->template], true) === false;
	}//end isConfigured()
}//end class
