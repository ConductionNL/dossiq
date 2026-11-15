<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * Who may manage the access links of a case: the assignee, anyone in the
 * assignees list, or whoever already created a share for it; a missing case is
 * refused, and setups without OpenRegister or configuration stay open.
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Sharing;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Sharing\CaseAccessPolicy;
use OCA\Dossiq\Service\Sharing\OpenRegisterSharingGateway;
use OCP\IGroupManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Sharing\CaseAccessPolicy
 */
class CaseAccessPolicyTest extends TestCase {

	/**
	 * The gateway.
	 *
	 * @var OpenRegisterSharingGateway&MockObject
	 */
	private OpenRegisterSharingGateway&MockObject $gateway;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface&MockObject
	 */
	private LoggerInterface&MockObject $logger;

	/**
	 * The configured values.
	 *
	 * @var array<string,string>
	 */
	private array $config = ['register' => '3', 'case_schema' => '7', 'case_share_schema' => '9'];

	/**
	 * Build the collaborators.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->gateway = $this->createMock(OpenRegisterSharingGateway::class);
		$this->gateway->method('toArray')->willReturnArgument(0);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The assignee and anyone in the assignees list may access the case; others may not.
	 *
	 * @return void
	 */
	public function testAssigneesMayAccessTheCase(): void {
		$objects = $this->objects(case: ['assignee' => 'els', 'assignees' => ['piet', 'jan']], shares: []);
		$policy  = $this->policy(objects: $objects);

		$this->assertTrue($policy->canUserAccessCase(caseId: 'case-1', userId: 'els'));
		$this->assertTrue($policy->canUserAccessCase(caseId: 'case-1', userId: 'jan'));
		$this->assertFalse($policy->canUserAccessCase(caseId: 'case-1', userId: 'kees'));
		$this->assertSame(array_fill(0, 3, ['case-1', 3, 7]), $objects->found);
	}//end testAssigneesMayAccessTheCase()

	/**
	 * Whoever created a share for the case may access it; a share by someone else does not count.
	 *
	 * @return void
	 */
	public function testTheCreatorOfAShareMayAccessTheCase(): void {
		$objects = $this->objects(case: ['assignee' => 'els'], shares: [['createdBy' => 'piet'], ['createdBy' => 'kees']]);
		$policy  = $this->policy(objects: $objects);

		$this->assertTrue($policy->canUserAccessCase(caseId: 'case-1', userId: 'kees'));
		$this->assertFalse($policy->canUserAccessCase(caseId: 'case-1', userId: 'jan'));
		$this->assertSame(array_fill(0, 2, ['filters' => ['register' => 3, 'schema' => 9, 'caseId' => 'case-1']]), $objects->listed);
	}//end testTheCreatorOfAShareMayAccessTheCase()

	/**
	 * Without a share schema, or when the share lookup fails, only assignees get in.
	 *
	 * @return void
	 */
	public function testNoShareSchemaOrAFailingLookupGrantsNothing(): void {
		$this->config['case_share_schema'] = '';
		$objects = $this->objects(case: ['assignee' => 'els'], shares: [['createdBy' => 'kees']]);
		$this->assertFalse($this->policy(objects: $objects)->canUserAccessCase(caseId: 'case-1', userId: 'kees'));
		$this->assertSame([], $objects->listed);

		$this->config['case_share_schema'] = '9';
		$failing = $this->objects(case: ['assignee' => 'els'], shares: [], listFails: new RuntimeException('index offline'));
		$this->logger->expects($this->once())->method('debug')->with(
			'CaseSharingService: share lookup in canUserAccessCase failed',
			['caseId' => 'case-1', 'exception' => 'index offline']
		);
		$this->assertFalse($this->policy(objects: $failing)->canUserAccessCase(caseId: 'case-1', userId: 'kees'));
	}//end testNoShareSchemaOrAFailingLookupGrantsNothing()

