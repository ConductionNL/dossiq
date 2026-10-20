<?php

/**
 * Dossiq Rewrite Renamed Layout Ids Repair Step
 *
 * Rewrites the widget, page and menu ids dossiq renamed inside every stored
 * buildiq arrangement of dossiq: the shared production delta and each user's
 * own delta. Without it, a page an administrator arranged in buildiq would lose
 * the arrangement of every renamed widget, because a delta matches widgets by id.
 *
 * WHERE. buildiq keeps a fleet app's arrangement as `applicationVersion`
 * objects in its `buildiq` register, each naming its app in `register`
 * (`openbuild-<appId>`). Only dossiq's are read, under its current and its
 * earlier app id. Without buildiq there is nothing to rewrite.
 *
 * SAFE TO RUN TWICE. A delta that names no old id is not written, so a second
 * run changes nothing.
 *
 * IT NEVER THROWS. A failure is logged; the delta keeps its old ids and the
 * widgets it arranged fall back to the shipped layout.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\Service\Dashboard\RenamedLayoutIds;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Rewrites renamed ids in dossiq's stored buildiq arrangements.
 *
 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
 */
class RewriteRenamedLayoutIds implements IRepairStep {
	use SearchesObjects;

	/**
	 * The register buildiq keeps its app records in.
	 */
	public const REGISTER = 'buildiq';

	/**
	 * The schema of a stored arrangement.
	 */
	public const SCHEMA = 'applicationVersion';

	/**
	 * The `register` value of dossiq's arrangements, under its current and earlier app id.
	 *
	 * @var list<string>
	 */
	public const APP_REGISTERS = ['openbuild-dossiq', 'openbuild-procest'];

	/**
	 * How many arrangements one run reads.
	 */
	private const LIMIT = 1000;

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Reaches OpenRegister.
	 * @param LoggerInterface $logger          Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * The step's name, as `occ upgrade` prints it.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
	 */
	public function getName(): string {
		return 'Rewrite renamed widget and page ids in stored layouts';
	}//end getName()

	/**
	 * Rewrite every dossiq arrangement that still names an old id.
	 *
	 * @param IOutput $output Upgrade output.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/ui-facing-names-are-generic/specs/ui-facing-names/spec.md
	 */
	public function run(IOutput $output): void {
		$objects = $this->settingsService->getObjectService();
		if ($objects === null) {
			return;
		}

		$rewritten = 0;
		try {
			$rewritten = (int)$this->runAsSystemIfAvailable(
				objectService: $objects,
				operation: fn (): int => $this->rewriteAll(objects: $objects)
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not rewrite renamed ids in stored layouts; those widgets fall back to the shipped layout',
				['error' => $e->getMessage()]
			);
			return;
		}

		if ($rewritten > 0) {
			$output->info('Rewrote renamed widget and page ids in ' . $rewritten . ' stored layout(s).');
		}
	}//end run()

	/**
	 * Rewrite each of dossiq's arrangements that names an old id.
	 *
	 * @param object $objects The ObjectService.
	 *
	 * @return int How many were written.
	 *
	 * @SuppressWarnings(PHPMD.StaticAccess) — RenamedLayoutIds is a pure rewrite helper
	 */
	private function rewriteAll(object $objects): int {
		try {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objects,
				register: self::REGISTER,
				schema: self::SCHEMA,
				filters: ['_limit' => self::LIMIT]
			);
		} catch (Throwable $e) {
			// No buildiq register: nothing was ever arranged.
			return 0;
		}

		$written = 0;
		foreach ($rows as $row) {
			if (in_array((string)($row['register'] ?? ''), self::APP_REGISTERS, true) === false) {
				continue;
			}

			$delta = ($row['manifestDelta'] ?? null);
			$uuid = (string)($row['@self']['id'] ?? ($row['id'] ?? ''));
			if (is_array($delta) === false || $uuid === '') {
				continue;
			}

			$rewritten = RenamedLayoutIds::rewrite(value: $delta);
			if ($rewritten === $delta) {
				continue;
			}

			$this->patchObjectAsArray(
				objectService: $objects,
				register: self::REGISTER,
				schema: self::SCHEMA,
				id: $uuid,
				changes: ['manifestDelta' => $rewritten]
			);
			$written++;
		}//end foreach

		return $written;
	}//end rewriteAll()
}//end class
