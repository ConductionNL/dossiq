<?php

/**
 * The converted sites throw instead of answering an empty value.
 *
 * These are the service-level halves of the four conversions. Each one used
 * to catch \Throwable and answer `[]` or `null`, and every caller then read
 * that emptiness as a decision about the user. The mutation that proves each
 * assertion is the old code: put the `return []` back and the test goes red.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Exception\RefusedException;
use OCA\Dossiq\Service\Beschikking\MandaatVerifier;
use OCA\Dossiq\Service\MandaatCheckService;
use OCA\Dossiq\Service\RoleResolverService;
use OCA\Dossiq\Service\Routing\RoleDelegationResolver;
use OCA\Dossiq\Service\Routing\RoutingStrategyInterface;
use OCA\Dossiq\Service\Routing\StrategyRegistry;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\TenantAuthenticationService;
use OCP\App\IAppManager;
use OCP\ICache;
use OCP\ICacheFactory;
use PHPUnit\Framework\TestCase;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A store that could not be read is a refusal, not an empty result.
 *
 * @covers \OCA\Dossiq\Service\Beschikking\MandaatVerifier
 * @covers \OCA\Dossiq\Service\MandaatCheckService
 * @covers \OCA\Dossiq\Service\RoleResolverService
 * @covers \OCA\Dossiq\Service\Support\RefusesWhenIndeterminate
 * @covers \OCA\Dossiq\Service\TenantAuthenticationService
 */
class RefusalReplacesTheEmptyAnswerTest extends TestCase {
	/**
	 * A settings service whose object service throws on every search.
	 *
	 * @param bool $throws Whether the search throws or answers rows.
	 *
	 * @return SettingsService The configured mock.
	 */
	private function settings(bool $throws): SettingsService {
		$objectService = new class($throws) {
			/**
			 * Whether the search throws.
			 *
			 * @var bool
			 */
			private bool $throws;

			/**
			 * Constructor.
			 *
			 * @param bool $throws Whether the search throws.
			 */
			public function __construct(bool $throws) {
				$this->throws = $throws;
			}

			/**
			 * Slug-aware search, as SearchesObjects calls it.
			 *
			 * @param string               $register Register slug.
			 * @param string               $schema   Schema slug.
			 * @param array<string, mixed> $filters  Query filters.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function searchObjectsBySlug(string $register, string $schema, array $filters = []): array {
				if ($this->throws === true) {
					throw new RuntimeException('the register is not answering');
				}

				return [];
			}
		};

		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService);
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return [
					'register' => 'dossiq',
					'mandaat_regeling_schema' => 'mandaatRegeling',
					'mandaat_schema' => 'mandaat',
				][$key] ?? $default;
			}
		);

		return $settings;
	}//end settings()

	/**
	 * An unreadable mandate scheme refuses, and says which rule could not run.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAnUnreadableMandateSchemeRefusesInsteadOfAnsweringEmpty(): void {
		$verifier = new MandaatVerifier(
			settingsService: $this->settings(throws: true),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		try {
			$verifier->resolveMandaatRegeling(caseType: 'wmo');
			self::fail(
				message: 'resolveMandaatRegeling answered instead of refusing. An empty regeling reaches '
				. 'the approver as "insufficient mandaat", which names the person for a register '
				. 'that could not be read.'
			);
		} catch (RefusedException $e) {
			self::assertSame(expected: 'mandaat-regeling-unreadable', actual: $e->getRule());
			self::assertSame(expected: 503, actual: $e->getStatus());
			self::assertStringContainsString(needle: 'could not be read', haystack: $e->getSentence());
		}
	}//end testAnUnreadableMandateSchemeRefusesInsteadOfAnsweringEmpty()

	/**
	 * A mandate scheme that reads answers an array, and refuses nothing.
	 *
	 * The pair: without it, a method that threw unconditionally would pass the
	 * test above.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAReadableMandateSchemeStillAnswers(): void {
		$verifier = new MandaatVerifier(
			settingsService: $this->settings(throws: false),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);

		self::assertSame(expected: [], actual: $verifier->resolveMandaatRegeling(caseType: 'wmo'));
	}//end testAReadableMandateSchemeStillAnswers()

	/**
	 * An unreadable mandate register refuses rather than reporting "niet bevoegd".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAnUnreadableMandateRegisterRefusesInsteadOfAnsweringEmpty(): void {
		$check = new MandaatCheckService(
			settingsService: $this->settings(throws: true),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			conflictService: null,
		);

		try {
			$check->getApplicableMandaten(decisionType: 'subsidie', caseType: 'wmo');
			self::fail(
				message: 'getApplicableMandaten answered an empty list. isAuthorized() then reports '
				. 'REDEN_NIET_BEVOEGD, which is a statement about the user rather than about '
				. 'the register.'
			);
		} catch (RefusedException $e) {
			self::assertSame(expected: 'mandaat-register-unreadable', actual: $e->getRule());
			self::assertSame(expected: 503, actual: $e->getStatus());
		}
	}//end testAnUnreadableMandateRegisterRefusesInsteadOfAnsweringEmpty()

	/**
	 * A mandate register that reads answers a list, and refuses nothing.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAReadableMandateRegisterStillAnswers(): void {
		$check = new MandaatCheckService(
			settingsService: $this->settings(throws: false),
			logger: $this->createMock(originalClassName: LoggerInterface::class),
			conflictService: null,
		);

		self::assertSame(
			expected: [],
			actual: $check->getApplicableMandaten(decisionType: 'subsidie', caseType: 'wmo')
		);
	}//end testAReadableMandateRegisterStillAnswers()
	/**
	 * A settings service whose `findAll()` throws or answers rows.
	 *
	 * RoleResolverService reads through `findAll()` rather than the slug-aware
	 * search the two services above use, so it needs its own double.
	 *
	 * @param bool $throws Whether the read throws.
	 *
	 * @return SettingsService The configured mock.
	 */
	private function findAllSettings(bool $throws): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->findAllStore(throws: $throws));
		$settings->method('getConfigValue')->willReturnCallback(
			static function (string $key, string $default = ''): string {
				return [
					'register' => 'dossiq',
					'role_schema' => 'caseRole',
				][$key] ?? $default;
			}
		);

		return $settings;
	}//end findAllSettings()

