<?php

/**
 * Dossiq first-run readiness.
 *
 * The minimum an instance needs before it can take a case, read live and
 * reported per item. Nothing here blocks: `register-check` is the only gate
 * the wizard has, and an instance an administrator deliberately leaves half
 * configured is a legitimate instance. An instance nobody can see the state
 * of is not.
 *
 * Every item is a question asked of the tree at request time, never a stored
 * completion flag: a flag drifts the moment somebody deletes the thing it
 * recorded. An item whose read raises reports not done and names the failure,
 * per ADR-102.
 *
 * @category Service
 * @package  OCA\Dossiq\Service\Setup
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
 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Setup;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\IAppConfig;
use RuntimeException;
use Throwable;

/**
 * Reads the five readiness items, and the tour steps that lost their surface.
 *
 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
 */
class FirstRunReadiness {

	use SearchesObjects;

	/**
	 * OpenRegister's working-calendar service, the thing that makes a term
	 * count in working days rather than in guesses.
	 *
	 * @var string
	 */
	public const CALENDAR_SERVICE_CLASS = 'OCA\OpenRegister\Service\Flow\Timer\WorkingCalendarService';

	/**
	 * OpenRegister's organisation store.
	 *
	 * @var string
	 */
	public const ORGANISATION_MAPPER_CLASS = 'OCA\OpenRegister\Db\OrganisationMapper';

	/**
	 * Constructor.
	 *
	 * @param SettingsService $settingsService OpenRegister availability and config.
	 * @param IAppConfig      $appConfig       App-config reader.
	 */
	public function __construct(
		private readonly SettingsService $settingsService,
		private readonly IAppConfig $appConfig,
	) {
	}//end __construct()

