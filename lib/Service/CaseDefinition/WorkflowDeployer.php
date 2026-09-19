<?php

/**
 * Dossiq workflow template deployer.
 *
 * Deploys the workflow templates a case definition package carries, and undoes
 * every one of them when any one of them fails: half a set of templates is a
 * case type whose process is missing steps nobody can see are missing.
 *
 * Split from {@see PackageWriter}, which writes the package's collection rows.
 * The two share the row primitives in {@see WritesPackageRows} and nothing else.
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
 * @spec openspec/specs/case-type-portability/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\CaseDefinition;

use OCA\Dossiq\Service\SettingsService;
use Psr\Log\LoggerInterface;
use ZipArchive;

/**
 * Deploys the workflow templates a package carries.
 *
 * @spec openspec/specs/case-type-portability/spec.md
 */
class WorkflowDeployer {

	use WritesPackageRows;

	/**
	 * The strategy that leaves a template this instance already has alone.
	 *
	 * @var string
	 */
	private const STRATEGY_SKIP = 'skip';

	/**
	 * Constructor.
	 *
	 * @param LoggerInterface $logger   What a deployment writes about itself.
	 * @param SettingsService $settings The register and schema names.
	 */
	public function __construct(
		private readonly LoggerInterface $logger,
		private readonly SettingsService $settings,
	) {
	}//end __construct()

	/**
	 * Import workflow files from the ZIP archive.
	 *
	 * 🔴 THIS USED TO COUNT FILES AND CALL IT AN IMPORT. It answered "Imported
	 * 3 workflow(s)" having deployed none. Counting is not importing, and a
	 * count reported as a success is the reason an administrator trusted an
	 * empty instance.
	 *
	 * @param \ZipArchive $zip The opened ZIP archive.
	 * @param string $strategy The conflict resolution strategy.
	 *
	 * @return array{status: string, message: string, created?: array<int, string>, replaced?: array<int, string>}
	 *
	 * @spec openspec/changes/case-definition-export-is-real/specs/case-types/spec.md
	 */
	public function deploy(\ZipArchive $zip, string $strategy): array {
		$objectService = $this->settings->getObjectService();
		$register = $this->settings->getConfigValue(key: 'register');
		$schema = $this->settings->getConfigValue(key: 'workflow_template_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			return [
				'status' => 'error',
				'message' => 'Workflows were not deployed: this instance has no workflow template schema configured.',
			];
		}

		$entries = $this->workflowEntries(zip: $zip);
		if ($entries === []) {
			return [
				'status' => 'skipped',
				'message' => 'The package carries no workflows.',
			];
		}

		$created = [];
		$replaced = [];
		$skipped = [];

		foreach ($entries as $name => $content) {
			$outcome = $this->deployWorkflow(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				name: (string)$name,
				content: $content,
				strategy: $strategy,
			);

			if ($outcome['kind'] === 'error') {
				// A workflow that cannot be read or written takes the whole
				// deployment with it: half a set of templates is a case type
				// whose process is missing steps nobody can see are missing.
				$this->rollBack(objectService: $objectService, register: $register, ids: $created);

				return ['status' => 'error', 'message' => $outcome['message']];
			}

			if ($outcome['kind'] === 'skipped') {
				$skipped[] = $outcome['id'];
				continue;
			}

			if ($outcome['kind'] === 'replaced') {
				$replaced[] = $outcome['id'];
				continue;
			}

			$created[] = $outcome['id'];
		}//end foreach

		return $this->workflowOutcome(created: $created, replaced: $replaced, skipped: $skipped);
	}//end deploy()

	/**
	 * The workflow templates the archive carries, by entry name.
	 *
	 * @param \ZipArchive $zip The opened archive.
	 *
	 * @return array<string, string|false> The raw entries, by name.
	 */
	private function workflowEntries(\ZipArchive $zip): array {
		$entries = [];
		for ($i = 0; $i < $zip->numFiles; $i++) {
			$name = $zip->getNameIndex($i);
			if ($name === false || str_starts_with($name, 'workflows/') === false || str_ends_with($name, '.json') === false) {
				continue;
			}

			$entries[$name] = $zip->getFromIndex($i);
		}

		return $entries;
	}//end workflowEntries()

	/**
	 * Deploy one workflow template, and say what became of it.
	 *
	 * @param object            $objectService The OpenRegister object service.
	 * @param string            $register      The register being written into.
	 * @param string            $schema        The workflow template schema.
	 * @param string            $name          The archive entry, for the message.
	 * @param string|false      $content       The raw entry, or false when unreadable.
	 * @param string            $strategy      What to do about a template already here.
	 *
	 * @return array{kind: string, id: string, message: string} What became of it.
	 */
	private function deployWorkflow(
		object $objectService,
		string $register,
		string $schema,
		string $name,
		string|false $content,
		string $strategy,
	): array {
		if ($content === false) {
			return [
				'kind' => 'error',
				'id' => '',
				'message' => "Workflow '{$name}' could not be read from the archive.",
			];
		}

		$template = json_decode($content, true);
		if (is_array($template) === false) {
			return ['kind' => 'error', 'id' => '', 'message' => "Workflow '{$name}' is not valid JSON."];
		}

		$packageId = $this->existingId(row: $template);
		$conflict = ($packageId !== '' && $this->holds(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			id: $packageId
		) === true);

		if ($conflict === true && $strategy === self::STRATEGY_SKIP) {
			return ['kind' => 'skipped', 'id' => $packageId, 'message' => ''];
		}

		try {
			$templateUuid = null;
			if ($packageId !== '') {
				$templateUuid = $packageId;
			}

			$stored = $objectService->saveObject(
				$this->withoutMetadata(row: $template),
				register: $register,
				schema: $schema,
				uuid: $templateUuid
			);
		} catch (\Throwable $e) {
			return [
				'kind' => 'error',
				'id' => '',
				'message' => "Workflow '{$name}' could not be deployed: " . $e->getMessage(),
			];
		}//end try

		$id = $this->storedId(stored: $stored);
		if ($conflict === true) {
			return ['kind' => 'replaced', 'id' => $id, 'message' => ''];
		}

		return ['kind' => 'created', 'id' => $id, 'message' => ''];
	}//end deployWorkflow()

	/**
	 * What the workflow deployment amounts to, said in the caller's vocabulary.
	 *
	 * @param array<int, string> $created  The templates deployed.
	 * @param array<int, string> $replaced The templates replaced.
	 * @param array<int, string> $skipped  The templates left alone.
	 *
	 * @return array<string, mixed> The outcome.
	 */
	private function workflowOutcome(array $created, array $replaced, array $skipped): array {
		if ($created === [] && $replaced === [] && $skipped === []) {
			return ['status' => 'error', 'message' => 'No workflow in the package was deployed.'];
		}

		if ($created === [] && $replaced === []) {
			return [
				'status' => 'skipped',
				'message' => count($skipped) . ' workflow(s) already present, left alone.',
			];
		}

		return [
			'status' => 'success',
			'message' => count($created) . ' workflow(s) deployed, ' . count($replaced) . ' replaced',
			'created' => $created,
			'replaced' => $replaced,
		];
	}//end workflowOutcome()
}//end class
