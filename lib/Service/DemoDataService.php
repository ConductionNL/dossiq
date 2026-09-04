<?php

/**
 * Installs this app's demo dataset on request (ADR-111).
 *
 * An app installed from the App Store opens on an empty list, and the only
 * question its first reader has is whether they can see it work. Answering it
 * requires data they cannot author, against a schema they do not know yet.
 *
 * This service imports `lib/Settings/dossiq_mock_register.json` — a `type: mock` descriptor
 * whose every object was generated from the schema that validates it —
 * through the same OpenRegister importer the app already uses for its real
 * configuration.
 *
 * 🔴 ON DEMAND ONLY, NEVER ON INSTALL. A mock register has no Repair step and
 * is not imported at boot: demo objects appearing unasked on a production
 * instance are indistinguishable from real records to everyone who did not
 * install it. The operator asks, through the setup walkthrough or `occ`.
 *
 * 🔴 AND `force: true`, DELIBERATELY. OpenRegister's importer version-gates a
 * non-forced import and SKIPS silently when the version has not moved. An
 * operator who clicks "install demo data" and is told it succeeded, on an
 * instance where nothing was written, has been lied to by a version compare.
 * The request is explicit, so the import is unconditional.
 *
 * @category Service
 * @package  OCA\Dossiq\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service;

use OCA\Dossiq\AppInfo\Application;
use OCP\App\IAppManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Imports the generated demo dataset into OpenRegister on request.
 *
 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
 */
class DemoDataService {
	/**
	 * App-relative path to the generated mock descriptor.
	 *
	 * @var string
	 */
	private const DESCRIPTOR = '/lib/Settings/dossiq_mock_register.json';

	/**
	 * Configuration identity for the demo import.
	 *
	 * 🔴 ITS OWN NAMESPACE, not the app id. Sharing the app's identity would
	 * make the demo import and the real configuration import share one version
	 * gate, so installing demo data could mask a pending configuration update
	 * — or be masked by one.
	 *
	 * @var string
	 */
	private const CONFIG_APP_ID = Application::APP_ID . '.demo';

