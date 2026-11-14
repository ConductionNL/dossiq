<?php

/**
 * Tests for the branches of the case outcome product issuer.
 *
 * @category Test
 * @package  OCA\Dossiq\Tests\Unit\Service\Product
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

namespace OCA\Dossiq\Tests\Unit\Service\Product;

use OCA\Dossiq\Service\Product\CaseOutcomeProductIssuer;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Which outcomes issue or change a product, and how a failure is reported.
 *
 * @covers \OCA\Dossiq\Service\Product\CaseOutcomeProductIssuer
 * @uses   \OCA\Dossiq\Service\Product\ProductOutcome
 * @uses   \OCA\Dossiq\Service\Product\ProductDecision
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class CaseOutcomeProductIssuerBranchesTest extends TestCase {

	/**
	 * The stand-in register.
	 *
	 * @var InMemoryRegister
	 */
	private InMemoryRegister $register;

	/**
	 * The logger.
	 *
	 * @var LoggerInterface|MockObject
	 */
	private LoggerInterface $logger;

	/**
	 * Seed a case of an issuing type.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->register = new InMemoryRegister();
		$this->register->seed(
			schema: 'case',
			uuid: 'case-1',
			row: ['caseType' => 'type-1', 'portalSubject' => ' subj-sanne ', 'title' => ' Parkeervergunning Lindelaan 4 ']
		);
		$this->register->seed(schema: 'caseType', uuid: 'type-1', row: ['issuesPermit' => ['kind' => 'parkeren']]);
		$this->logger = $this->createMock(LoggerInterface::class);
	}//end setUp()

	/**
	 * The issuer under test.
	 *
	 * @param object|null $objectService The object service; the register when omitted.
	 * @param string      $register      The configured register.
	 *
	 * @return CaseOutcomeProductIssuer
	 */
	private function issuer(?object $objectService = null, string $register = 'dossiq'): CaseOutcomeProductIssuer {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($objectService ?? $this->register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($key === 'register' ? $register : $default)
		);

		return new CaseOutcomeProductIssuer(settingsService: $settings, logger: $this->logger);
	}//end issuer()

	/**
	 * A declaration without a title template or schema issues a permit titled after the case.
	 *
	 * @return void
	 */
	public function testTheDefaultsTitleTheProductAfterTheCase(): void {
		$product = $this->issuer()->issueFor(caseId: 'case-1', decisionId: 'dec-1', status: ' APPROVED ', decidedAt: '2026-10-05T14:00:00+02:00');

		self::assertSame(
			[
				'title' => 'Parkeervergunning Lindelaan 4',
				'portalSubject' => 'subj-sanne',
				'case' => 'case-1',
				'decision' => 'dec-1',
				'validFrom' => '2026-10-05',
				'status' => 'active',
				'kind' => 'parkeren',
				'id' => $product['id'],
			],
			$product
		);
		self::assertCount(1, $this->register->all(schema: 'permit'));
	}//end testTheDefaultsTitleTheProductAfterTheCase()

	/**
	 * A declared schema and issue status are used instead of the defaults.
	 *
	 * @return void
	 */
	public function testADeclaredSchemaAndStatusAreUsed(): void {
		$this->register->seed(schema: 'caseType', uuid: 'type-1', row: ['issuesPermit' => ['schema' => 'licence', 'issueOn' => ['granted']]]);

		self::assertNull($this->issuer()->issueFor(caseId: 'case-1', decisionId: 'dec-1', status: 'approved', decidedAt: null));
		self::assertNotNull($this->issuer()->issueFor(caseId: 'case-1', decisionId: 'dec-1', status: 'Granted', decidedAt: null));
		self::assertCount(1, $this->register->all(schema: 'licence'));
		self::assertSame([], $this->register->all(schema: 'permit'));
	}//end testADeclaredSchemaAndStatusAreUsed()

	/**
	 * Without a usable decision moment the product is valid from a calendar day.
	 *
	 * @return void
	 */
	public function testAnUnusableDecisionMomentStillGivesADay(): void {
		$product = $this->issuer()->issueFor(caseId: 'case-1', decisionId: 'dec-1', status: 'approved', decidedAt: 'gisteren');

		self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $product['validFrom']);
	}//end testAnUnusableDecisionMomentStillGivesADay()

	/**
	 * No store, no register, a blank case, an unknown case or a type declaring nothing issues nothing.
	 *
	 * @return void
	 */
	public function testNothingIsIssuedWithoutAStoreACaseOrADeclaration(): void {
		self::assertNull($this->issuer(register: '')->issueFor(caseId: 'case-1', decisionId: 'dec-1', status: 'approved', decidedAt: null));
		self::assertNull($this->issuer()->issueFor(caseId: ' ', decisionId: 'dec-1', status: 'approved', decidedAt: null));
		self::assertNull($this->issuer()->issueFor(caseId: 'missing', decisionId: 'dec-1', status: 'approved', decidedAt: null));

		$this->register->seed(schema: 'caseType', uuid: 'type-1', row: ['issuesPermit' => []]);
		self::assertNull($this->issuer()->issueFor(caseId: 'case-1', decisionId: 'dec-1', status: 'approved', decidedAt: null));

		$this->register->seed(schema: 'case', uuid: 'case-2', row: ['portalSubject' => 'subj-sanne']);
		self::assertNull($this->issuer()->issueFor(caseId: 'case-2', decisionId: 'dec-1', status: 'approved', decidedAt: null));
		self::assertSame(0, $this->register->writes);
	}//end testNothingIsIssuedWithoutAStoreACaseOrADeclaration()

	/**
	 * A case without a holder issues nothing, and says so.
	 *
	 * @return void
	 */
	public function testACaseWithoutAHolderIsLogged(): void {
		$this->register->seed(schema: 'case', uuid: 'case-1', row: ['caseType' => 'type-1', 'portalSubject' => ' ']);
		$this->logger->expects(self::once())->method('info')->with('Dossiq: a case without a portal subject issues no product', ['case' => 'case-1']);

		self::assertNull($this->issuer()->issueFor(caseId: 'case-1', decisionId: 'dec-1', status: 'approved', decidedAt: null));
	}//end testACaseWithoutAHolderIsLogged()

	/**
	 * A change naming another resident's or a revoked product changes nothing, and is logged.
	 *
	 * @return void
	 */
	public function testAChangeToAProductNotInForceIsLogged(): void {
		$this->register->seed(schema: 'case', uuid: 'change-1', row: ['caseType' => 'type-2', 'portalSubject' => 'subj-sanne', 'permit' => 'permit-1', 'nieuwKenteken' => 'HX901B']);
		$this->register->seed(schema: 'caseType', uuid: 'type-2', row: ['issuesPermit' => ['changesProduct' => ['answer' => 'permit', 'set' => ['kenteken' => 'nieuwKenteken']]]]);
		$this->register->seed(schema: 'permit', uuid: 'permit-1', row: ['portalSubject' => 'subj-sanne', 'status' => 'revoked', 'kenteken' => 'GZ482K']);

		$this->logger->expects(self::once())
			->method('warning')
			->with('Dossiq: a change case names a product that is not its requester\'s or not in force', ['case' => 'change-1', 'product' => 'permit-1']);

		self::assertNull($this->issuer()->issueFor(caseId: 'change-1', decisionId: 'dec-2', status: 'approved', decidedAt: null));
		self::assertSame('GZ482K', $this->register->row(schema: 'permit', uuid: 'permit-1')['kenteken']);
	}//end testAChangeToAProductNotInForceIsLogged()

	/**
	 * A change whose answers are all empty, or naming no product, writes nothing.
	 *
	 * @return void
	 */
	public function testAChangeWithNothingToSetWritesNothing(): void {
		$this->register->seed(schema: 'case', uuid: 'change-1', row: ['caseType' => 'type-2', 'portalSubject' => 'subj-sanne', 'permit' => 'permit-1']);
		$this->register->seed(schema: 'caseType', uuid: 'type-2', row: ['issuesPermit' => ['changesProduct' => ['answer' => 'permit', 'set' => ['kenteken' => 'nieuwKenteken']]]]);
		$this->register->seed(schema: 'permit', uuid: 'permit-1', row: ['portalSubject' => 'subj-sanne', 'status' => 'active']);
		$writes = $this->register->writes;

		self::assertNull($this->issuer()->issueFor(caseId: 'change-1', decisionId: 'dec-2', status: 'approved', decidedAt: null));

		$this->register->seed(schema: 'case', uuid: 'change-1', row: ['caseType' => 'type-2', 'portalSubject' => 'subj-sanne']);
		self::assertNull($this->issuer()->issueFor(caseId: 'change-1', decisionId: 'dec-2', status: 'approved', decidedAt: null));
		self::assertSame($writes, $this->register->writes);
	}//end testAChangeWithNothingToSetWritesNothing()

	/**
	 * A register failure is swallowed by the decision listener entry point and logged.
	 *
	 * @return void
	 */
	public function testAFailureOnConcludedDecisionIsLoggedNotThrown(): void {
		$failing = new class {
			/**
			 * Fail every find.
			 *
			 * @param string     $id       The id.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return never
			 */
			public function find(string $id, int|string $register = '', int|string $schema = ''): never {
				throw new RuntimeException('register down');
			}
		};

		$this->logger->expects(self::once())
			->method('warning')
			->with(
				'Dossiq: the decision concluded, but the product it issues could not be written',
				['case' => 'case-1', 'decision' => 'dec-1', 'error' => 'register down']
			);

		$this->issuer(objectService: $failing)->onConcludedDecision(caseId: 'case-1', decisionId: 'dec-1', status: 'approved', decidedAt: null);
	}//end testAFailureOnConcludedDecisionIsLoggedNotThrown()

	/**
	 * A register failure reaches the caller of issueFor.
	 *
	 * @return void
	 */
	public function testAFailureReachesTheCallerOfIssueFor(): void {
		$failing = new class {
			/**
			 * Fail every find.
			 *
			 * @param string     $id       The id.
			 * @param int|string $register The register.
			 * @param int|string $schema   The schema.
			 *
			 * @return never
			 */
			public function find(string $id, int|string $register = '', int|string $schema = ''): never {
				throw new RuntimeException('register down');
			}
		};

		$this->expectException(RuntimeException::class);
		$this->expectExceptionMessage('register down');

		$this->issuer(objectService: $failing)->issueFor(caseId: 'case-1', decisionId: 'dec-1', status: 'approved', decidedAt: null);
	}//end testAFailureReachesTheCallerOfIssueFor()
}//end class
