<?php

/**
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * What TussenrapportageService stores for an expected, approved or partly approved report.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Subsidie
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Subsidie;

use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Subsidie\TussenrapportageService;
use OCA\Dossiq\Tests\Support\InMemoryRegister;
use OCP\AppFramework\OCS\OCSBadRequestException;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Creating and assessing interim reports.
 *
 * @covers \OCA\Dossiq\Service\Subsidie\TussenrapportageService
 * @uses   \OCA\Dossiq\Service\Subsidie\SubsidieSchemaLookup
 * @uses   \OCA\Dossiq\Service\Support\SearchesObjects
 */
class TussenrapportagePersistenceTest extends TestCase {

	private InMemoryRegister $store;

	/**
	 * @return void
	 */
	protected function setUp(): void {
		$this->store = new InMemoryRegister();
	}//end setUp()

	/**
	 * The service under test.
	 *
	 * @param string|null          $uid    The signed-in uid, or null for nobody.
	 * @param LoggerInterface|null $logger The logger.
	 * @param string               $schema The configured schema.
	 *
	 * @return TussenrapportageService
	 */
	private function service(?string $uid = 'beoordelaar', ?LoggerInterface $logger = null, string $schema = 'tussenrapportage'): TussenrapportageService {
		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($this->store);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'tussenrapportage_schema' => $schema][$key] ?? $default)
		);
		$user = null;
		if ($uid !== null) {
			$user = $this->createMock(IUser::class);
			$user->method('getUID')->willReturn($uid);
		}
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);

		return new TussenrapportageService(settingsService: $settings, userSession: $session, logger: ($logger ?? new NullLogger()));
	}//end service()

	/**
	 * An expected report is linked, starts as expected with numerator 0, and forged identity keys are dropped.
	 *
	 * @return void
	 */
	public function testCreateExpectedStoresAnExpectedReport(): void {
		$stored = $this->service()->createExpected(uitvoeringId: 'u-1', payload: [
			'id' => 'forged',
			'period' => '2026-H1',
			'status' => 'approved',
			'amendmentNumerator' => 5,
		]);

		$this->assertSame(['period' => '2026-H1', 'status' => 'expected', 'amendmentNumerator' => 0, 'subsidieuitvoering' => 'u-1', 'id' => 'generated-1'], $stored);
	}//end testCreateExpectedStoresAnExpectedReport()

	/**
	 * A failed save is logged and refused; an unconfigured schema is refused.
	 *
	 * @return void
	 */
	public function testCreateExpectedRefusals(): void {
		$this->store->refusedSchemas = ['tussenrapportage'];
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with($this->stringStartsWith('Dossiq subsidie: createExpected tussenrapportage failed: '));
		try {
			$this->service(logger: $logger)->createExpected(uitvoeringId: 'u-1', payload: []);
			$this->fail('a refused save must refuse');
		} catch (OCSBadRequestException $e) {
			$this->assertSame('Kon tussenrapportage niet aanmaken', $e->getMessage());
		}

		$this->expectExceptionMessage('Tussenrapportage-schema is niet geconfigureerd');
		$this->service(schema: '')->createExpected(uitvoeringId: 'u-1', payload: []);
	}//end testCreateExpectedRefusals()

	/**
	 * Approving records the assessor, the date, and the optional judgement and amount.
	 *
	 * @return void
	 */
	public function testApproveReportPatchesTheAssessment(): void {
		$this->store->seed(schema: 'tussenrapportage', uuid: 'r-1', row: ['status' => 'submitted', 'period' => '2026-H1']);

		$result = $this->service()->approveReport(reportId: 'r-1', beoordelingsoordeel: 'goed', approvedAmount: 1200.5);

		$row = $this->store->row(schema: 'tussenrapportage', uuid: 'r-1');
		$this->assertSame('approved', $row['status']);
		$this->assertSame('beoordelaar', $row['assessor']);
		$this->assertSame('goed', $row['beoordelingsoordeel']);
		$this->assertSame(1200.5, $row['approvedAmount']);
		$this->assertSame('2026-H1', $row['period']);
		$this->assertNotFalse(\DateTimeImmutable::createFromFormat(\DateTimeImmutable::ATOM, $row['beoordelingsdatum']));
		$this->assertSame($row, $result);
	}//end testApproveReportPatchesTheAssessment()

	/**
	 * Without a judgement or amount neither field is written.
	 *
	 * @return void
	 */
	public function testApproveReportLeavesOptionalFieldsOut(): void {
		$this->store->seed(schema: 'tussenrapportage', uuid: 'r-1', row: ['status' => 'submitted']);
		$this->service()->approveReport(reportId: 'r-1');

		$row = $this->store->row(schema: 'tussenrapportage', uuid: 'r-1');
		$this->assertArrayNotHasKey('beoordelingsoordeel', $row);
		$this->assertArrayNotHasKey('approvedAmount', $row);
	}//end testApproveReportLeavesOptionalFieldsOut()

	/**
	 * Nobody signed in may not approve, and nothing is written.
	 *
	 * @return void
	 */
	public function testApproveReportRequiresASignedInAssessor(): void {
		$this->store->seed(schema: 'tussenrapportage', uuid: 'r-1', row: ['status' => 'submitted']);
		try {
			$this->service(uid: null)->approveReport(reportId: 'r-1');
			$this->fail('anonymous approval must refuse');
		} catch (OCSBadRequestException $e) {
			$this->assertSame('Authenticatie vereist om te beoordelen', $e->getMessage());
		}

		$this->assertSame(0, $this->store->writes);
	}//end testApproveReportRequiresASignedInAssessor()

	/**
	 * Approving a report that does not exist is logged and refused.
	 *
	 * @return void
	 */
	public function testApproveReportOfAMissingReportIsRefused(): void {
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects($this->once())->method('error')->with('Dossiq subsidie: approveReport failed: object_not_found_for_partial_write');

		$this->expectException(OCSBadRequestException::class);
		$this->expectExceptionMessage('Kon tussenrapportage niet goedkeuren');
		$this->service(logger: $logger)->approveReport(reportId: 'missing');
	}//end testApproveReportOfAMissingReportIsRefused()

	/**
	 * A partial approval stores the correction request and bumps the numerator.
	 *
	 * @return void
	 */
	public function testPartialApproveBumpsTheAmendmentNumerator(): void {
		$this->store->seed(schema: 'tussenrapportage', uuid: 'r-1', row: ['status' => 'submitted', 'amendmentNumerator' => 2]);

		$this->service()->partialApprove(reportId: 'r-1', correctionRequest: 'Bijlage ontbreekt', currentTeller: 2);

		$row = $this->store->row(schema: 'tussenrapportage', uuid: 'r-1');
		$this->assertSame('gedeeltelijk_approved', $row['status']);
		$this->assertSame('Bijlage ontbreekt', $row['correctionRequest']);
		$this->assertSame(3, $row['amendmentNumerator']);
		$this->assertSame('beoordelaar', $row['assessor']);
	}//end testPartialApproveBumpsTheAmendmentNumerator()

	/**
	 * A blank correction request, an anonymous caller and a failed write are each refused.
	 *
	 * @return void
	 */
	public function testPartialApproveRefusals(): void {
		$this->store->seed(schema: 'tussenrapportage', uuid: 'r-1', row: ['status' => 'submitted']);
		$cases = [
			['   ', 'x', 'Een correctieverzoek is verplicht bij gedeeltelijke goedkeuring'],
			['fix', null, 'Authenticatie vereist om te beoordelen'],
		];
		foreach ($cases as [$request, $uid, $message]) {
			try {
				$this->service(uid: $uid)->partialApprove(reportId: 'r-1', correctionRequest: $request, currentTeller: 0);
				$this->fail('should refuse');
			} catch (OCSBadRequestException $e) {
				$this->assertSame($message, $e->getMessage());
			}
		}
		$this->assertSame(0, $this->store->writes);

		$this->store->refusedSchemas = ['tussenrapportage'];
		$this->expectExceptionMessage('Kon tussenrapportage niet gedeeltelijk goedkeuren');
		$this->service()->partialApprove(reportId: 'r-1', correctionRequest: 'fix', currentTeller: 0);
	}//end testPartialApproveRefusals()
}//end class
