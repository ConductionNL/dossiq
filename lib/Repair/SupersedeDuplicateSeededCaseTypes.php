<?php

/**
 * Dossiq repair step: a seeded case type stored twice keeps one current copy.
 *
 * Before the case-flow seed found a case type by its slug it looked it up by
 * title, which is a language map and never matched, so every repair pass
 * stored the case type again. On the review instance "Omgevingsvergunning
 * kleine bouwactiviteit" stood twice in the new case picker (11 Oct, N-5).
 * The seed no longer duplicates; this step settles an install that already
 * has the copies.
 *
 * Decided 22 Oct: the picker offers only the current version of a case type,
 * and an older one stays reachable from the case type page. So the newest
 * copy stays current and every older copy of the same seed slug and version
 * is superseded by it, the way a published version supersedes the one before.
 * Each organisation keeps its own copies apart: the read is unscoped, and on a
 * multi-tenant install one organisation's copy never supersedes another's.
 * Nothing is deleted and no case moves: a case keeps the copy it was opened
 * on, as a running case keeps its version.
 *
 * Versions of one case type share a slug but not a version number, so they
 * are left alone, as is any copy something already supersedes.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
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
 * @spec openspec/specs/case-type-seed-data/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Supersedes the older copies of a seeded case type stored more than once.
 *
 * @spec openspec/specs/case-type-seed-data/spec.md
 */
class SupersedeDuplicateSeededCaseTypes implements IRepairStep {

	use SearchesObjects;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/specs/case-type-seed-data/spec.md
	 */
	public function getName(): string {
		return 'Supersede the older copies of a seeded case type stored twice';
	}//end getName()

	/**
	 * Run the step. Idempotent: a superseded copy is not a candidate again.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/specs/case-type-seed-data/spec.md
	 */
	public function run(IOutput $output): void {
		$objectService = $this->settingsService->getObjectService();
		$register      = (string)$this->settingsService->getConfigValue('register');
		$schema        = (string)$this->settingsService->getConfigValue('case_type_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			$output->info('OpenRegister or the case type schema is not available; skipping.');
			return;
		}

		$superseded = $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): int => $this->supersede(
				objectService: $objectService,
				register: $register,
				schema: $schema,
			),
		);

		$output->info(sprintf('Duplicate seeded case types: %d older copies superseded.', $superseded));
	}//end run()

	/**
	 * Supersede the older copies in every group of one slug, version and organisation.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      The register id.
	 * @param string $schema        The case type schema id.
	 *
	 * @return integer How many copies were superseded.
	 *
	 * @spec openspec/specs/case-type-seed-data/spec.md
	 */
	private function supersede(object $objectService, string $register, string $schema): int {
		try {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => 1000],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not read the case types to supersede seeded duplicates',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			return 0;
		}

		$superseded = 0;
		foreach ($this->duplicateGroups(rows: $rows) as $group) {
			$current = array_pop($group);
			foreach ($group as $older) {
				$superseded += $this->supersedeOne(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					olderId: $older['id'],
					currentId: $current['id'],
				);
			}
		}

		return $superseded;
	}//end supersede()

	/**
	 * The current copies grouped by seed slug, version and organisation,
	 * oldest first, only where a group holds more than one.
	 *
	 * @param array<int, array<string, mixed>> $rows The stored case types.
	 *
	 * @return array<string, array<int, array{id: string, created: string}>> The groups.
	 *
	 * @spec openspec/specs/case-type-seed-data/spec.md
	 */
	private function duplicateGroups(array $rows): array {
		$groups = [];
		foreach ($rows as $row) {
			$self = ($row['@self'] ?? []);
			if (is_array($self) === false) {
				$self = [];
			}

			$slug = (string)($self['slug'] ?? '');
			$uuid = (string)($self['id'] ?? ($row['id'] ?? ''));
			if ($slug === '' || $uuid === '' || empty($row['supersededBy']) === false) {
				continue;
			}

			// Per organisation: the read is unscoped, and one organisation's copy
			// must never supersede another's on a multi-tenant install.
			$organisation = ($self['organisation'] ?? '');
			if (is_array($organisation) === true) {
				$organisation = ($organisation['uuid'] ?? ($organisation['id'] ?? ''));
			}

			$key = $slug . '#' . (string)($row['version'] ?? '') . '#' . (string)$organisation;
			$groups[$key][] = ['id' => $uuid, 'created' => (string)($self['created'] ?? '')];
		}

		$duplicates = [];
		foreach ($groups as $key => $group) {
			if (count($group) < 2) {
				continue;
			}

			usort($group, static fn (array $one, array $two): int => strcmp($one['created'], $two['created']));
			$duplicates[$key] = $group;
		}

		return $duplicates;
	}//end duplicateGroups()

	/**
	 * Point one older copy at the current one.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register      The register id.
	 * @param string $schema        The case type schema id.
	 * @param string $olderId       The copy to supersede.
	 * @param string $currentId     The copy that stays current.
	 *
	 * @return integer 1 when written, 0 when the write failed.
	 *
	 * @spec openspec/specs/case-type-seed-data/spec.md
	 */
	private function supersedeOne(object $objectService, string $register, string $schema, string $olderId, string $currentId): int {
		try {
			$this->patchObjectAsArray(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				id: $olderId,
				changes: ['supersededBy' => $currentId],
			);
			return 1;
		} catch (Throwable $e) {
			$this->logger->error(
				'Dossiq: could not supersede a duplicate seeded case type',
				['app' => Application::APP_ID, 'id' => $olderId, 'exception' => $e->getMessage()]
			);
			return 0;
		}
	}//end supersedeOne()
}//end class
