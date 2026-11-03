<?php

/**
 * A new disclosure assessment cannot be pointed at another case's assessment.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/authz-bypass-fixes/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service;

use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\WOODocumentAssessmentService;
use OCA\Dossiq\Tests\Support\LifecycleRegister;
use OCA\Dossiq\Woo\WooRefusalGrounds;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * An assessment in a bulk upsert that carries `id`, `uuid` or `@self` is
 * created as a new one on the caller's case; the assessment those fields name
 * stays on its own case, as it was.
 *
 * @covers \OCA\Dossiq\Service\WOODocumentAssessmentService
 * @uses \OCA\Dossiq\Service\Support\SearchesObjects
 * @uses \OCA\Dossiq\Woo\WooRefusalGrounds
 * @uses \OCA\Dossiq\Review\DisclosureAssessmentValidator
 * @uses \OCA\Dossiq\Review\OutstandingDocuments
 */
class WooAssessmentForgedIdTest extends TestCase {

	/**
	 * The stored assessment the forged fields name, on another case.
	 */
	private const VICTIM = ['caseRef' => 'case-2', 'documentRef' => 'doc-secret', 'classification' => 'niet_openbaar'];

	/**
	 * Each way a payload can name a stored object.
	 *
	 * @return array<string, array{0: array<string, mixed>}>
	 */
	public static function forged(): array {
		return [
			'id' => [['id' => 'victim-1']],
			'@self.id' => [['@self' => ['id' => 'victim-1']]],
			'all three' => [['id' => 'victim-1', 'uuid' => 'victim-1', '@self' => ['id' => 'victim-1']]],
		];
	}//end forged()

	/**
	 * A forged id opens a new assessment and leaves the named one on its case.
	 *
	 * @param array<string, mixed> $identity The forged fields.
	 *
	 * @return void
	 *
	 * @dataProvider forged
	 */
	public function testAForgedIdDoesNotTouchAnotherCasesAssessment(array $identity): void {
		$register = new LifecycleRegister();
		$register->seed(schema: 'wooDocumentAssessment', uuid: 'victim-1', row: self::VICTIM);

		$settings = $this->createMock(SettingsService::class);
		$settings->method('getObjectService')->willReturn($register);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => (['register' => 'dossiq', 'woo_assessment_schema' => 'wooDocumentAssessment'][$key] ?? $default)
		);
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('reviewer-on-case-1');
		$session = $this->createMock(IUserSession::class);
		$session->method('getUser')->willReturn($user);
		$logger  = new NullLogger();
		$service = new WOODocumentAssessmentService(
			$settings,
			$session,
			$logger,
			null,
			new WooRefusalGrounds(settingsService: $settings, logger: $logger),
			reviewerAccess: $this->createMock(CaseReviewerAccess::class),
		);

		$service->bulkUpsert('case-1', [$identity + ['documentRef' => 'doc-new', 'classification' => 'openbaar']]);

		$this->assertSame(self::VICTIM + ['id' => 'victim-1'], $register->row('wooDocumentAssessment', 'victim-1'));
		$created = array_values(array_filter(
			$register->all('wooDocumentAssessment'),
			static fn (array $row): bool => $row['id'] !== 'victim-1'
		));
		$this->assertCount(1, $created);
		$this->assertSame('case-1', $created[0]['caseRef']);
		$this->assertArrayNotHasKey('uuid', $created[0]);
		$this->assertArrayNotHasKey('@self', $created[0]);
	}//end testAForgedIdDoesNotTouchAnotherCasesAssessment()
}//end class