	/**
	 * Constructor.
	 *
	 * @param IAppManager        $appManager Resolves this app's path and version.
	 * @param ContainerInterface $container  Resolves OpenRegister's importer.
	 * @param LoggerInterface    $logger     Records what was imported.
	 *
	 * @return void
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly ContainerInterface $container,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Whether this app ships a demo dataset at all.
	 *
	 * @return boolean True when the descriptor is present on disk.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	public function isAvailable(): bool {
		return is_file($this->descriptorPath()) === true;
	}//end isAvailable()

	/**
	 * Import the demo dataset.
	 *
	 * 🔴 THROWS RATHER THAN RETURNING A QUIET FAILURE. Every caller reports the
	 * outcome to an operator who just asked for this, so "nothing happened"
	 * must not be presentable as success.
	 *
	 * 🔴 AND THE COUNT IS WHAT LANDED, NOT WHAT WAS ASKED FOR. This method used
	 * to count `components.objects` in the shipped file and report that as the
	 * result, with a comment saying the number reported is "the number ASKED
	 * FOR". The ask is not an outcome: a descriptor of 456 objects reported
	 * "456 objects" whether the importer stored 456, three or none, so the ten
	 * demo keys no schema declared (#1782) were stripped on the way in under a
	 * green message that could not have said otherwise. `importFromJson()`
	 * answers with `objects` — the entities it created or updated — and
	 * `skipped.objects` — the ones it refused. Both are read here, and both are
	 * returned, so a caller can print the landing next to the ask.
	 *
	 * @return array{objects: integer, requested: integer, refused: integer, unchanged: integer,
	 *     registers: integer, schemas: integer} What was asked for and what landed.
	 *
	 * @throws RuntimeException When the descriptor is missing or unreadable, OpenRegister is
	 *     absent, or nothing was stored and nothing was already present.
	 *
	 * @spec openspec/changes/first-time-setup/specs/first-time-setup/spec.md
	 */
	public function install(): array {
		$path = $this->descriptorPath();
		if (is_file($path) === false) {
			throw new RuntimeException('No demo dataset ships with this app (' . self::DESCRIPTOR . ' not found).');
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			throw new RuntimeException('The demo dataset could not be read: ' . $path);
		}

		$data = json_decode($raw, true);
		if (is_array($data) === false) {
			throw new RuntimeException('The demo dataset is not valid JSON: ' . $path);
		}

		// The ASK: how many objects the shipped descriptor carries. Kept, but
		// as one half of a comparison rather than as the answer.
		$requested = 0;
		$components = ($data['components'] ?? []);
		if (is_array($components) === true && is_array(($components['objects'] ?? null)) === true) {
			$requested = count($components['objects']);
		}

		$result = $this->configurationService()->importFromApp(
			appId: self::CONFIG_APP_ID,
			data: $data,
			version: $this->appManager->getAppVersion(Application::APP_ID),
			force: true
		);

		// The LANDING. An importer reply with no `objects` key has said nothing
		// about objects, and nothing is zero — never "as many as we asked for".
		$skipped   = (array)($result['skipped'] ?? []);
		$unchanged = (array)($result['unchanged'] ?? []);
		$imported  = [
			'objects'   => count((array)($result['objects'] ?? [])),
			'requested' => $requested,
			'refused'   => (int)($skipped['objects'] ?? 0),
			// Already present at the same version, so correctly left alone.
			// REPORTED BY THE IMPORTER, not inferred here: deriving it as
			// `requested - stored - refused` looks equivalent and is not, because
			// it silently reclassifies an object the importer dropped WITHOUT
			// saying so as "already present", which is the exact failure this
			// guard exists to catch.
			'unchanged' => (int)($unchanged['objects'] ?? 0),
			'registers' => count((array)($result['registers'] ?? [])),
			'schemas'   => count((array)($result['schemas'] ?? [])),
		];

		// 🔴 AN IMPORT THAT STORED NOTHING IS NOT A SUCCESS, and this is the
		// only place that can tell. Same shape as the seed steps of #1767 and
		// #1769, which reported `success: true` with every counter at zero and
		// recorded themselves as done. A descriptor that ships no objects at
		// all is a different condition and stays a success: registers and
		// schemas are a legitimate thing to ship on their own.
		// STORING NOTHING IS NOT THE SAME AS FAILING. This read `objects === 0`
		// alone, which refuses an import whose objects are already there, and
		// that is the normal case on a second run. The step's own body promises
		// it is "safe to run more than once", and an idempotent import
		// necessarily stores zero the second time. Measured on CI, dossiq
		// development, every run since 2026-09-03: 444 requested, 0 stored,
		// reported as a hard failure on an install with nothing left to do.
		//
		// So the question is whether anything SURVIVED, not whether anything
		// moved.
		if ($requested > 0 && $imported['objects'] === 0 && $imported['unchanged'] === 0) {
			throw new RuntimeException(
				'The demo import stored 0 of ' . $requested . ' object(s) ('
				. $imported['refused'] . ' refused by OpenRegister) and none was already present. '
				. 'Nothing was written, so this is not an install. Check the OpenRegister log for the refusals.'
			);
		}

		$this->logger->info(
			'[DemoDataService] imported demo data: '
			. $imported['objects'] . ' of ' . $requested . ' object(s) stored, '
			. $imported['refused'] . ' refused, '
			. $imported['registers'] . ' register(s), '
			. $imported['schemas'] . ' schema(s).',
			['app' => Application::APP_ID]
		);

		if ($imported['objects'] < $requested) {
			// Partial. Louder than info on purpose: some of what the app ships
			// did not survive the import, and the message above is the only
			// place the difference is visible.
			$this->logger->warning(
				'[DemoDataService] the demo import lost ' . ($requested - $imported['objects'])
				. ' of ' . $requested . ' object(s) — ' . $imported['refused'] . ' refused, the rest were '
				. 'left unchanged because an object of the same version already exists.',
				['app' => Application::APP_ID]
			);
		}

		return $imported;
	}//end install()

	/**
	 * Absolute path to the shipped descriptor.
	 *
	 * @return string The path.
	 */
	private function descriptorPath(): string {
		return $this->appManager->getAppPath(Application::APP_ID) . self::DESCRIPTOR;
	}//end descriptorPath()

	/**
	 * OpenRegister's configuration importer.
	 *
	 * 🔴 A CROSS-APP CLASS IS A RUNTIME LOOKUP. OpenRegister may not be
	 * installed, and asking the container for a class from a missing app
	 * raises something the caller cannot act on. Check first and say which app
	 * is missing.
	 *
	 * 🔴 THE RETURN TYPE IS `object`, NOT THE CLASS, AND THAT IS THE POINT.
	 * Naming a class from an OPTIONAL app in a native return type makes PHP
	 * resolve it whenever this method returns — so on an instance without
	 * OpenRegister the failure is a TypeError about a class nobody mentioned,
	 * instead of the RuntimeException above that names the missing app. It
	 * also makes the method impossible to exercise in a unit test, which is
	 * how this was found. The docblock keeps psalm and phpstan informed.
	 *
	 * @return object The importer — an OCA\OpenRegister\Service\ConfigurationService.
	 *
	 * @psalm-return \OCA\OpenRegister\Service\ConfigurationService
	 *
	 * @throws RuntimeException When OpenRegister is not installed.
	 */
	private function configurationService(): object {
		if (in_array('openregister', $this->appManager->getInstalledApps(), true) === false) {
			throw new RuntimeException('Demo data needs OpenRegister, which is not installed.');
		}

		return $this->container->get('OCA\OpenRegister\Service\ConfigurationService');
	}//end configurationService()
}//end class
