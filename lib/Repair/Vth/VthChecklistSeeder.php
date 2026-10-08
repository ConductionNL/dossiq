<?php

/**
 * Dossiq VTH inspection-checklist seeder.
 *
 * The second half of the VTH seed: the inspection-checklist templates, which
 * bind to the case types the first half just wrote. Split out of
 * {@see \OCA\Dossiq\Repair\VthSeedDataRepairStep} so that step reads as
 * orchestration and this one owns the checklist's own rules — the slug
 * idempotency probe, the slug-to-uuid binding, and the per-template failure
 * that must not abort the rest of the seed.
 *
 * @category Repair
 * @package  OCA\Dossiq\Repair\Vth
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
 * @spec openspec/changes/vth-workflow-configuration-01-config-foundation/tasks.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair\Vth;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\Migration\IOutput;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Writes the shipped inspection-checklist templates, once.
 *
 * @spec openspec/changes/vth-workflow-configuration-01-config-foundation/tasks.md
 */
class VthChecklistSeeder {

	use SearchesObjects;

	/**
	 * The schema slug used when the settings name none.
	 */
	private const DEFAULT_SCHEMA = 'inspectionChecklistTemplate';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Settings bridge.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Seed every shipped checklist template that is not present yet.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register slug.
	 * @param array<string, mixed> $data Decoded seed data.
	 * @param array<string, string> $caseTypeIds Case-type uuid keyed by slug.
	 * @param IOutput $output Output.
	 *
	 * @return array{seeded: int, skipped: int}
	 *
	 * @spec openspec/changes/vth-workflow-configuration-01-config-foundation/tasks.md
	 */
	public function seed(
		object $objectService,
		string $register,
		array $data,
		array $caseTypeIds,
		IOutput $output,
	): array {
		$checklists = $data['inspectionChecklists'] ?? [];
		if (is_array($checklists) === false || $checklists === []) {
			return ['seeded' => 0, 'skipped' => 0];
		}

		// Prefer the configured schema slug; fall back to the canonical name.
		$schema = (string)$this->settingsService->getConfigValue('inspection_checklist_template_schema');
		if ($schema === '') {
			$schema = self::DEFAULT_SCHEMA;
		}

		$existing = $this->existingSlugs(
			objectService: $objectService,
			register: $register,
			schema: $schema
		);
		if ($existing === null) {
			// Same rule as the case types: an unreadable list seeds nothing.
			$output->warning('VTH seed: the checklist list could not be read; no checklists seeded this run.');
			return ['seeded' => 0, 'skipped' => 0];
		}

		return $this->seedEach(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			checklists: $checklists,
			existing: $existing,
			caseTypeIds: $caseTypeIds,
			output: $output
		);
	}//end seed()

	/**
	 * Walk the shipped checklists, counting what each one did.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 * @param array<int, mixed> $checklists The shipped payloads.
	 * @param array<int, string> $existing The slugs already present.
	 * @param array<string, string> $caseTypeIds Case-type uuid keyed by slug.
	 * @param IOutput $output Output.
	 *
	 * @return array{seeded: int, skipped: int}
	 */
	private function seedEach(
		object $objectService,
		string $register,
		string $schema,
		array $checklists,
		array $existing,
		array $caseTypeIds,
		IOutput $output,
	): array {
		$seeded = 0;
		$skipped = 0;

		foreach ($checklists as $checklist) {
			if (is_array($checklist) === false) {
				continue;
			}

			$outcome = $this->seedOne(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				checklist: $checklist,
				existing: $existing,
				caseTypeIds: $caseTypeIds,
				output: $output
			);

			if ($outcome === 'seeded') {
				$seeded++;
			}

			if ($outcome === 'skipped') {
				$skipped++;
			}
		}//end foreach

		return ['seeded' => $seeded, 'skipped' => $skipped];
	}//end seedEach()

