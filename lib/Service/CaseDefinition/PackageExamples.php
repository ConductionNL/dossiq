<?php

/**
 * The example rows of a package, on an instance that wants example data.
 *
 * A package's `examples/` files hold a case in flight, which is fake data. They
 * are written only when the operator chose to load example data in the setup
 * wizard (DemoDataService::DECIDED_KEY is `installed`); on any other instance,
 * a production install among them, they are skipped with one log line.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\CaseDefinition
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
 * @link https://conduction.nl
 *
 * @spec openspec/changes/package-examples/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\DemoDataService;
use OCA\Dossiq\Service\Settings\SchemaSlugMap;
use OCA\Dossiq\Service\Support\JsonEncodedStringProperties;
use OCP\IAppConfig;
use Psr\Log\LoggerInterface;

/**
 * Decides whether a package's example rows install, and fills their dates.
 *
 * @spec openspec/changes/package-examples/specs/case-types/spec.md
 */
final class PackageExamples {

	/**
	 * Constructor.
	 *
	 * @param IAppConfig      $appConfig Holds the setup wizard's example data decision.
	 * @param LoggerInterface $logger    Says when examples are skipped.
	 */
	public function __construct(
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The example files to write, their date tokens filled; none when this
	 * instance has not opted into example data.
	 *
	 * @param string                           $package  The package id, for the log line.
	 * @param array<int, array<string, mixed>> $examples The decoded example files.
	 *
	 * @return array<int, array<string, mixed>> The files to write.
	 *
	 * @spec openspec/changes/package-examples/specs/case-types/spec.md
	 */
	public function toWrite(string $package, array $examples): array {
		if ($examples === []) {
			return [];
		}

		$decided = $this->appConfig->getValueString(Application::APP_ID, DemoDataService::DECIDED_KEY, '');
		if ($decided !== DemoDataService::DECIDED_INSTALLED) {
			$this->logger->info(
				'Package {id}: example rows skipped, this instance has not opted into example data',
				['id' => $package]
			);

			return [];
		}

		$dates = new PackageExampleDates();
		foreach ($examples as $index => $example) {
			$rows = $dates->fill(rows: (array)($example['rows'] ?? []));
			$examples[$index]['rows'] = $this->encoded(rows: $rows, schema: ($example['schema'] ?? null));
		}

		return $examples;
	}//end toWrite()

	/**
	 * The rows with every property the register declares as a JSON-encoded
	 * string encoded, as {@see JsonEncodedStringProperties} lists them for the
	 * schema the file names. An example file writes `statusHistory` or
	 * `geometry` as readable JSON; the schema takes them as text.
	 *
	 * @param array<int|string, mixed> $rows   The rows.
	 * @param mixed                    $schema The file's `schema`: a settings key of this app, or another app's schema.
	 *
	 * @return array<int|string, mixed> The rows as the schema takes them.
	 *
	 * @spec openspec/specs/case-types/spec.md
	 */
	private function encoded(array $rows, mixed $schema): array {
		if (is_string($schema) === false) {
			return $rows;
		}

		$encoder = new JsonEncodedStringProperties();
		$slugs = array_keys(SchemaSlugMap::SLUG_TO_CONFIG_KEY, $schema, true);
		foreach ($rows as $index => $row) {
			foreach ($slugs as $slug) {
				$row = $encoder->reencode(object: (array)$row, schemaSlug: (string)$slug);
			}

			$rows[$index] = $row;
		}

		return $rows;
	}//end encoded()
}//end class
