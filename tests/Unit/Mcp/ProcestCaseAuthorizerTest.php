<?php

/**
 * ProcestCaseAuthorizer Unit Tests
 *
 * Covers the MCP read-authorisation decision (OWASP A01:2021 / ADR-005). The
 * class decides whether the calling user may read a case, so every branch that
 * can GRANT access needs a control that shows it also DENIES: unauthenticated,
 * non-admin non-assignee without a role record, and a role search that throws.
 *
 * The role-record branch is exercised through the public canReadCase() entry
 * point rather than by reflecting into the private helper, so the test travels
 * the same path the MCP reader does.
 *
 * @category Tests
 * @package  OCA\Procest\Tests\Unit\Mcp
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://procest.nl
 *
 * @spec openspec/specs/mcp-integration/spec.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Mcp;

use OCA\Procest\Mcp\Tool\ProcestCaseAuthorizer;
use OCA\Procest\Service\SettingsService;
use OCP\IGroupManager;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * Test double that stubs the OpenRegister role search.
 *
 * Overrides the SearchesObjects trait method so the authoriser's own decision
 * logic runs unchanged while the search result is controlled by the test.
 *
 * @spec openspec/specs/mcp-integration/spec.md
 */
class StubbedRoleSearchAuthorizer extends ProcestCaseAuthorizer {
	/**
	 * Filters the authoriser passed to the role search, captured for assertion.
	 *
	 * @var array<string, mixed>
	 */
	public array $capturedFilters = [];

	/**
	 * Rows the stubbed search returns, or a throwable it raises instead.
	 *
	 * @var array<int, array<string, mixed>>|\Throwable
	 */
	private array|\Throwable $roleRows;

	/**
	 * Constructor.
	 *
	 * @param array<int, array<string, mixed>>|\Throwable $roleRows Rows to return, or a throwable to raise.
	 * @param SettingsService $settingsService The Procest settings service.
	 * @param IUserSession $userSession The current user session.
	 * @param IGroupManager $groupManager The group manager.
	 * @param LoggerInterface $logger The PSR-3 logger.
	 *
	 * @return void
	 */
	public function __construct(
		array|\Throwable $roleRows,
		SettingsService $settingsService,
		IUserSession $userSession,
		IGroupManager $groupManager,
		LoggerInterface $logger,
	) {
		$this->roleRows = $roleRows;
		parent::__construct(
			settingsService: $settingsService,
			userSession: $userSession,
			groupManager: $groupManager,
			logger: $logger
		);
	}//end __construct()

	/**
	 * Return the configured role rows instead of querying OpenRegister.
	 *
	 * @param object $objectService The OpenRegister object service (unused here).
	 * @param int|string $register The register id or slug (unused here).
	 * @param int|string $schema The schema id or slug (unused here).
	 * @param array<string, mixed> $filters The search filters, captured for assertion.
	 *
	 * @return array<int, array<string, mixed>> The configured rows.
	 *
	 * @throws \Throwable When the test configured a failing search.
	 *
	 * @spec openspec/specs/mcp-integration/spec.md
	 */
	protected function searchObjectsAsArrays(
		object $objectService,
		int|string $register,
		int|string $schema,
		array $filters = [],
	): array {
		$this->capturedFilters = $filters;
		if ($this->roleRows instanceof \Throwable) {
			throw $this->roleRows;
		}

		return $this->roleRows;
	}//end searchObjectsAsArrays()
}//end class

/**
 * Unit tests for ProcestCaseAuthorizer::canReadCase().
 *
 * @spec openspec/specs/mcp-integration/spec.md
 */
class ProcestCaseAuthorizerTest extends TestCase {
	/**
	 * The stubbed settings service.
	 *
	 * @var SettingsService&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $settingsService;

	/**
	 * The stubbed user session.
	 *
	 * @var IUserSession&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $userSession;

	/**
	 * The stubbed group manager.
	 *
	 * @var IGroupManager&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $groupManager;

	/**
	 * The stubbed logger.
	 *
	 * @var LoggerInterface&\PHPUnit\Framework\MockObject\MockObject
	 */
	private $logger;

