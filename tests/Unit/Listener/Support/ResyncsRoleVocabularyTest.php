<?php

/**
 * ResyncsRoleVocabulary — re-sync the role vocabulary, warning on failure.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener\Support;

use OCA\Dossiq\Kernel\Contracts\CaseRoleVocabularyInterface;
use OCA\Dossiq\Listener\Support\ResyncsRoleVocabulary;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * A sync that works and one that fails.
 *
 * @covers \OCA\Dossiq\Listener\Support\ResyncsRoleVocabulary
 */
final class ResyncsRoleVocabularyTest extends TestCase {

	/**
	 * A class using the trait.
	 *
	 * @param CaseRoleVocabularyInterface $vocabulary The vocabulary.
	 * @param LoggerInterface             $logger     The logger.
	 *
	 * @return object
	 */
	private function subject(CaseRoleVocabularyInterface $vocabulary, LoggerInterface $logger): object {
		return new class($vocabulary, $logger) {
			use ResyncsRoleVocabulary {
				resyncVocabularyAfter as public;
			}

			/**
			 * Constructor.
			 *
			 * @param CaseRoleVocabularyInterface $vocabulary The vocabulary.
			 * @param LoggerInterface             $logger     The logger.
			 */
			public function __construct(
				private readonly CaseRoleVocabularyInterface $vocabulary,
				private readonly LoggerInterface $logger,
			) {
			}
		};
	}//end subject()

	/**
	 * A working sync logs nothing.
	 *
	 * @return void
	 */
	public function testSyncs(): void {
		$vocabulary = $this->createMock(CaseRoleVocabularyInterface::class);
		$vocabulary->expects(self::once())->method('sync');
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::never())->method('warning');

		$this->subject($vocabulary, $logger)->resyncVocabularyAfter('a role type change');
	}//end testSyncs()

	/**
	 * A failing sync is a warning naming the change.
	 *
	 * @return void
	 */
	public function testWarnsOnFailure(): void {
		$vocabulary = $this->createMock(CaseRoleVocabularyInterface::class);
		$vocabulary->method('sync')->willThrowException(new RuntimeException('down'));
		$logger = $this->createMock(LoggerInterface::class);
		$logger->expects(self::once())->method('warning')
			->with('Dossiq people: the case role vocabulary was not re-synced after a role type change: down');

		$this->subject($vocabulary, $logger)->resyncVocabularyAfter('a role type change');
	}//end testWarnsOnFailure()
}//end class