	/**
	 * An object store whose `findAll()` throws, or answers no rows.
	 *
	 * @param bool $throws Whether the read throws.
	 *
	 * @return object The store double.
	 */
	private function findAllStore(bool $throws): object {
		return new class($throws) {
			/**
			 * Whether the read throws.
			 *
			 * @var bool
			 */
			private bool $throws;

			/**
			 * Constructor.
			 *
			 * @param bool $throws Whether the read throws.
			 */
			public function __construct(bool $throws) {
				$this->throws = $throws;
			}

			/**
			 * The single-config-array read OpenRegister exposes.
			 *
			 * @param array<string, mixed> $config The read config.
			 *
			 * @return array<int, mixed> The rows.
			 */
			public function findAll(array $config = []): array {
				if ($this->throws === true) {
					throw new RuntimeException('the register is not answering');
				}

				return [];
			}
		};
	}//end findAllStore()

	/**
	 * A role resolver over a register that throws, or answers no rows.
	 *
	 * @param bool $throws Whether the role read throws.
	 *
	 * @return RoleResolverService The configured service.
	 */
	private function roleResolver(bool $throws): RoleResolverService {
		$strategy = $this->createMock(originalClassName: RoutingStrategyInterface::class);
		$strategy->method('resolve')->willReturn([]);

		$registry = $this->createMock(originalClassName: StrategyRegistry::class);
		$registry->method('has')->willReturn(true);
		$registry->method('get')->willReturn($strategy);

		$cacheFactory = $this->createMock(originalClassName: ICacheFactory::class);
		$cacheFactory->method('createLocal')->willReturn($this->createMock(originalClassName: ICache::class));

		$delegation = $this->createMock(originalClassName: RoleDelegationResolver::class);
		$delegation->method('apply')->willReturn([]);

		return new RoleResolverService(
			registry: $registry,
			settingsService: $this->findAllSettings(throws: $throws),
			cacheFactory: $cacheFactory,
			delegation: $delegation,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end roleResolver()

	/**
	 * An unreadable role register refuses rather than routing to nobody.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAnUnreadableRoleRegisterRefusesInsteadOfAnsweringEmpty(): void {
		try {
			$this->roleResolver(throws: true)->resolve(
				['strategy' => 'single-role', 'roleType' => 'behandelaar'],
				['id' => 'case-1']
			);
			self::fail(
				message: 'resolve() answered instead of refusing. An empty role list is how a routing '
				. 'rule resolves to nobody, so a register that could not be read arrives as '
				. '"this case has no handler in that role".'
			);
		} catch (RefusedException $e) {
			self::assertSame(expected: 'case-roles-unreadable', actual: $e->getRule());
			self::assertSame(expected: 503, actual: $e->getStatus());
			self::assertStringContainsString(needle: 'could not be read', haystack: $e->getSentence());
		}
	}//end testAnUnreadableRoleRegisterRefusesInsteadOfAnsweringEmpty()

	/**
	 * A role register that reads resolves, and refuses nothing.
	 *
	 * The pair: without it, a resolver that threw unconditionally would pass
	 * the test above.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAReadableRoleRegisterStillResolves(): void {
		self::assertSame(
			expected: [],
			actual: $this->roleResolver(throws: false)->resolve(
				['strategy' => 'single-role', 'roleType' => 'behandelaar'],
				['id' => 'case-1']
			)
		);
	}//end testAReadableRoleRegisterStillResolves()

	/**
	 * A tenant authentication service over a store that throws, or answers none.
	 *
	 * @param bool $throws Whether the matrix read throws.
	 *
	 * @return TenantAuthenticationService The configured service.
	 */
	private function tenantAuth(bool $throws): TenantAuthenticationService {
		$appManager = $this->createMock(originalClassName: IAppManager::class);
		$appManager->method('getInstalledApps')->willReturn(['openregister']);

		$container = $this->createMock(originalClassName: ContainerInterface::class);
		$container->method('get')->willReturn($this->findAllStore(throws: $throws));

		return new TenantAuthenticationService(
			appManager: $appManager,
			container: $container,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end tenantAuth()

	/**
	 * An unreadable mandate matrix refuses rather than answering "no matrix".
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testAnUnreadableMandateMatrixRefusesInsteadOfAnsweringNull(): void {
		try {
			$this->tenantAuth(throws: true)->loadActiveMatrix(tenantId: 'tenant-1');
			self::fail(
				message: 'loadActiveMatrix() answered null. The middleware reads that as "no active '
				. 'mandate matrix for tenant" and returns 403, which is a decision the matrix '
				. 'never made.'
			);
		} catch (RefusedException $e) {
			self::assertSame(expected: 'tenant-mandate-matrix-unreadable', actual: $e->getRule());
			self::assertSame(expected: 503, actual: $e->getStatus());
			self::assertStringContainsString(needle: 'could not be read', haystack: $e->getSentence());
		}
	}//end testAnUnreadableMandateMatrixRefusesInsteadOfAnsweringNull()

	/**
	 * A matrix store that reads and holds nothing answers null, and refuses nothing.
	 *
	 * The pair: "the tenant has no matrix" and "the matrix could not be read"
	 * are the two answers this change exists to keep apart.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/refusals-carry-a-status/specs/quality-gates/spec.md
	 */
	public function testATenantWithNoMatrixStillAnswersNull(): void {
		self::assertNull(actual: $this->tenantAuth(throws: false)->loadActiveMatrix(tenantId: 'tenant-1'));
	}//end testATenantWithNoMatrixStillAnswersNull()
}//end class