	/**
	 * The items as declared, in the order an administrator works through them.
	 *
	 * @return array<int, array<string, mixed>> The declared items.
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function declared(): array {
		$path = __DIR__ . '/../../Settings/first_run_readiness.json';
		if (is_file($path) === false) {
			return [];
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			return [];
		}

		$parsed = json_decode($raw, true);
		if (is_array($parsed) === false) {
			return [];
		}

		$items = ($parsed['items'] ?? []);
		if (is_array($items) === false) {
			return [];
		}

		return array_values(array_filter($items, 'is_array'));
	}//end declared()

	/**
	 * Every declared item, with whether it is done and why not.
	 *
	 * @return array<int, array<string, mixed>> The reported items.
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function report(): array {
		$reported = [];
		foreach ($this->declared() as $item) {
			$id = (string)($item['id'] ?? '');
			$read = $this->read(id: $id);
			$reported[] = [
				'id' => $id,
				'title' => (string)($item['title'] ?? ''),
				'body' => (string)($item['body'] ?? ''),
				'screen' => (string)($item['screen'] ?? ''),
				'done' => $read['done'],
				'failure' => $read['failure'],
			];
		}

		return $reported;
	}//end report()

	/**
	 * The tour steps that name a surface this app no longer has.
	 *
	 * A step naming a page that is gone is skipped in silence by the runner,
	 * which is how a tour quietly stops teaching half the product: nothing
	 * errors, nothing logs, the step simply never appears. So it is reported
	 * beside the readiness items, where somebody can see it.
	 *
	 * Only `page` and `nav-item` targets can be judged from here: an `element`
	 * target names a DOM test id, which no manifest resolves, so those are
	 * reported as unverifiable rather than counted as broken. Judging them
	 * belongs in the runner, which can see the DOM.
	 *
	 * @param array<string, mixed>|null $manifest The manifest to judge, or null
	 *        for the shipped one. Taken as an argument so a test can hand it a
	 *        tour that DOES name a missing page: a scan that only ever runs
	 *        over a healthy tree reports the same green as one that works.
	 *
	 * @return array<int, array<string, mixed>> The broken and unverifiable steps.
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	public function brokenTourSteps(?array $manifest = null): array {
		$manifest = ($manifest ?? $this->manifest());
		$surfaces = $this->declaredSurfaces(manifest: $manifest);
		$broken = [];

		foreach (($manifest['walkthrough']['tours'] ?? []) as $tour) {
			foreach (($tour['steps'] ?? []) as $step) {
				$target = ($step['target'] ?? []);
				$kind = (string)($target['kind'] ?? '');
				// A `selector` target carries no ref, so the report would name
				// nothing at all for it.
				$ref = (string)($target['ref'] ?? $target['selector'] ?? '');

				if (in_array($kind, ['page', 'nav-item'], true) === false) {
					$broken[] = [
						'tour' => (string)($tour['id'] ?? ''),
						'step' => (string)($step['id'] ?? ''),
						'surface' => $ref,
						'kind' => $kind,
						'state' => 'unverifiable',
					];
					continue;
				}

				if (in_array($ref, $surfaces, true) === true) {
					continue;
				}

				$broken[] = [
					'tour' => (string)($tour['id'] ?? ''),
					'step' => (string)($step['id'] ?? ''),
					'surface' => $ref,
					'kind' => $kind,
					'state' => 'missing',
				];
			}
		}

		return $broken;
	}//end brokenTourSteps()

	/**
	 * Every surface a tour step could name: the pages and the menu entries.
	 *
	 * @param array<string, mixed> $manifest The app manifest.
	 *
	 * @return array<int, string> The ids.
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	private function declaredSurfaces(array $manifest): array {
		$ids = [];
		foreach (($manifest['pages'] ?? []) as $page) {
			$ids[] = (string)($page['id'] ?? '');
		}

		foreach (($manifest['menu'] ?? []) as $entry) {
			$ids[] = (string)($entry['id'] ?? '');
			foreach (($entry['children'] ?? []) as $child) {
				$ids[] = (string)($child['id'] ?? '');
			}
		}

		return array_values(array_unique(array_filter($ids)));
	}//end declaredSurfaces()

	/**
	 * The app manifest, as shipped.
	 *
	 * @return array<string, mixed> The manifest, empty when it cannot be read.
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	private function manifest(): array {
		$path = __DIR__ . '/../../../src/manifest.json';
		if (is_file($path) === false) {
			return [];
		}

		$raw = file_get_contents($path);
		if ($raw === false) {
			return [];
		}

		$parsed = json_decode($raw, true);
		if (is_array($parsed) === false) {
			return [];
		}

		return $parsed;
	}//end manifest()

	/**
	 * Read one item, turning any failure into "not done" and a sentence.
	 *
	 * @param string $id The item id.
	 *
	 * @return array{done: bool, failure: string} Whether it is satisfied.
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	private function read(string $id): array {
		try {
			$done = match ($id) {
				'organisation' => $this->hasOrganisation(),
				'mail-account' => $this->hasMailAccount(),
				'published-case-type' => $this->hasPublishedCaseType(),
				'role-with-holder' => $this->hasRoleWithHolder(),
				'working-calendar' => $this->hasWorkingCalendar(),
				// An item declared with no reader cannot be answered, which is
				// the failure D-3 exists to surface rather than to hide.
				default => throw new RuntimeException('no reader is declared for "' . $id . '"'),
			};

			return ['done' => $done, 'failure' => ''];
		} catch (Throwable $e) {
			// NOT DONE, and the sentence. An item whose read raises and an item
			// that is satisfied look identical to an administrator otherwise,
			// and only one of them is safe to stop worrying about (ADR-102).
			return ['done' => false, 'failure' => $e->getMessage()];
		}//end try
	}//end read()

	/**
	 * Whether OpenRegister holds at least one organisation.
	 *
	 * @return bool True when one exists.
	 *
	 * @throws RuntimeException When OpenRegister cannot answer.
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	private function hasOrganisation(): bool {
		$mapper = $this->settingsService->getOpenRegisterClass(self::ORGANISATION_MAPPER_CLASS);
		if ($mapper === null) {
			throw new RuntimeException('OpenRegister does not answer for organisations');
		}

		return $mapper->findAll(limit: 1) !== [];
	}//end hasOrganisation()

	/**
	 * Whether a shared mailbox is picked.
	 *
	 * @return bool True when one is configured.
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	private function hasMailAccount(): bool {
		return $this->appConfig->getValueString('dossiq', 'email_mail_account_id', '') !== '';
	}//end hasMailAccount()

	/**
	 * Whether at least one case type is published rather than draft.
	 *
	 * @return bool True when one is published.
	 *
	 * @throws RuntimeException When the case types cannot be read.
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	private function hasPublishedCaseType(): bool {
		$rows = $this->search(schemaKey: 'case_type_schema', filters: ['isDraft' => false]);

		return $rows !== [];
	}//end hasPublishedCaseType()

	/**
	 * Whether at least one role names a holder.
	 *
	 * @return bool True when one does.
	 *
	 * @throws RuntimeException When the roles cannot be read.
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	private function hasRoleWithHolder(): bool {
		foreach ($this->search(schemaKey: 'role_schema', filters: []) as $role) {
			if (trim((string)($role['participant'] ?? '')) !== '') {
				return true;
			}
		}

		return false;
	}//end hasRoleWithHolder()

	/**
	 * Whether the engine can count a working day.
	 *
	 * @return bool True when the calendar service answers.
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	private function hasWorkingCalendar(): bool {
		return $this->settingsService->getOpenRegisterClass(self::CALENDAR_SERVICE_CLASS) !== null;
	}//end hasWorkingCalendar()

	/**
	 * Read objects of one configured schema.
	 *
	 * @param string               $schemaKey The settings key naming the schema.
	 * @param array<string, mixed> $filters   Equality filters.
	 *
	 * @return array<int, array<string, mixed>> The rows.
	 *
	 * @throws RuntimeException When OpenRegister is not configured for it.
	 *
	 * @spec openspec/changes/first-run-and-the-tour/specs/first-time-setup/spec.md
	 */
	private function search(string $schemaKey, array $filters): array {
		$objectService = $this->settingsService->getObjectService();
		if ($objectService === null) {
			throw new RuntimeException('OpenRegister is not available');
		}

		$register = $this->settingsService->getConfigValue(key: 'register');
		$schema = $this->settingsService->getConfigValue(key: $schemaKey);
		if (empty($register) === true || empty($schema) === true) {
			throw new RuntimeException('the "' . $schemaKey . '" schema is not configured');
		}

		return $this->searchObjectsAsArrays(
			objectService: $objectService,
			register: $register,
			schema: $schema,
			filters: $filters,
		);
	}//end search()

}//end class
