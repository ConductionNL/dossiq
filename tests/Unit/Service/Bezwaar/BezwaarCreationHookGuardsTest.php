<?php

/**
 * BezwaarCreationHook guard tests.
 *
 * Covers the refusals in front of the hook (no OpenRegister, an unconfigured
 * schema, a missing bezwaar case), a primair case that is already linked, and
 * the registrar when nobody is signed in.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
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
 * @spec openspec/specs/bezwaar-beroep-workflow/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\Bezwaar\BezwaarCreationHook;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Unit\Service\FakeStoredObject;
use OCP\AppFramework\Db\DoesNotExistException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The hook's refusals and its quiet paths.
 */
class BezwaarCreationHookGuardsTest extends TestCase {

	/**
	 * @var SettingsService&MockObject
	 */
	private SettingsService&MockObject $settingsService;

	/**
	 * @var IUserSession&MockObject
	 */
	private IUserSession&MockObject $userSession;

	/**
	 * Schema config by key.
	 *
	 * @var array<string, string>
	 */
	private array $config = [];

	/**
	 * Set up mocks with every schema configured.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();
		$this->settingsService = $this->createMock(SettingsService::class);
		$this->userSession     = $this->createMock(IUserSession::class);
		$this->config          = ['register' => '1', 'case_schema' => '10', 'decision_schema' => '20', 'objection_schema' => '30'];
		$this->settingsService->method('getConfigValue')->willReturnCallback(
			fn (string $key): string => $this->config[$key] ?? ''
		);
	}//end setUp()

	/**
	 * The hook under test.
	 *
	 * @return BezwaarCreationHook
	 */
	private function hook(): BezwaarCreationHook {
		return new BezwaarCreationHook($this->settingsService, $this->userSession, $this->createMock(LoggerInterface::class));
	}//end hook()

	/**
	 * An object service with a decision on primair-1 and the given bezwaar case (or none).
	 *
	 * @param array<string, mixed>|null $case The bezwaar case, or null when it does not exist.
	 *
	 * @return object
	 */
	private function store(?array $case): object {
		$store       = new class {
			/** @var array<string, mixed>|null */
			public ?array $case = null;

			/** @var array<int, array{string, array<string, mixed>}> */
			public array $saves = [];

			/**
			 * @param int|string      $id       The id.
			 * @param array|null      $_extend  Extends.
			 * @param bool            $files    Files.
			 * @param string|int|null $register The register.
			 * @param string|int|null $schema   The schema.
			 *
			 * @return FakeStoredObject
			 */
			public function find(int|string $id, ?array $_extend = [], bool $files = false, string|int|null $register = null, string|int|null $schema = null): FakeStoredObject {
				if ($id === 'decision-1') {
					return new FakeStoredObject(['case' => 'primair-1']);
				}

				if ($this->case === null) {
					throw new DoesNotExistException('Object ' . $id . ' does not exist');
				}

				return new FakeStoredObject($this->case);
			}

			/**
			 * @param array<string, mixed> $object   The object.
			 * @param array|null           $extend   Extends.
			 * @param string|int|null      $register The register.
			 * @param string|int|null      $schema   The schema.
			 * @param string|null          $uuid     The uuid.
			 *
			 * @return FakeStoredObject
			 */
			public function saveObject(array $object, ?array $extend = [], string|int|null $register = null, string|int|null $schema = null, ?string $uuid = null): FakeStoredObject {
				$this->saves[] = [(string)$schema, $object];
				return new FakeStoredObject($object);
			}
		};
		$store->case = $case;
		$this->settingsService->method('getObjectService')->willReturn($store);
		return $store;
	}//end store()

	/**
	 * Without OpenRegister the hook refuses before reading any schema.
	 *
	 * @return void
	 */
	public function testRefusesWithoutOpenRegister(): void {
		$this->settingsService->method('getObjectService')->willReturn(null);
		$this->settingsService->expects($this->never())->method('getConfigValue');

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('OpenRegister is not available');

		$this->hook()->onBezwaarCreated('bezwaar-1', 'decision-1');
	}//end testRefusesWithoutOpenRegister()

	/**
	 * Ids of only spaces count as missing.
	 *
	 * @return void
	 */
	public function testRefusesBlankIds(): void {
		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('bezwaar case id and contested decision id are required');

		$this->hook()->onBezwaarCreated('bezwaar-1', '   ');
	}//end testRefusesBlankIds()

	/**
	 * Any unconfigured schema refuses the hook and writes nothing.
	 *
	 * @return void
	 */
	public function testRefusesAnUnconfiguredSchema(): void {
		foreach (['case_schema', 'decision_schema', 'objection_schema'] as $missing) {
			$this->setUp();
			$this->config[$missing] = '';
			$store = $this->store(case: []);

			try {
				$this->hook()->onBezwaarCreated('bezwaar-1', 'decision-1');
				$this->fail('RuntimeException expected without ' . $missing);
			} catch (RuntimeException $e) {
				$this->assertSame('Case, decision or objection schema is not configured', $e->getMessage());
			}

			$this->assertSame([], $store->saves, $missing);
		}
	}//end testRefusesAnUnconfiguredSchema()

	/**
	 * A bezwaar case that does not exist refuses the hook before the objection is made.
	 *
	 * @return void
	 */
	public function testRefusesAMissingBezwaarCase(): void {
		$store = $this->store(case: null);

		try {
			$this->hook()->onBezwaarCreated('bezwaar-missing', 'decision-1');
			$this->fail('RuntimeException expected');
		} catch (RuntimeException $e) {
			$this->assertSame('Bezwaar case not found', $e->getMessage());
		}

		$this->assertSame([], $store->saves);
	}//end testRefusesAMissingBezwaarCase()

	/**
	 * A case already related to the primair case is not saved again; the
	 * objection is still made, registered by "system" when nobody is signed in.
	 *
	 * @return void
	 */
	public function testAnExistingLinkIsLeftAloneAndTheSystemRegisters(): void {
		$store = $this->store(case: ['relatedCases' => [['uuid' => 'primair-1'], 'other-9']]);
		$this->userSession->method('getUser')->willReturn(null);

		$objection = $this->hook()->onBezwaarCreated('bezwaar-1', 'decision-1', ['grounds' => 'Te laat']);

		$this->assertSame(['30'], array_column($store->saves, 0), 'only the objection is saved');
		$this->assertSame('system', $objection['registeredBy']);
		$this->assertSame('Te laat', $objection['grounds']);
	}//end testAnExistingLinkIsLeftAloneAndTheSystemRegisters()

	/**
	 * A signed-in user is the registrar.
	 *
	 * @return void
	 */
	public function testTheSignedInUserRegisters(): void {
		$this->store(case: ['relatedCases' => ['primair-1']]);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('behandelaar');
		$this->userSession->method('getUser')->willReturn($user);

		$this->assertSame('behandelaar', $this->hook()->onBezwaarCreated('bezwaar-1', 'decision-1')['registeredBy']);
	}//end testTheSignedInUserRegisters()
}//end class
