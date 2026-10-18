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
			$examples[$index]['rows'] = $dates->fill(rows: (array)($example['rows'] ?? []));
		}

		return $examples;
	}//end toWrite()
}//end class
