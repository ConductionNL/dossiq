<?php

/**
 * Unit tests for MatchRunReadiness.
 *
 * A run is prepared with everything it needs, or refused with the first code
 * that fails: an account that is not the owner's, an unconfigured register,
 * an OpenRegister that cannot scope a read to the owner, no email leaf.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/method-decomposition/specs/method-decomposition/spec.md
 *
 * @covers \OCA\Dossiq\Service\Email\MatchRunReadiness
 * @uses \OCA\Dossiq\Service\Email\MailMatchSources
 * @uses \OCA\Dossiq\Service\CaseEmailMatchRun
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Email;

use OCA\Dossiq\Service\CaseEmailMatchRun;
use OCA\Dossiq\Service\Email\CaseEmailMatchPreferences;
use OCA\Dossiq\Service\Email\CaseNumberRecognizer;
use OCA\Dossiq\Service\Email\MailMatchSources;
use OCA\Dossiq\Service\Email\MailMessageSource;
use OCA\Dossiq\Service\Email\MatchRunReadiness;
use OCA\Dossiq\Service\SettingsService;
use OCP\IUser;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

class MatchRunReadinessTest extends TestCase {

	/**
	 * An object service that can scope a read to a user.
	 *
	 * @return object
	 */
	private static function scopingStore(): object {
		return new class {
			/**
			 * Run a callable as a user.
			 *
			 * @param mixed    $user     The user.
			 * @param callable $callable What to run.
			 *
			 * @return mixed
			 */
			public function runAs(mixed $user, callable $callable): mixed {
				return $callable();
			}
		};
	}//end scopingStore()

	/**
	 * An email leaf that can link.
	 *
	 * @return object
	 */
	private static function leaf(): object {
		return new class {
			/**
			 * Link a message to an object.
			 *
			 * @return bool
			 */
			public function linkEmail(): bool {
				return true;
			}
		};
	}//end leaf()

	/**
	 * The readiness over an instance in the given state.
	 *
	 * @param array<string, mixed> $state ownsAccount, register, objectService, leaf (null: not resolvable).
	 *
	 * @return MatchRunReadiness
	 */
	private function readiness(array $state): MatchRunReadiness {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($state['register'] ?? '')
		);
		$settings->method('getObjectService')->willReturn($state['objectService'] ?? null);

		$messages = $this->createMock(originalClassName: MailMessageSource::class);
		$messages->method('ownsAccount')->willReturn($state['ownsAccount'] ?? true);
		$recognizer = $this->createMock(originalClassName: CaseNumberRecognizer::class);
		$recognizer->method('loadPattern')->willReturn('/\d{4}-\d{4}/');

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		if (($state['leaf'] ?? null) === null) {
			$container->method('get')->willThrowException(new RuntimeException('no leaf'));
		} else {
			$container->method('get')->willReturn($state['leaf']);
		}

		return new MatchRunReadiness(
			settingsService: $settings,
			matching: new MailMatchSources(
				preferences: $this->createMock(originalClassName: CaseEmailMatchPreferences::class),
				recognizer: $recognizer,
				messages: $messages,
			),
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class)
		);
	}//end readiness()

	/**
	 * A ready instance hands back a run carrying everything the batch needs.
	 *
	 * @return void
	 */
	public function testAReadyInstanceHandsBackAFilledRun(): void {
		$owner = $this->createMock(originalClassName: IUser::class);
		$owner->method('getUID')->willReturn('anna');
		$store = self::scopingStore();
		$leaf = self::leaf();

		$run = $this->readiness(state: ['register' => 'reg', 'objectService' => $store, 'leaf' => $leaf])
			->prepare(owner: $owner, accountId: 7);

		$this->assertInstanceOf(expected: CaseEmailMatchRun::class, actual: $run);
		$this->assertSame(expected: ['reg', 'reg', '/\d{4}-\d{4}/', 7], actual: [$run->register, $run->schema, $run->pattern, $run->accountId]);
		$this->assertSame(expected: [$store, $leaf], actual: [$run->objectService, $run->linkService]);
	}//end testAReadyInstanceHandsBackAFilledRun()

	/**
	 * Each missing piece is refused with its own code, the first that fails.
	 *
	 * @return void
	 */
	public function testEachMissingPieceIsRefusedWithItsCode(): void {
		$owner = $this->createMock(originalClassName: IUser::class);
		$owner->method('getUID')->willReturn('anna');
		$ready = ['register' => 'reg', 'objectService' => self::scopingStore(), 'leaf' => self::leaf()];

		$cases = [
			'account_not_owned'        => ['ownsAccount' => false] + $ready,
			'register_unconfigured'    => ['register' => ''] + $ready,
			'openregister_unavailable' => ['objectService' => null] + $ready,
			'scope_unavailable'        => ['objectService' => new \stdClass()] + $ready,
			'email_leaf_unavailable'   => ['leaf' => null] + $ready,
		];
		foreach ($cases as $code => $state) {
			$this->assertSame(expected: $code, actual: $this->readiness(state: $state)->prepare(owner: $owner, accountId: 7));
		}
	}//end testEachMissingPieceIsRefusedWithItsCode()
}//end class