	/**
	 * Write one checklist, saying what became of it.
	 *
	 * A failed write is reported and stepped over: one template OpenRegister
	 * refuses should not cost the rest of the catalogue.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 * @param array<string, mixed> $checklist The shipped payload.
	 * @param array<int, string> $existing The slugs already present.
	 * @param array<string, string> $caseTypeIds Case-type uuid keyed by slug.
	 * @param IOutput $output Output.
	 *
	 * @return string One of `seeded`, `skipped` or `failed`.
	 */
	private function seedOne(
		object $objectService,
		string $register,
		string $schema,
		array $checklist,
		array $existing,
		array $caseTypeIds,
		IOutput $output,
	): string {
		$slug = (string)($checklist['slug'] ?? '');
		if ($slug === '') {
			return 'failed';
		}

		if (in_array($slug, $existing, true) === true) {
			return 'skipped';
		}

		try {
			$objectService->saveObject(
				register: $register,
				schema: $schema,
				object: $this->bindCaseType(checklist: $checklist, caseTypeIds: $caseTypeIds)
			);
		} catch (Throwable $e) {
			$output->warning('VTH checklist seed failed for ' . $slug . ': ' . $e->getMessage());
			$this->logger->warning(
				'Dossiq VTH checklist seed failed',
				['slug' => $slug, 'exception' => $e->getMessage()]
			);
			return 'failed';
		}

		return 'seeded';
	}//end seedOne()

	/**
	 * Bind a checklist template to its case type, by slug.
	 *
	 * The seed names its case type by slug because that is the only stable
	 * identifier a shipped file can carry: the uuid is minted at install. The
	 * schema declares `caseType` (a uuid `$ref`) and declares no `caseTypeSlug`,
	 * so shipping the slug straight through wrote a key OpenRegister answers 200
	 * to and stores nowhere, and every checklist installed unbound.
	 *
	 * An unresolvable slug drops the binding rather than the template: a
	 * checklist with no case type is still usable, `caseType` is optional
	 * ("null means any case type"), and a `caseTypeSlug` left in the payload
	 * would only be discarded again.
	 *
	 * @param array<string, mixed> $checklist The shipped checklist payload.
	 * @param array<string, string> $caseTypeIds Case-type uuid keyed by slug.
	 *
	 * @return array<string, mixed> The payload as OpenRegister should receive it.
	 */
	private function bindCaseType(array $checklist, array $caseTypeIds): array {
		$slug = (string)($checklist['caseTypeSlug'] ?? '');
		unset($checklist['caseTypeSlug']);

		$caseTypeId = (string)($caseTypeIds[$slug] ?? '');
		if ($slug !== '' && $caseTypeId === '') {
			$this->logger->warning(
				'Dossiq VTH checklist seed could not resolve its case type',
				['checklist' => ($checklist['slug'] ?? ''), 'caseTypeSlug' => $slug]
			);
			return $checklist;
		}

		if ($caseTypeId !== '') {
			$checklist['caseType'] = $caseTypeId;
		}

		return $checklist;
	}//end bindCaseType()

	/**
	 * Read existing slugs for idempotency.
	 *
	 * @param object $objectService OpenRegister ObjectService.
	 * @param string $register Register slug.
	 * @param string $schema Schema slug.
	 *
	 * @return array<int, string>|null The slugs, or null when the list could not be read.
	 */
	private function existingSlugs(
		object $objectService,
		string $register,
		string $schema,
	): ?array {
		try {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema
			);
		} catch (Throwable) {
			return null;
		}

		$slugs = [];
		foreach ($rows as $row) {
			// THE SLUG LIVES IN `@self`, NOT IN THE OBJECT BODY.
			//
			// A seeded `slug:` is an import-time identifier OpenRegister keeps
			// as metadata; it is NOT a stored property. Reading `$row['slug']`
			// therefore returned '' for every row, so this list came back empty,
			// so the idempotency check below matched nothing, so every upgrade
			// re-seeded the whole set. Measured on a live instance: nine
			// consecutive upgrades left 9 copies each of Omgevingsvergunning
			// Bouwactiviteit, Sloopmelding, Toezichtzaak Bouw, Toezichtzaak
			// Milieu, Handhavingszaak and Invorderingszaak — and every run
			// reported success.
			//
			// The body form is kept as a fallback rather than dropped: an
			// object created by some other path may legitimately carry it.
			$self = $row['@self'] ?? [];
			$slug = (string)($self['slug'] ?? $row['slug'] ?? '');
			if ($slug !== '') {
				$slugs[] = $slug;
			}
		}

		return $slugs;
	}//end existingSlugs()
}//end class
