<?php

/**
 * HouseholdCasesController Unit Tests
 *
 * The household card answers only a reader who may see the social domain's
 * sensitive fields and read the case, and an other-domain row never leaves the
 * endpoint with more than exists, domain and contact, also when the session
 * holds more.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Controller
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @version GIT: <git-id>
 *
 * @link https://conduction.nl
 *
 * @spec openspec/changes/the-social-domain-plan-and-its-grounds/specs/dossiq-sociaal-domein-avg-consent/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Controller;

use OCA\Dossiq\Controller\HouseholdCasesController;
use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\CitizenLookupGuard;
use OCA\Dossiq\Service\SociaalDomein\HouseholdCases;
use OCP\AppFramework\Http;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for HouseholdCasesController.
 */
class HouseholdCasesControllerTest extends TestCase {
	/**
	 * What the session holds, by key.
	 *
	 * @var array<string, mixed>
	 */
	private array $sessionData = [];

	/**
	 * A controller over these answers.
	 *
	 * @param boolean $sensitive Whether the reader may see sensitive fields.
	 * @param boolean $readable  Whether the reader may read the case.
	 * @param string  $ground    The ground the request carries.
	 * @param ?CitizenLookupGuard $guard A real guard instead of one answering $sensitive.
	 *
	 * @return array{0: HouseholdCasesController, 1: HouseholdCases}
	 */
	private function controller(bool $sensitive = true, bool $readable = true, string $ground = '', ?CitizenLookupGuard $guard = null): array {
		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getParam')->willReturnMap([['ground', '', $ground]]);
		$user = $this->createMock(originalClassName: IUser::class);
		$user->method('getUID')->willReturn('fatma');
		$userSession = $this->createMock(originalClassName: IUserSession::class);
		$userSession->method('getUser')->willReturn($user);
		$session = $this->createMock(originalClassName: ISession::class);
		$session->method('get')->willReturnCallback(fn (string $key): mixed => ($this->sessionData[$key] ?? null));
		$session->method('set')->willReturnCallback(function (string $key, mixed $value): void {
			$this->sessionData[$key] = $value;
		});
		$caseAccess = $this->createMock(originalClassName: CaseAccessGuard::class);
		$caseAccess->method('hasCaseReadAccess')->willReturn($readable);
		if ($guard === null) {
			$guard = $this->createMock(originalClassName: CitizenLookupGuard::class);
			$guard->method('maySeeSensitiveFields')->willReturn($sensitive);
		}
		$household = $this->createMock(originalClassName: HouseholdCases::class);
		$household->method('readCase')->willReturn(['id' => 'case-1', 'gezinsplanId' => 'plan-1']);
		$household->method('householdBsns')->willReturn(['111222333']);

		return [
			new HouseholdCasesController(
				request: $request,
				userSession: $userSession,
				session: $session,
				caseAccess: $caseAccess,
				sensitive: $guard,
				household: $household,
				logger: new NullLogger(),
			),
			$household,
		];
	}//end controller()

	/**
	 * A reader outside dossiq-sensitive is refused, and nothing is read.
	 *
	 * @return void
	 */
	public function testAReaderWithoutSensitiveAccessIsRefused(): void {
		[$controller, $household] = $this->controller(sensitive: false);
		$household->expects($this->never())->method('sameDomain');
		$household->expects($this->never())->method('otherDomains');

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->index(caseId: 'case-1')->getStatus());
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->lookUp(caseId: 'case-1')->getStatus());
	}//end testAReaderWithoutSensitiveAccessIsRefused()

	/**
	 * An administrator outside dossiq-sensitive is refused: a household is not
	 * opened by administering the instance. The real guard is used, over a
	 * group manager that answers "admin" and "not in the group".
	 *
	 * @return void
	 */
	public function testAnAdministratorOutsideTheSensitiveGroupIsRefused(): void {
		$groups = $this->createMock(originalClassName: IGroupManager::class);
		$groups->method('isAdmin')->willReturn(true);
		$groups->method('isInGroup')->willReturnCallback(
			static fn (string $uid, string $group): bool => $group === 'admin'
		);
		[$controller, $household] = $this->controller(guard: new CitizenLookupGuard(groupManager: $groups));
		$household->expects($this->never())->method('sameDomain');
		$household->expects($this->never())->method('otherDomains');

		$response = $controller->index(caseId: 'case-1');
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $response->getStatus());
		self::assertSame(expected: 'household-not-allowed', actual: $response->getData()['error']);
		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->lookUp(caseId: 'case-1')->getStatus());
	}//end testAnAdministratorOutsideTheSensitiveGroupIsRefused()

	/**
	 * A reader who may not read the case is refused too.
	 *
	 * @return void
	 */
	public function testAReaderWhoMayNotReadTheCaseIsRefused(): void {
		[$controller] = $this->controller(readable: false);

		self::assertSame(expected: Http::STATUS_FORBIDDEN, actual: $controller->index(caseId: 'case-1')->getStatus());
	}//end testAReaderWhoMayNotReadTheCaseIsRefused()

	/**
	 * 🔴 The lookup's rows come back on the next read, exists, domain and contact only.
	 *
	 * @return void
	 */
	public function testALookedUpDomainReturnsWithItsThreeFieldsOnly(): void {
		[$controller, $household] = $this->controller(ground: 'toestemming');
		$household->method('sameDomain')->willReturn([['caseId' => 'case-2', 'access' => 'full']]);
		$household->expects($this->once())->method('otherDomains')
			->with(['111222333'], $this->anything(), 'toestemming', 'fatma')
			->willReturn([['exists' => true, 'domain' => 'Wmo', 'contact' => 'anouk']]);

		self::assertSame(expected: Http::STATUS_OK, actual: $controller->lookUp(caseId: 'case-1')->getStatus());

		// Whatever else the session were to hold, nothing beyond the three travels.
		$key = array_key_first($this->sessionData);
		$this->sessionData[$key] = json_encode([['exists' => true, 'domain' => 'Wmo', 'contact' => 'anouk', 'caseNumber' => 'WMO-1', 'status' => 'open']]);

		$results = $controller->index(caseId: 'case-1')->getData()['results'];
		self::assertSame(expected: ['caseId' => 'case-2', 'access' => 'full'], actual: $results[0]);
		self::assertSame(expected: ['exists', 'domain', 'contact'], actual: array_keys($results[1]));
	}//end testALookedUpDomainReturnsWithItsThreeFieldsOnly()

	/**
	 * Before any lookup, no other-domain row is shown.
	 *
	 * @return void
	 */
	public function testNoOtherDomainBeforeALookup(): void {
		[$controller, $household] = $this->controller();
		$household->method('sameDomain')->willReturn([]);
		$household->expects($this->never())->method('otherDomains');

		self::assertSame(expected: [], actual: $controller->index(caseId: 'case-1')->getData()['results']);
	}//end testNoOtherDomainBeforeALookup()
}//end class