	/**
	 * Wire the collaborators to a configured, non-admin default.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		parent::setUp();

		$this->settingsService = $this->createMock(SettingsService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->groupManager = $this->createMock(IGroupManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->settingsService->method('getObjectService')->willReturn(new \stdClass());
		$this->settingsService->method('getConfigValue')->willReturnMap(
			[
				['register', '', 'procest'],
				['role_schema', '', 'role'],
			]
		);

		$this->groupManager->method('isInGroup')->willReturn(false);
		$this->groupManager->method('isAdmin')->willReturn(false);
	}//end setUp()

	/**
	 * Sign a user in for the duration of a test.
	 *
	 * @param string $userId The uid the session should report.
	 *
	 * @return void
	 */
	private function signIn(string $userId): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);
	}//end signIn()

	/**
	 * Build an authoriser whose role search returns the given rows.
	 *
	 * @param array<int, array<string, mixed>>|\Throwable $roleRows Rows to return, or a throwable to raise.
	 *
	 * @return StubbedRoleSearchAuthorizer The authoriser under test.
	 */
	private function authorizer(array|\Throwable $roleRows): StubbedRoleSearchAuthorizer {
		return new StubbedRoleSearchAuthorizer(
			roleRows: $roleRows,
			settingsService: $this->settingsService,
			userSession: $this->userSession,
			groupManager: $this->groupManager,
			logger: $this->logger
		);
	}//end authorizer()

	/**
	 * A non-admin who is not the assignee is DENIED when no role record links them.
	 *
	 * This is the must-FAIL control for the role-record grant below: without it,
	 * a search stub that always returned a row would make both tests pass.
	 *
	 * @return void
	 */
	public function testDeniesWhenNoRoleRecordLinksTheUserToTheCase(): void {
		$this->signIn('bob');

		$authorizer = $this->authorizer([]);

		$this->assertFalse(
			$authorizer->canReadCase(case: ['assignee' => 'alice'], caseUuid: 'case-1'),
			'A non-admin non-assignee with zero role records must not read the case'
		);
	}//end testDeniesWhenNoRoleRecordLinksTheUserToTheCase()

	/**
	 * A non-admin who is not the assignee is ALLOWED when a role record links them.
	 *
	 * Also asserts the search is scoped to both the case AND the caller — a
	 * filter that dropped `participant` would return another user's role row
	 * and still make the count-based grant fire.
	 *
	 * @return void
	 */
	public function testAllowsWhenARoleRecordLinksTheUserToTheCase(): void {
		$this->signIn('bob');

		$authorizer = $this->authorizer([['participant' => 'bob', 'case' => 'case-1']]);

		$this->assertTrue(
			$authorizer->canReadCase(case: ['assignee' => 'alice'], caseUuid: 'case-1'),
			'A role record linking the caller to the case must grant read access'
		);
		$this->assertSame('case-1', $authorizer->capturedFilters['case']);
		$this->assertSame('bob', $authorizer->capturedFilters['participant']);
	}//end testAllowsWhenARoleRecordLinksTheUserToTheCase()

	/**
	 * A failing role search is fail-closed, not fail-open.
	 *
	 * @return void
	 */
	public function testDeniesWhenTheRoleSearchThrows(): void {
		$this->signIn('bob');

		$authorizer = $this->authorizer(new \RuntimeException('OpenRegister unavailable'));

		$this->assertFalse(
			$authorizer->canReadCase(case: ['assignee' => 'alice'], caseUuid: 'case-1'),
			'A failing role search must deny, not grant'
		);
	}//end testDeniesWhenTheRoleSearchThrows()

	/**
	 * An unauthenticated caller is denied before any search runs.
	 *
	 * @return void
	 */
	public function testDeniesAnUnauthenticatedCaller(): void {
		$this->userSession->method('getUser')->willReturn(null);

		$authorizer = $this->authorizer([['participant' => 'bob']]);

		$this->assertFalse(
			$authorizer->canReadCase(case: ['assignee' => 'bob'], caseUuid: 'case-1'),
			'An unauthenticated caller must be denied even when a role row exists'
		);
		$this->assertSame([], $authorizer->capturedFilters, 'No role search may run for an anonymous caller');
	}//end testDeniesAnUnauthenticatedCaller()

	/**
	 * An empty case uuid never reaches the role search.
	 *
	 * @return void
	 */
	public function testDeniesWhenTheCaseUuidIsEmpty(): void {
		$this->signIn('bob');

		$authorizer = $this->authorizer([['participant' => 'bob']]);

		$this->assertFalse(
			$authorizer->canReadCase(case: ['assignee' => 'alice'], caseUuid: ''),
			'An empty case uuid must deny rather than search unscoped'
		);
		$this->assertSame([], $authorizer->capturedFilters, 'No role search may run without a case uuid');
	}//end testDeniesWhenTheCaseUuidIsEmpty()

	/**
	 * The assignee reads their own case without a role record.
	 *
	 * @return void
	 */
	public function testAllowsTheAssigneeWithoutARoleRecord(): void {
		$this->signIn('bob');

		$authorizer = $this->authorizer([]);

		$this->assertTrue(
			$authorizer->canReadCase(case: ['assignee' => 'bob'], caseUuid: 'case-1'),
			'The assignee must be able to read their own case'
		);
	}//end testAllowsTheAssigneeWithoutARoleRecord()
}//end class
