<?php

/**
 * WOODeadlineCheckJob reach test (#3153)
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\BackgroundJob
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
 */


declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\BackgroundJob;

use OCA\Dossiq\BackgroundJob\WOODeadlineCheckJob;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOODeadlineService;
use OCA\Dossiq\Tests\Support\MakesCaseDateNormaliser;
use OCA\OpenRegister\Contract\ObjectEntityInterface;
use OCA\OpenRegister\Contract\ObjectServiceInterface;
use OCP\App\IAppManager;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Notification\IManager as INotificationManager;
use OCP\Notification\INotification;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Woo deadline job reaches the open Woo case it exists for (#3153).
 *
 * The job runs against a store that filters the way OpenRegister does and the
 * REAL WOODeadlineService, so a filter on a key the case schema does not
 * declare, a status compared by name, a handler field the case does not have,
 * or a deadline read from a property the case does not declare, each leave
 * the handler without a warning and this test red.
 *
 * @covers \OCA\Dossiq\BackgroundJob\WOODeadlineCheckJob
 * @uses   \OCA\Dossiq\Service\WOODeadlineService
 * @uses   \OCA\Dossiq\Service\CaseDateNormaliser
 *
 * @spec openspec/changes/woo-case-type/tasks.md#task-4
 */
class WOODeadlineCheckJobReachTest extends TestCase {

	use MakesCaseDateNormaliser;

	/**
	 * A frozen "now", seven days before the open Woo case's deadline.
	 *
	 * @var string
	 */
	private const NOW = '2026-06-01T10:00:00+02:00';

	/**
	 * The schemas the fake store answers for, by the numeric id the settings hold.
	 *
	 * @var array<string, string>
	 */
	private const SCHEMA_BY_ID = ['11' => 'case', '12' => 'caseType', '13' => 'statusType'];

	/**
	 * The rows the fake store holds, by schema slug.
	 *
	 * @var array<string, array<int, array<string, mixed>>>
	 */
	private array $store = [];

	/**
	 * The properties the SHIPPED register declares, by schema slug.
	 *
	 * @return array<string, array<int, string>> Property names.
	 */
	private function declaredProperties(): array {
		$raw = (string)file_get_contents(__DIR__ . '/../../../lib/Settings/dossiq_register.json');
		$schemas = (array)(json_decode($raw, true)['components']['schemas'] ?? []);
		$declared = [];
		foreach (self::SCHEMA_BY_ID as $slug) {
			$declared[$slug] = array_keys((array)($schemas[$slug]['properties'] ?? []));
		}

		return $declared;
	}//end declaredProperties()

	/**
	 * An object store that answers the way OpenRegister's magic tables do.
	 *
	 * 🔴 THE DIALECT IS THE POINT. A `willReturn()` of the case rows would
	 * answer whatever the job asked, which is how a filter on status NAMES
	 * against a uuid column and a filter on the relation path `caseType.title`
	 * both read as working. This double applies the three rules the real
	 * `MagicSearchHandler::applyObjectFilters()` applies: a key the schema
	 * does not declare matches nothing (it adds `1 = 0`), a list is `IN (...)`,
	 * and a scalar is equality. `_`-prefixed keys are query options.
	 *
	 * @return ObjectServiceInterface The store.
	 */
	private function openRegisterStore(): ObjectServiceInterface {
		$declared = $this->declaredProperties();

		$store = $this->createMock(ObjectServiceInterface::class);
		$store->method('searchObjects')->willReturnCallback(
			function (array $query = []) use ($declared): array {
				$slug = self::SCHEMA_BY_ID[(string)($query['@self']['schema'] ?? '')] ?? '';
				$matches = [];
				foreach (($this->store[$slug] ?? []) as $row) {
					if ($this->matchesLikeOpenRegister(row: $row, query: $query, declared: ($declared[$slug] ?? [])) === true) {
						$matches[] = $row;
					}
				}

				return $matches;
			}
		);
		$store->method('find')->willReturnCallback(
			function (int|string $id): ?ObjectEntityInterface {
				foreach ($this->store['case'] ?? [] as $row) {
					if ($row['id'] === $id) {
						$entity = $this->createMock(ObjectEntityInterface::class);
						$entity->method('jsonSerialize')->willReturn($row);
						return $entity;
					}
				}

				return null;
			}
		);

		return $store;
	}//end openRegisterStore()

	/**
	 * Whether one row survives the filters, by OpenRegister's rules.
	 *
	 * @param array<string, mixed> $row      The stored row.
	 * @param array<string, mixed> $query    The query as the job sent it.
	 * @param array<int, string>   $declared The schema's declared properties.
	 *
	 * @return boolean True when it matches.
	 */
	private function matchesLikeOpenRegister(array $row, array $query, array $declared): bool {
		foreach ($query as $key => $value) {
			if (str_starts_with((string)$key, '_') === true || $key === '@self') {
				continue;
			}

			if (in_array($key, $declared, true) === false) {
				return false;
			}

			$stored = ($row[$key] ?? null);
			if (is_array($value) === true) {
				if (in_array($stored, $value, true) === false) {
					return false;
				}

				continue;
			}

			if ($stored !== $value) {
				return false;
			}
		}//end foreach

		return true;
	}//end matchesLikeOpenRegister()

	/**
	 * One run warns the handler of the one open Woo case, and nobody else.
	 *
	 * The store holds an open Woo case seven days before its deadline, a Woo
	 * case in a final status and an open case of another case type, all three
	 * seven days from their deadline. Only the first is the job's business.
	 *
	 * @return void
	 */
	public function testARunWarnsTheHandlerOfTheOpenWooCaseOnly(): void {
		$this->store = [
			'caseType' => [
				['id' => 'ct-woo', 'title' => 'WOO Verzoek'],
				['id' => 'ct-other', 'title' => 'Omgevingsvergunning'],
			],
			'statusType' => [
				['id' => 'st-woo-open', 'caseType' => 'ct-woo', 'isFinal' => false],
				['id' => 'st-woo-closed', 'caseType' => 'ct-woo', 'isFinal' => true],
				['id' => 'st-other-open', 'caseType' => 'ct-other', 'isFinal' => false],
			],
			'case' => [
				['id' => 'case-open-woo', 'caseType' => 'ct-woo', 'status' => 'st-woo-open', 'assignee' => 'alice', 'deadline' => '2026-06-08'],
				['id' => 'case-closed-woo', 'caseType' => 'ct-woo', 'status' => 'st-woo-closed', 'assignee' => 'bob', 'deadline' => '2026-06-08'],
				['id' => 'case-other', 'caseType' => 'ct-other', 'status' => 'st-other-open', 'assignee' => 'carol', 'deadline' => '2026-06-08'],
			],
		];

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->openRegisterStore());
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => [
				'register' => '10',
				'case_schema' => '11',
				'case_type_schema' => '12',
				'status_type_schema' => '13',
			][$key] ?? $default
		);

		$notified = [];
		$notifications = $this->createMock(INotificationManager::class);
		$notifications->method('createNotification')->willReturnCallback(
			function () use (&$notified): INotification {
				$notification = $this->createMock(INotification::class);
				foreach (['setApp', 'setDateTime', 'setObject', 'setSubject'] as $setter) {
					$notification->method($setter)->willReturnSelf();
				}

				$notification->method('setUser')->willReturnCallback(
					function (string $user) use (&$notified, $notification): INotification {
						$notified[] = $user;
						return $notification;
					}
				);
				return $notification;
			}
		);
		$notifications->expects($this->once())->method('notify');

		$deadlines = new WOODeadlineService(
			$settings,
			$notifications,
			$this->createMock(LoggerInterface::class),
			$this->caseDatesFrozenAt(instant: self::NOW),
		);

		$apps = $this->createMock(IAppManager::class);
		$apps->method('getInstalledApps')->willReturn(['openregister']);

		$job = new WOODeadlineCheckJob(
			$this->createMock(ITimeFactory::class),
			$deadlines,
			$settings,
			$apps,
			$this->createMock(LoggerInterface::class),
		);

		$run = new \ReflectionMethod(WOODeadlineCheckJob::class, 'run');
		$run->invoke($job, null);

		$this->assertSame(['alice'], $notified);
	}//end testARunWarnsTheHandlerOfTheOpenWooCaseOnly()
}//end class
