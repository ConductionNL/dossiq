<?php

/**
 * A case-type read with nobody signed in runs as the system.
 *
 * Rehearsal R2 (15 Oct): installing the example set logged 32 "User
 * 'Anonymous' does not have permission to 'read' objects in schema 'Case
 * Type'" lines from the case plan projection and the statutory term's slug
 * lookup. An occ run has no user; OpenRegister refuses an anonymous read.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Support
 *
 * @spec openspec/specs/case-management/spec.md#requirement-a-read-or-write-with-nobody-signed-in-runs-as-the-system-req-cm-83
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Service\CasePlanProjectionService;
use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\SettingsService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * An object service that refuses an anonymous read, as OpenRegister does.
 */
class AnonymousRefusingObjectService {

	/**
	 * Whether the current call runs as the system.
	 *
	 * @var boolean
	 */
	public bool $inSystem = false;

	/**
	 * Whether a user is signed in.
	 *
	 * @var boolean
	 */
	public bool $signedIn = false;

	/**
	 * Run an operation as the system.
	 *
	 * @param callable $operation The operation.
	 *
	 * @return mixed What it returns.
	 */
	public function runAsSystem(callable $operation): mixed {
		$this->inSystem = true;
		try {
			return $operation();
		} finally {
			$this->inSystem = false;
		}
	}//end runAsSystem()

	/**
	 * Find a case type; refused to nobody.
	 *
	 * @param string $id       The id.
	 * @param mixed  $register The register.
	 * @param mixed  $schema   The schema.
	 *
	 * @return array<string, mixed> The row.
	 */
	public function find(string $id, mixed $register = null, mixed $schema = null): array {
		if ($this->inSystem === false && $this->signedIn === false) {
			throw new RuntimeException("User 'Anonymous' does not have permission to 'read' objects in schema 'Case Type'");
		}

		return ['id' => $id, 'identifier' => 'woo-verzoek', 'handlingModel' => 'bpmn'];
	}//end find()
}//end class

/**
 * The two case-type reads that ran during the example-set install.
 *
 * @covers \OCA\Dossiq\Service\CaseTypeSlugResolver
 * @covers \OCA\Dossiq\Service\CasePlanProjectionService
 */
class BackgroundReadsRunAsSystemTest extends TestCase {

	private const CASE_TYPE = '3c0f5a00-0000-4000-a000-00000000a001';

	/**
	 * The object service double.
	 *
	 * @var AnonymousRefusingObjectService
	 */
	private AnonymousRefusingObjectService $objects;

	/**
	 * The settings, pointing at the double.
	 *
	 * @var SettingsService
	 */
	private SettingsService $settings;

	/**
	 * Set up the double and the settings.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new AnonymousRefusingObjectService();
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => 'dossiq', 'case_type_schema' => 'caseType'][$key] ?? ''
		);
		$this->settings = $settings;
	}//end setUp()

	/**
	 * A session with or without a user.
	 *
	 * @param boolean $signedIn Whether somebody is signed in.
	 *
	 * @return IUserSession The session.
	 */
	private function session(bool $signedIn): IUserSession {
		$this->objects->signedIn = $signedIn;
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($signedIn === true ? $this->createMock(IUser::class) : null);

		return $session;
	}//end session()

	/**
	 * The statutory term's slug lookup reads the case type with nobody signed in.
	 *
	 * @return void
	 */
	public function testTheSlugLookupReadsAsTheSystemWithoutAUser(): void {
		$resolver = new CaseTypeSlugResolver($this->settings, $this->createMock(LoggerInterface::class), $this->session(signedIn: false));

		$this->assertSame('woo-verzoek', $resolver->toSlug(self::CASE_TYPE));
	}//end testTheSlugLookupReadsAsTheSystemWithoutAUser()

	/**
	 * The case plan projection reads the case type with nobody signed in.
	 *
	 * @return void
	 */
	public function testTheProjectionReadsTheCaseTypeAsTheSystemWithoutAUser(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->never())->method('error');
		$projection = new CasePlanProjectionService($this->settings, $logger, $this->session(signedIn: false));

		$this->assertSame('case_not_cmmn_managed', $projection->projectForCase(caseId: 'case-1', caseTypeId: self::CASE_TYPE)['reason']);
	}//end testTheProjectionReadsTheCaseTypeAsTheSystemWithoutAUser()

	/**
	 * Control: without the session the read is anonymous and refused, which
	 * is the line the rehearsal logged.
	 *
	 * @return void
	 */
	public function testWithoutTheSessionTheReadIsRefused(): void {
		$resolver = new CaseTypeSlugResolver($this->settings, $this->createMock(LoggerInterface::class));

		$this->assertSame('', $resolver->toSlug(self::CASE_TYPE));
	}//end testWithoutTheSessionTheReadIsRefused()

	/**
	 * Control: a signed-in caller reads with their own rights, never as the system.
	 *
	 * @return void
	 */
	public function testASignedInCallerIsNeverElevated(): void {
		$seen = [];
		$objects = new class($seen) extends AnonymousRefusingObjectService {
			/**
			 * @param array<int, bool> $seen Records each find's system flag.
			 */
			public function __construct(public array &$seen) {
			}

			/**
			 * @param string $id       The id.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array<string, mixed>
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): array {
				$this->seen[] = $this->inSystem;
				return parent::find($id, $register, $schema);
			}
		};
		$this->objects = $objects;
		$this->setUpSettingsFor(objects: $objects);

		$resolver = new CaseTypeSlugResolver($this->settings, $this->createMock(LoggerInterface::class), $this->session(signedIn: true));
		$resolver->toSlug(self::CASE_TYPE);

		$this->assertSame([false], $objects->seen);
	}//end testASignedInCallerIsNeverElevated()

	/**
	 * Point the settings at another double.
	 *
	 * @param AnonymousRefusingObjectService $objects The double.
	 *
	 * @return void
	 */
	private function setUpSettingsFor(AnonymousRefusingObjectService $objects): void {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ['register' => 'dossiq', 'case_type_schema' => 'caseType'][$key] ?? ''
		);
		$this->settings = $settings;
	}//end setUpSettingsFor()
}//end class
