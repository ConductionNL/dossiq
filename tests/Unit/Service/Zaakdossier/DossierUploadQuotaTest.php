<?php

/**
 * A document upload consumes the tenant's storage quota in megabytes.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Zaakdossier
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/tenant-quotas-enforced-and-billing-shown/specs/tenant-quotas/spec.md#requirement-the-quota-card-and-the-refusal-read-the-same-limits-req-tq-enf-02
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Zaakdossier;

use OCA\Dossiq\Service\CaseAccessGuard;
use OCA\Dossiq\Service\QuotaGate;
use OCA\Dossiq\Service\QuotaLimitLabel;
use OCA\Dossiq\Service\Zaakdossier\DossierUploadHandler;
use OCA\Dossiq\Service\ZaakdossierService;
use OCA\Dossiq\Tests\Unit\Service\Transitions\SourceL10n;
use PHPUnit\Framework\TestCase;

/**
 * REQ-TQ-ENF-02, the storage half.
 *
 * @covers \OCA\Dossiq\Service\Zaakdossier\DossierUploadHandler
 * @covers \OCA\Dossiq\Service\QuotaLimitLabel
 */
class DossierUploadQuotaTest extends TestCase {

	/** @var list<string> */
	private array $files = [];

	protected function tearDown(): void {
		foreach ($this->files as $file) {
			@unlink($file);
		}
	}

	/**
	 * A temporary upload of the given size.
	 *
	 * @param int $bytes The size.
	 *
	 * @return array<string, mixed> The uploaded-file entry.
	 */
	private function upload(int $bytes): array {
		$path = (string)tempnam(sys_get_temp_dir(), 'dq-upload');
		file_put_contents($path, str_repeat('a', $bytes));
		$this->files[] = $path;

		return ['name' => 'brief.pdf', 'tmp_name' => $path, 'type' => 'application/pdf'];
	}

	public function testAStoredUploadCountsItsSizeRoundedUpToWholeMegabytes(): void {
		$files = $this->createMock(ZaakdossierService::class);
		$files->expects($this->once())->method('uploadDocument')->willReturn(['id' => 'doc-1']);
		$gate = $this->createMock(QuotaGate::class);
		$gate->expects($this->once())->method('refusal')->with('storage_mb', 2)->willReturn(null);
		$gate->expects($this->once())->method('record')->with('storage_mb', 2);

		$result = (new DossierUploadHandler($files, $this->createMock(CaseAccessGuard::class), $gate))
			->uploadOne(caseId: 'case-1', file: $this->upload(bytes: 1048577), metadata: []);

		$this->assertTrue($result['success']);
	}

	public function testAnUploadPastTheStorageLimitIsRefusedAndNothingIsStored(): void {
		$files = $this->createMock(ZaakdossierService::class);
		$files->expects($this->never())->method('uploadDocument');
		$gate = $this->createMock(QuotaGate::class);
		$gate->method('refusal')->willReturn(
			['message' => 'Your organisation reached its limit of 10 MB of storage on the basic plan.', 'quotaType' => 'storage_mb', 'limit' => 10, 'tier' => 'basic']
		);
		$gate->expects($this->never())->method('record');

		$result = (new DossierUploadHandler($files, $this->createMock(CaseAccessGuard::class), $gate))
			->uploadOne(caseId: 'case-1', file: $this->upload(bytes: 2 * 1048576), metadata: []);

		$this->assertFalse($result['success']);
		$this->assertStringContainsString('10 MB', $result['error']);
	}

	public function testAFailedStoreIsNotCounted(): void {
		$files = $this->createMock(ZaakdossierService::class);
		$files->method('uploadDocument')->willThrowException(new \RuntimeException('disk full'));
		$gate = $this->createMock(QuotaGate::class);
		$gate->method('refusal')->willReturn(null);
		$gate->expects($this->never())->method('record');

		$result = (new DossierUploadHandler($files, $this->createMock(CaseAccessGuard::class), $gate))
			->uploadOne(caseId: 'case-1', file: $this->upload(bytes: 10), metadata: []);

		$this->assertFalse($result['success']);
	}

	public function testTheStorageLimitIsWordedInMegabytes(): void {
		$this->assertSame('10240 MB of storage', (new QuotaLimitLabel())->label(l: new SourceL10n(), quotaType: 'storage_mb', limit: 10240));
	}
}
