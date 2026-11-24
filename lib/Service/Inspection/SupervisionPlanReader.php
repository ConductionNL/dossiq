<?php

/**
 * Reads a supervision plan, its categories and their inspections, in the reader's scope.
 *
 * Every search runs with the signed-in user's own access, so an inspection a
 * reader may not see is not counted for them either. A failing read is
 * logged here and answered as a status, so the controller only maps it.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Inspection
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Inspection;

use OCA\Dossiq\Kernel\Contracts\SettingsServiceInterface;
use OCA\Dossiq\Service\Support\SearchesObjects;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * One plan with its categories and, when asked, their inspections.
 *
 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
 */
class SupervisionPlanReader {
	use SearchesObjects;

	public const FOUND = 200;

	public const NOT_FOUND = 404;

	public const BROKEN = 500;

	public const UNCONFIGURED = 503;

	/**
	 * The app config keys of the three schemas (SchemaSlugMap).
	 */
	private const SCHEMA_KEYS = [
		'plan'       => 'supervision_plan_schema',
		'category'   => 'supervision_plan_category_schema',
		'inspection' => 'field_inspection_schema',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsServiceInterface $settings The object service, the register and the schemas.
	 * @param SupervisionPlanProgress  $progress Record ids.
	 * @param LoggerInterface          $logger   The logger.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
	 */
	public function __construct(
		private readonly SettingsServiceInterface $settings,
		private readonly SupervisionPlanProgress $progress,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The plan, its categories and, when asked, the inspections naming them.
	 *
	 * @param string $id              The supervisionPlan uuid.
	 * @param bool   $withInspections Whether to read the inspections too.
	 *
	 * @return array<string, mixed> `status` FOUND with `plan`, `categories` and, when asked,
	 *                              `inspections`; or only NOT_FOUND, UNCONFIGURED or BROKEN.
	 *
	 * @spec openspec/changes/supervision-plan-page/tasks.md#task-1
	 */
	public function read(string $id, bool $withInspections): array {
		$objectService = $this->settings->getObjectService();
		$register      = $this->settings->getConfigValue('register');
		$schemas       = array_map(fn (string $key): string => $this->settings->getConfigValue($key), self::SCHEMA_KEYS);
		if ($objectService === null || $register === '' || in_array('', $schemas, true) === true) {
			return ['status' => self::UNCONFIGURED];
		}

		try {
			$plan = $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schemas['plan'], id: $id);
			if ($plan === null) {
				return ['status' => self::NOT_FOUND];
			}

			$categories = $this->searchObjectsAsArrays(
				objectService: $objectService,
				register: $register,
				schema: $schemas['category'],
				filters: ['plan' => $id]
			);
			$read       = ['status' => self::FOUND, 'plan' => $plan, 'categories' => $categories];
			if ($withInspections === true) {
				$read['inspections'] = $this->inspectionsOf(
					objectService: $objectService,
					register: $register,
					schema: $schemas['inspection'],
					categories: $categories
				);
			}

			return $read;
		} catch (Throwable $e) {
			$this->logger->error('dossiq: the supervision plan could not be read', ['exception' => $e, 'plan' => $id]);
			return ['status' => self::BROKEN];
		}
	}//end read()

	/**
	 * The field inspections that name one of the categories.
	 *
	 * @param object                     $objectService The object service.
	 * @param string                     $register      The register.
	 * @param string                     $schema        The field inspection schema.
	 * @param list<array<string, mixed>> $categories    The categories.
	 *
	 * @return list<array<string, mixed>> The inspections.
	 */
	private function inspectionsOf(object $objectService, string $register, string $schema, array $categories): array {
		$inspections = [];
		foreach ($categories as $category) {
			$categoryId = $this->progress->idOf(record: $category);
			if ($categoryId !== '') {
				$found       = $this->searchObjectsAsArrays(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					filters: ['planCategory' => $categoryId]
				);
				$inspections = array_merge($inspections, $found);
			}
		}

		return $inspections;
	}//end inspectionsOf()
}//end class
