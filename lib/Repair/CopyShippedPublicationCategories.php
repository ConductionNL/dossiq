<?php

/**
 * Dossiq repair step: shipped publication categories reach stored decision types
 *
 * A decision type names the information category its published decisions are
 * filed under (`publicationCategory`, decision 182, Q-R8-1). Until then the
 * category was a lookup table in code. A decision type the app ships carries
 * its category in the shipped register fragment; an instance that stored the
 * row before the property existed has the row without it. This step copies the
 * shipped category onto such a row, so each existing instance keeps the
 * category its decisions were published under.
 *
 * It writes only a row that has no complete category of its own, so an
 * administrator's choice survives, and a second run writes nothing.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\Publication\PublicationCategory;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Copies each shipped decision type's publication category onto its stored row. Idempotent, non-fatal.
 *
 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
 */
class CopyShippedPublicationCategories implements IRepairStep {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister and the decision type schema.
	 * @param LoggerInterface $logger          Logger.
	 * @param string          $fragmentDir     The shipped register fragments; '' for the app's own.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
		private readonly string $fragmentDir = '',
	) {
	}//end __construct()

	/**
	 * The step's name.
	 *
	 * @return string The name.
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
	 */
	public function getName(): string {
		return 'Copy the shipped publication categories onto stored decision types';
	}//end getName()

	/**
	 * Copy every missing category. Never throws.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/woo-publication-via-opencatalogi/spec.md#requirement-the-publication-carries-the-woo-journey-fields-req-wpi-007
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		$register = $this->settingsService->getConfigValue('register');
		$schema = $this->settingsService->getConfigValue('decision_type_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			$output->info('OpenRegister or the decision type schema is unavailable, skipping the publication categories.');
			return;
		}

		$copied = 0;
		$categories = new PublicationCategory();
		foreach ($this->shippedCategories(categories: $categories) as $id => $category) {
			try {
				$stored = (array)$this->runAsSystemIfAvailable(
					objectService: $objectService,
					operation: fn (): array => ($this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $id) ?? [])
				);
				if ($stored === [] || $categories->fromRow(row: $stored) !== null) {
					continue;
				}

				$this->runAsSystemIfAvailable(
					objectService: $objectService,
					operation: fn (): ?array => $this->patchObjectAsArray(
						objectService: $objectService,
						register: $register,
						schema: $schema,
						id: $id,
						changes: ['publicationCategory' => $category],
					)
				);
				$copied++;
			} catch (Throwable $e) {
				$this->logger->warning(
					'CopyShippedPublicationCategories: a decision type kept its row without a category',
					['decisionType' => $id, 'error' => $e->getMessage()]
				);
			}//end try
		}//end foreach

		$output->info("Copied the publication category onto {$copied} stored decision type(s).");
	}//end run()

	/**
	 * The complete categories the shipped fragments give their decision types, by row id.
	 *
	 * @param PublicationCategory $categories Reads a row's category.
	 *
	 * @return array<string, array{code: string, label: string, uri: string}>
	 */
	private function shippedCategories(PublicationCategory $categories): array {
		$dir = $this->fragmentDir;
		if ($dir === '') {
			$dir = dirname(__DIR__) . '/Settings/register.d';
		}

		$files = glob($dir . '/*.json');
		if ($files === false) {
			return [];
		}

		$found = [];
		foreach ($files as $file) {
			$fragment = json_decode((string)file_get_contents($file), true);
			foreach ((array)($fragment['components']['objects'] ?? []) as $row) {
				if (is_array($row) === false || ($row['@self']['schema'] ?? '') !== 'decisionType') {
					continue;
				}

				$id = (string)($row['id'] ?? '');
				$category = $categories->fromRow(row: $row);
				if ($id !== '' && $category !== null) {
					$found[$id] = $category;
				}
			}
		}

		return $found;
	}//end shippedCategories()
}//end class
