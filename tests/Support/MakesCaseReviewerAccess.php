<?php

/**
 * A real CaseReviewerAccess over a test's own settings.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Support
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Support;

use OCA\Dossiq\Service\Access\CaseReviewerAccess;
use OCA\Dossiq\Service\Access\ObjectReadGrants;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\SettingsService;
use OCP\IGroupManager;
use Psr\Log\LoggerInterface;

/**
 * For a test of a writer that keeps a case's private records.
 *
 * The read a rule makes (`recordsOf()`) is the real one, over the object
 * service the test's settings answer, so a record hidden from the caller is
 * still found. Only the reconcile after a create is recorded instead of run:
 * the test says which case was reconciled, and CaseReviewerAccessTest says
 * what a reconcile does.
 */
trait MakesCaseReviewerAccess {

	/**
	 * Every reconcile a writer asked for after it created a record.
	 *
	 * @var list<array{case: string, created: int}>
	 */
	protected array $reconciledAfterCreate = [];

	/**
	 * The access service over these settings.
	 *
	 * @param SettingsService $settings The test's settings.
	 *
	 * @return CaseReviewerAccess
	 */
	protected function caseReviewerAccessOver(SettingsService $settings): CaseReviewerAccess {
		$access = $this->getMockBuilder(CaseReviewerAccess::class)
			->setConstructorArgs(
				[
					$settings,
					new CaseTypeStore($settings),
					$this->createMock(CaseTypeResolver::class),
					$this->createMock(ObjectReadGrants::class),
					$this->createMock(IGroupManager::class),
					$this->createMock(LoggerInterface::class),
				]
			)
			->onlyMethods(['reconcileAfterCreate'])
			->getMock();
		$access->method('reconcileAfterCreate')->willReturnCallback(
			function (string $caseId, int $created): array {
				$this->reconciledAfterCreate[] = ['case' => $caseId, 'created' => $created];
				return ['status' => 'reconciled', 'granted' => 0, 'revoked' => 0, 'refused' => 0];
			}
		);

		return $access;
	}//end caseReviewerAccessOver()
}//end trait