	/**
	 * A case that does not exist is refused, even to someone who might have shared it.
	 *
	 * @return void
	 */
	public function testAMissingCaseIsRefused(): void {
		$objects = $this->objects(case: null, shares: [['createdBy' => 'kees']]);

		$this->assertFalse($this->policy(objects: $objects)->canUserAccessCase(caseId: 'case-404', userId: 'kees'));
		$this->assertSame([], $objects->listed);
	}//end testAMissingCaseIsRefused()

	/**
	 * A case that cannot be loaded is let through and logged, as are setups without OpenRegister or configuration.
	 *
	 * @return void
	 */
	public function testUnreadableCasesAndBareSetupsStayOpen(): void {
		$failing = $this->objects(case: [], shares: [], findFails: new RuntimeException('db down'));
		$this->logger->expects($this->once())->method('warning')->with(
			'CaseSharingService: canUserAccessCase load failed',
			['caseId' => 'case-1', 'exception' => 'db down']
		);
		$this->assertTrue($this->policy(objects: $failing)->canUserAccessCase(caseId: 'case-1', userId: 'kees'));

		$this->assertTrue($this->policy(objects: null)->canUserAccessCase(caseId: 'case-1', userId: 'kees'));

		$this->config['case_schema'] = '';
		$unconfigured = $this->objects(case: null, shares: []);
		$this->assertTrue($this->policy(objects: $unconfigured)->canUserAccessCase(caseId: 'case-1', userId: 'kees'));
		$this->assertSame([], $unconfigured->found);
	}//end testUnreadableCasesAndBareSetupsStayOpen()

	/**
	 * The policy over the given object service, or none.
	 *
	 * @param object|null $objects The object service.
	 *
	 * @return CaseAccessPolicy
	 */
	private function policy(?object $objects): CaseAccessPolicy {
		$this->gateway = $this->createMock(OpenRegisterSharingGateway::class);
		$this->gateway->method('toArray')->willReturnArgument(0);
		$this->gateway->method('objectService')->willReturn($objects);
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getConfigValue')->willReturnCallback(fn (string $key): string => ($this->config[$key] ?? ''));
		$groups = $this->createMock(IGroupManager::class);
		$groups->method('isAdmin')->willReturn(false);

		return new CaseAccessPolicy(settingsService: $settings, gateway: $this->gateway, groupManager: $groups, logger: $this->logger);
	}//end policy()

	/**
	 * An object service answering one case and a list of shares.
	 *
	 * @param array|null      $case      The case, or null when it does not exist.
	 * @param array           $shares    The shares.
	 * @param \Throwable|null $findFails What finding the case throws.
	 * @param \Throwable|null $listFails What listing the shares throws.
	 *
	 * @return object
	 */
	private function objects(?array $case, array $shares, ?\Throwable $findFails = null, ?\Throwable $listFails = null): object {
		return new class($case, $shares, $findFails, $listFails) {
			/**
			 * The finds, as [id, register, schema].
			 *
			 * @var array<int,array>
			 */
			public array $found = [];

			/**
			 * The share listings, by config.
			 *
			 * @var array<int,array>
			 */
			public array $listed = [];

			/**
			 * @param array|null      $case      The case.
			 * @param array           $shares    The shares.
			 * @param \Throwable|null $findFails What find throws.
			 * @param \Throwable|null $listFails What findAll throws.
			 */
			public function __construct(private ?array $case, private array $shares, private ?\Throwable $findFails, private ?\Throwable $listFails) {
			}

			/**
			 * @return array|null
			 */
			public function find(string $id, array $_extend = [], bool $files = false, int|string|null $register = null, int|string|null $schema = null): ?array {
				$this->found[] = [$id, $register, $schema];
				if ($this->findFails !== null) {
					throw $this->findFails;
				}

				return $this->case;
			}

			/**
			 * @return array
			 */
			public function findAll(array $config = []): array {
				$this->listed[] = $config;
				if ($this->listFails !== null) {
					throw $this->listFails;
				}

				return $this->shares;
			}
		};
	}//end objects()
}//end class
