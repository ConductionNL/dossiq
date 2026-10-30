<?php

/**
 * Dossiq example data fragments.
 *
 * The curated part of the example data the setup wizard loads: work that
 * reads like a working day (cases assigned to the person loading the set,
 * deadlines this week, a Woo request with assessed documents, data subject
 * requests). The generated set beside it covers every schema with placeholder
 * values; these fragments are what make the work pages read as work.
 *
 * Each `lib/Settings/demo.d/*.json` file holds `components.objects`, in the
 * shape of a register seed. Values may carry tokens that are only known when
 * the set is loaded:
 * - `@me`: the user who loads the example data;
 * - `@myGroup`: that user's first group other than `admin`, by id, the team
 *   whose queue the example cases land in;
 * - `@today`, `@today+3d`, `@today-2d`: a date relative to the load day;
 * - `@now`, `@now-1d`: a date-time relative to the moment of loading;
 * - `@ref:<schema>:<slug>` naming no fragment object: the id of the stored
 *   register object with that slug, such as a case type the register ships.
 *   A reference to another fragment object is left for the importer.
 * Everything in these files is fictional.
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
 * @spec openspec/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Setup;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Support\SearchesObjects;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IGroupManager;
use OCP\IUserSession;
use Throwable;

/**
 * Reads the curated example data fragments and fills in their load-time tokens.
 *
 * @spec openspec/specs/first-time-setup/spec.md
 */
class ExampleDataFragments {

	use SearchesObjects;

	/**
	 * App-relative directory of the fragments.
	 *
	 * @var string
	 */
	public const DIRECTORY = '/lib/Settings/demo.d';

	/**
	 * Constructor.
	 *
	 * @param IAppManager   $appManager  App manager, for the app's path.
	 * @param IUserSession  $userSession The user loading the example data.
	 * @param ITimeFactory  $time        The clock the relative dates count from.
	 * @param IGroupManager $groups      The loading user's groups, for `@myGroup`.
	 * @param SettingsService|null $settingsService The register and the ObjectService, for stored references.
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $time,
		private readonly IGroupManager $groups,
		private readonly ?SettingsService $settingsService = null,
	) {
	}//end __construct()

	/**
	 * The slugs of every fragment object.
	 *
	 * @return array<int, string> The slugs.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function slugs(): array {
		return array_values(
			array_filter(
				array_map(static fn (array $object): string => (string)($object['@self']['slug'] ?? ''), $this->rawObjects()),
				static fn (string $slug): bool => $slug !== ''
			)
		);
	}//end slugs()

	/**
	 * The fragment objects as written, tokens unresolved.
	 *
	 * @return array<int, array<string, mixed>> The objects, in file name order.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function rawObjects(): array {
		$files = glob($this->appManager->getAppPath(Application::APP_ID) . self::DIRECTORY . '/*.json');
		if ($files === false) {
			return [];
		}

		sort($files);
		$objects = [];
		foreach ($files as $file) {
			$data = json_decode((string)file_get_contents($file), true);
			$found = ($data['components']['objects'] ?? null);
			if (is_array($found) === true) {
				$objects = array_merge($objects, array_values($found));
			}
		}

		return $objects;
	}//end rawObjects()

	/**
	 * The fragment objects with every token filled in for this load.
	 *
	 * A `@me` with nobody signed in (an `occ` run), or a `@myGroup` for a user
	 * in no group but `admin`, drops the property, so the object stays valid
	 * and simply has nobody or no team assigned.
	 *
	 * @param array<int, string> $skipSlugs Slugs of objects an earlier load already created; they are left out.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 *
	 * @spec openspec/specs/first-time-setup/spec.md
	 */
	public function objects(array $skipSlugs = []): array {
		$who = ['@me' => '', '@myGroup' => ''];
		$user = $this->userSession->getUser();
		if ($user !== null) {
			$who['@me'] = $user->getUID();
			$who['@myGroup'] = $this->firstTeam(groupIds: $this->groups->getUserGroupIds($user));
		}

		$now = $this->time->now();

		$raw = $this->rawObjects();
		$fresh = array_filter(
			$raw,
			static fn (array $object): bool => in_array((string)($object['@self']['slug'] ?? ''), $skipSlugs, true) === false
		);
		$who = array_merge($who, $this->storedReferences(objects: $fresh, siblings: $raw));

		return array_map(
			fn (array $object): array => $this->resolve(value: $object, who: $who, now: $now),
			array_values($fresh)
		);
	}//end objects()

