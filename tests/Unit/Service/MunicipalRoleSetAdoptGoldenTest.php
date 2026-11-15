<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Starter\MunicipalRoleSetService;
use OCA\Dossiq\Service\Starter\ShippedConfigurationService;
use OCA\Dossiq\Service\Starter\ShippedRoleSources;
use OCA\Dossiq\Service\Starter\ShippedSets;
use OCA\Dossiq\Service\Starter\StarterStore;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Every outcome of adopting the municipal role set, with the writes and the log in order.
 */
class MunicipalRoleSetAdoptGoldenTest extends TestCase {

	/**
	 * What the store and the logger were asked, in order.
	 *
	 * @var array<int, array<int, mixed>>
	 */
	private array $calls = [];

	/**
	 * The service over a store answering `$rows` per config key and refusing the saves in `$refused`.
	 *
	 * @param array<string, array<int, array<string, mixed>>|null> $rows    Rows per config key.
	 * @param array<int, string>                                   $refused Ids (or config keys) whose save fails.
	 * @param IUser|null                                           $user    The current user.
	 *
	 * @return MunicipalRoleSetService The service.
	 */
	private function service(array $rows, array $refused = [], ?IUser $user = null): MunicipalRoleSetService {
		$store = $this->createMock(StarterStore::class);
		$store->method('rows')->willReturnCallback(fn (string $configKey, array $filters = []): ?array => $rows[$configKey] ?? null);
		$store->method('idOf')->willReturnCallback(static fn (?array $row): string => (string)($row['id'] ?? ''));
		$store->method('save')->willReturnCallback(
			function (string $configKey, array $payload, ?string $id = null) use ($refused): ?array {
				if ($configKey === MunicipalRoleSetService::ADOPTIONS) {
					$payload['adoptedAt'] = 'AT';
				}

				$this->calls[] = ['save', $configKey, $payload, $id];
				if (in_array((string)($id ?? $configKey), $refused, true) === true) {
					return null;
				}

				return $payload;
			}
		);
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$logger = $this->createMock(LoggerInterface::class);
		$logger->method('info')->willReturnCallback(
			function (string $message, array $context): void {
				$this->calls[] = ['info', $message, $context];
			}
		);

		return new MunicipalRoleSetService(
			new ShippedRoleSources(store: $store, shipped: $this->createMock(ShippedConfigurationService::class), sets: new ShippedSets()),
			$session,
			$logger,
		);
	}//end service()

	/**
	 * The adoption record the service writes for `$by`.
	 *
	 * @param string $by Who adopted.
	 *
	 * @return array<int, mixed> The save call.
	 */
	private static function record(string $by): array {
		return [
			'save',
			MunicipalRoleSetService::ADOPTIONS,
			['set' => ShippedSets::MUNICIPAL_ROLES, 'setVersion' => (new ShippedSets())->versionOf(set: ShippedSets::MUNICIPAL_ROLES), 'adoptedBy' => $by, 'adoptedAt' => 'AT'],
			null,
		];
	}//end record()

	/**
	 * Allowed: every role is woken, the adoption is recorded by its actor, and logged.
	 *
	 * @return void
	 */
	public function testAdoptionWakesEveryRole(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('noor');
		$rows = [MunicipalRoleSetService::ROLE_TYPES => [['id' => 'r-1', 'dormant' => true], ['id' => 'r-2']], MunicipalRoleSetService::ADOPTIONS => [['reversedAt' => '2026-01-01']]];

		self::assertSame(['ok' => true, 'reason' => '', 'adopted' => 2], $this->service(rows: $rows, user: $user)->adopt());
		self::assertSame(
			[
				['save', MunicipalRoleSetService::ROLE_TYPES, ['id' => 'r-1', 'dormant' => false], 'r-1'],
				['save', MunicipalRoleSetService::ROLE_TYPES, ['id' => 'r-2', 'dormant' => false], 'r-2'],
				self::record(by: 'noor'),
				['info', 'Dossiq starter: the municipal role set was adopted', ['roles' => 2]],
			],
			$this->calls
		);
	}//end testAdoptionWakesEveryRole()

	/**
	 * A role that will not save is not counted; without a user the record names nobody.
	 *
	 * @return void
	 */
	public function testAFailedRoleIsNotCounted(): void {
		$rows = [MunicipalRoleSetService::ROLE_TYPES => [['id' => 'r-1'], ['id' => 'r-2']], MunicipalRoleSetService::ADOPTIONS => []];

		self::assertSame(['ok' => true, 'reason' => '', 'adopted' => 1], $this->service(rows: $rows, refused: ['r-1'])->adopt());
		self::assertSame(self::record(by: ''), $this->calls[2]);
	}//end testAFailedRoleIsNotCounted()

	/**
	 * Refused: an unreadable set, an open adoption, and a record that will not save.
	 *
	 * @return void
	 */
	public function testTheRefusals(): void {
		self::assertSame(['ok' => false, 'reason' => 'unavailable', 'adopted' => 0], $this->service(rows: [])->adopt());
		self::assertSame([], $this->calls);

		$open = [MunicipalRoleSetService::ROLE_TYPES => [['id' => 'r-1']], MunicipalRoleSetService::ADOPTIONS => [['reversedAt' => '2026-01-01'], ['set' => 'x']]];
		self::assertSame(['ok' => false, 'reason' => 'already_adopted', 'adopted' => 0], $this->service(rows: $open)->adopt());
		self::assertSame([], $this->calls);

		$rows = [MunicipalRoleSetService::ROLE_TYPES => [['id' => 'r-1']]];
		self::assertSame(
			['ok' => false, 'reason' => 'write_failed', 'adopted' => 1],
			$this->service(rows: $rows, refused: [MunicipalRoleSetService::ADOPTIONS])->adopt()
		);
		self::assertSame('save', $this->calls[1][0]);
		self::assertCount(2, $this->calls);
	}//end testTheRefusals()
}//end class
