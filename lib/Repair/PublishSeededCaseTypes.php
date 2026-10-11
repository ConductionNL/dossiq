<?php

/**
 * Dossiq repair step: publish the case types the seeds left as drafts.
 *
 * `caseType.isDraft` defaults to TRUE. Thirteen shipped case types (the four
 * supplier case types and the two subsidy schemes in the register, the six VTH
 * case types and the case-flow "kleine bouwactiviteit") were seeded without
 * `isDraft`, so OpenRegister stored the default and every one of them is a
 * draft. The case type picker of the new case form offers only `isDraft: false`,
 * so on the review instance (10 Oct, finding B3) "Sloopmelding" and "kleine
 * bouwactiviteit" could not be chosen at all. Nothing errored.
 *
 * The seeds now say `isDraft: false`, which settles a fresh install. This step
 * settles an existing one: it sets `isDraft: false` on exactly those thirteen
 * case types, by seed slug. The stored value cannot tell an unset seed from a
 * deliberate draft (both read `true`), so the step runs ONCE per instance and
 * records that it did. An administrator who later makes one of them a draft
 * again keeps that choice.
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
 * @spec openspec/changes/seeded-case-types-are-published/specs/case-types/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Repair;

use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IAppConfig;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Sets `isDraft: false` once on the seeded case types that were stored as drafts.
 *
 * @spec openspec/changes/seeded-case-types-are-published/specs/case-types/spec.md
 */
class PublishSeededCaseTypes implements IRepairStep {

	use SearchesObjects;

	/**
	 * The app config key that records this step has run on this instance.
	 */
	public const DONE_KEY = 'seeded_case_types_published';

	/**
	 * The seed slugs of the case types that were shipped without `isDraft`.
	 *
	 * Read from `@self.slug`, where OpenRegister keeps an imported slug.
	 */
	public const SLUGS = [
		'leverancier-contractverlenging-verzoek',
		'leverancier-iban-wijziging',
		'leverancier-accreditatie-verificatie',
		'leverancier-mutatie',
		'zaaktype-innovatiefonds-2026',
		'zaaktype-cultuur-subsidie-2026',
		'omgevingsvergunning-bouwactiviteit',
		'sloopmelding',
		'toezichtzaak-bouw',
		'toezichtzaak-milieu',
		'handhavingszaak',
		'invorderingszaak',
		'omgevingsvergunning-kleinbouw',
	];

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService Bridge to OpenRegister.
	 * @param IAppConfig $appConfig Where the step records that it ran.
	 * @param LoggerInterface $logger Logger.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IAppConfig $appConfig,
		private readonly LoggerInterface $logger,
	) {
	}//end __construct()

	/**
	 * Get the name of this repair step.
	 *
	 * @return string
	 *
	 * @spec openspec/changes/seeded-case-types-are-published/specs/case-types/spec.md
	 */
	public function getName(): string {
		return 'Publish the seeded case types that were stored as drafts';
	}//end getName()

	/**
	 * Run the step once.
	 *
	 * The run is recorded only when every write succeeded, so a failed write
	 * is tried again on the next repair rather than forgotten.
	 *
	 * @param IOutput $output Progress reporting.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/seeded-case-types-are-published/specs/case-types/spec.md
	 */
	public function run(IOutput $output): void {
		if ($this->appConfig->getValueString(Application::APP_ID, self::DONE_KEY, '') === '1') {
			$output->info('Seeded case types were published before; skipping.');
			return;
		}

		$objectService = $this->settingsService->getObjectService();
		$register = (string)$this->settingsService->getConfigValue('register');
		$schema = (string)$this->settingsService->getConfigValue('case_type_schema');
		if ($objectService === null || $register === '' || $schema === '') {
			$output->info('OpenRegister or the case type schema is not available; skipping.');
			return;
		}

		$tally = $this->runAsSystemIfAvailable(
			objectService: $objectService,
			operation: fn (): array => $this->publish(
				objectService: $objectService,
				register: $register,
				schema: $schema,
			),
		);

		$output->info(
			sprintf(
				'Seeded case types: %d published, %d already published, %d failed.',
				$tally['published'],
				$tally['skipped'],
				$tally['failed']
			)
		);

		if ($tally['failed'] === 0 && $tally['unreadable'] === false) {
			$this->appConfig->setValueString(Application::APP_ID, self::DONE_KEY, '1');
		}
	}//end run()

	/**
	 * Set `isDraft: false` on each seeded case type that is not published.
	 *
	 * The case types are read WITHOUT an `isDraft` filter and matched here: a
	 * boolean filter on OpenRegister's search is not something this step
	 * should depend on to find the rows it has to change.
	 *
	 * @param object $objectService OpenRegister's ObjectService.
	 * @param string $register The register id.
	 * @param string $schema The case type schema id.
	 *
	 * @return array{published: int, skipped: int, failed: int, unreadable: bool} The tally.
	 *
	 * @spec openspec/changes/seeded-case-types-are-published/specs/case-types/spec.md
	 */
	private function publish(object $objectService, string $register, string $schema): array {
		$tally = ['published' => 0, 'skipped' => 0, 'failed' => 0, 'unreadable' => false];

		try {
			$rows = $this->searchObjectsAsArraysUnscoped(
				objectService: $objectService,
				register: $register,
				schema: $schema,
				filters: ['_limit' => 1000],
			);
		} catch (Throwable $e) {
			$this->logger->warning(
				'Dossiq: could not read the case types to publish the seeded ones',
				['app' => Application::APP_ID, 'exception' => $e->getMessage()]
			);
			$tally['unreadable'] = true;
			return $tally;
		}

		foreach ($rows as $row) {
			$self = ($row['@self'] ?? []);
			if (is_array($self) === false) {
				$self = [];
			}

			$slug = (string)($self['slug'] ?? '');
			$uuid = (string)($self['id'] ?? ($row['id'] ?? ''));
			if ($uuid === '' || in_array($slug, self::SLUGS, true) === false) {
				continue;
			}

			if (($row['isDraft'] ?? true) === false) {
				$tally['skipped']++;
				continue;
			}

			try {
				$this->patchObjectAsArray(
					objectService: $objectService,
					register: $register,
					schema: $schema,
					id: $uuid,
					changes: ['isDraft' => false],
				);
				$tally['published']++;
			} catch (Throwable $e) {
				$this->logger->error(
					'Dossiq: could not publish a seeded case type',
					['app' => Application::APP_ID, 'slug' => $slug, 'exception' => $e->getMessage()]
				);
				$tally['failed']++;
			}
		}//end foreach

		return $tally;
	}//end publish()
}//end class