	/**
	 * The stored ids behind the `@ref:<schema>:<slug>` tokens that name no fragment object.
	 *
	 * A token that cannot be resolved is left out, so it stays in the object
	 * and the importer reports it as unresolved.
	 *
	 * @param array<int, array<string, mixed>> $objects  The objects being loaded.
	 * @param array<int, array<string, mixed>> $siblings Every fragment object.
	 *
	 * @return array<string, string> The ids, keyed by token.
	 */
	private function storedReferences(array $objects, array $siblings): array {
		$fragments = [];
		foreach ($siblings as $sibling) {
			$fragments['@ref:' . (string)($sibling['@self']['schema'] ?? '') . ':' . (string)($sibling['@self']['slug'] ?? '')] = true;
		}

		$tokens = [];
		array_walk_recursive(
			$objects,
			static function (mixed $value) use (&$tokens, $fragments): void {
				if (is_string($value) === true && preg_match('/^@ref:[^:]+:.+$/', $value) === 1 && isset($fragments[$value]) === false) {
					$tokens[$value] = true;
				}
			}
		);

		$ids = [];
		foreach (array_keys($tokens) as $token) {
			[, $schema, $slug] = explode(':', $token, 3);
			$id = $this->storedId(schema: $schema, slug: $slug);
			if ($id !== '') {
				$ids[$token] = $id;
			}
		}

		return $ids;
	}//end storedReferences()

	/**
	 * The id of the stored register object of a schema with a slug.
	 *
	 * @param string $schema The schema slug.
	 * @param string $slug   The object slug.
	 *
	 * @return string The id, or '' when it is not stored or cannot be read.
	 */
	private function storedId(string $schema, string $slug): string {
		$objectService = $this->settingsService?->getObjectService();
		if ($objectService === null) {
			return '';
		}

		$register = $this->settingsService->getConfigValue('register', Application::APP_ID);

		try {
			$row = $this->asSystemWithoutUser(
				objectService: $objectService,
				userSession: $this->userSession,
				operation: fn (): ?array => $this->findObjectAsArray(objectService: $objectService, register: $register, schema: $schema, id: $slug),
			);
		} catch (Throwable) {
			return '';
		}

		return (string)($row['@self']['id'] ?? $row['id'] ?? '');
	}//end storedId()

	/**
	 * The loading user's team: the first of their groups, by id, that is not
	 * `admin`.
	 *
	 * @param array<int, string> $groupIds The user's group ids.
	 *
	 * @return string The group id, or '' when there is none.
	 */
	private function firstTeam(array $groupIds): string {
		$teams = array_values(array_diff($groupIds, ['admin']));
		sort($teams);

		return (string)($teams[0] ?? '');
	}//end firstTeam()

	/**
	 * Fill the tokens in one value, recursively.
	 *
	 * @param array<array-key, mixed> $value The object, or a nested object or list.
	 * @param array<string, string> $who   `@me`, `@myGroup` and stored references, '' when unknown.
	 * @param DateTimeImmutable     $now   The moment of loading.
	 *
	 * @return array<array-key, mixed> The value with tokens filled in.
	 */
	private function resolve(array $value, array $who, DateTimeImmutable $now): array {
		$isList = array_is_list($value);
		foreach ($value as $key => $item) {
			if (is_array($item) === true) {
				$value[$key] = $this->resolve(value: $item, who: $who, now: $now);
				continue;
			}

			if (is_string($item) === false) {
				continue;
			}

			if (array_key_exists($item, $who) === true) {
				$value[$key] = $who[$item];
				if ($who[$item] === '') {
					unset($value[$key]);
				}

				continue;
			}

			$value[$key] = $this->date(token: $item, now: $now);
		}

		// A dropped list element must not leave a gap: a list with a gap
		// encodes as a JSON object.
		if ($isList === true) {
			return array_values($value);
		}

		return $value;
	}//end resolve()

	/**
	 * Fill one date token; any other string is returned as it is.
	 *
	 * @param string            $token The value.
	 * @param DateTimeImmutable $now   The moment of loading.
	 *
	 * @return string The filled value.
	 */
	private function date(string $token, DateTimeImmutable $now): string {
		$matches = [];
		if (preg_match('/^@(today|now)(?:([+-])(\d+)d)?$/', $token, $matches) !== 1) {
			return $token;
		}

		$moment = $now;
		if (isset($matches[3]) === true) {
			$interval = new DateInterval('P' . $matches[3] . 'D');
			$moment = $now->add($interval);
			if ($matches[2] === '-') {
				$moment = $now->sub($interval);
			}
		}

		if ($matches[1] === 'today') {
			return $moment->format('Y-m-d');
		}

		return $moment->format(DATE_ATOM);
	}//end date()
}//end class
