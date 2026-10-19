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
 * - `@today`, `@today+3d`, `@today-2d`: a date relative to the load day;
 * - `@now`, `@now-1d`: a date-time relative to the moment of loading.
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
 * @spec openspec/changes/example-data-fills-the-work-pages/specs/first-time-setup/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Service\Setup;

use DateInterval;
use DateTimeImmutable;
use OCA\Dossiq\AppInfo\Application;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IUserSession;

/**
 * Reads the curated example data fragments and fills in their load-time tokens.
 *
 * @spec openspec/changes/example-data-fills-the-work-pages/specs/first-time-setup/spec.md
 */
class ExampleDataFragments {

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
	 */
	public function __construct(
		private readonly IAppManager $appManager,
		private readonly IUserSession $userSession,
		private readonly ITimeFactory $time,
	) {
	}//end __construct()

	/**
	 * The fragment objects as written, tokens unresolved.
	 *
	 * @return array<int, array<string, mixed>> The objects, in file name order.
	 *
	 * @spec openspec/changes/example-data-fills-the-work-pages/specs/first-time-setup/spec.md
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
	 * A `@me` with nobody signed in (an `occ` run) drops the property, so the
	 * object stays valid and simply has nobody assigned.
	 *
	 * @return array<int, array<string, mixed>> The objects.
	 *
	 * @spec openspec/changes/example-data-fills-the-work-pages/specs/first-time-setup/spec.md
	 */
	public function objects(): array {
		$user = $this->userSession->getUser();
		$userId = '';
		if ($user !== null) {
			$userId = $user->getUID();
		}

		$now = $this->time->now();

		return array_map(
			fn (array $object): array => $this->resolve(value: $object, userId: $userId, now: $now),
			$this->rawObjects()
		);
	}//end objects()

	/**
	 * Fill the tokens in one value, recursively.
	 *
	 * @param array<string, mixed> $value  The object or nested value.
	 * @param string               $userId The loading user, '' for none.
	 * @param DateTimeImmutable    $now    The moment of loading.
	 *
	 * @return array<string, mixed> The value with tokens filled in.
	 */
	private function resolve(array $value, string $userId, DateTimeImmutable $now): array {
		foreach ($value as $key => $item) {
			if (is_array($item) === true) {
				$value[$key] = $this->resolve(value: $item, userId: $userId, now: $now);
				continue;
			}

			if ($item === '@me' && $userId === '') {
				unset($value[$key]);
				continue;
			}

			if (is_string($item) === true) {
				$value[$key] = $this->token(token: $item, userId: $userId, now: $now);
			}
		}

		return $value;
	}//end resolve()

	/**
	 * Fill one token; any other string is returned as it is.
	 *
	 * @param string            $token  The value.
	 * @param string            $userId The loading user.
	 * @param DateTimeImmutable $now    The moment of loading.
	 *
	 * @return string The filled value.
	 */
	private function token(string $token, string $userId, DateTimeImmutable $now): string {
		if ($token === '@me') {
			return $userId;
		}

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
	}//end token()
}//end class
