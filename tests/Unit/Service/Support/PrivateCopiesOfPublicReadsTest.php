<?php

/**
 * Two private reads that copy a public one answer exactly what the public one answers.
 *
 * `DigitalPostDeliveredListener::stringFrom()` copies `EventGetters::stringFrom()`,
 * and `CaseDocumentDrafts::caseRow()` copies `CaseRecordLookup::caseRow()`.
 * These compare each copy with its original on the same inputs, so the copy
 * can be replaced by a call to the original.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/case-management/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Support;

use OCA\Dossiq\Listener\DigitalPostDeliveredListener;
use OCA\Dossiq\Listener\Support\EventGetters;
use OCA\Dossiq\Service\Access\CaseRecordLookup;
use OCA\Dossiq\Service\BerichtenboxService;
use OCA\Dossiq\Service\CaseTypeResolver;
use OCA\Dossiq\Service\CaseTypeStore;
use OCA\Dossiq\Service\Drafts\CaseDocumentDrafts;
use OCA\Dossiq\Service\Drafts\CaseDraftData;
use OCA\Dossiq\Service\SettingsService;
use OCA\Dossiq\Service\Zaakdossier\GeneratedDocumentFiler;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventDispatcher;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use ReflectionMethod;

class PrivateCopiesOfPublicReadsTest extends TestCase {

	/**
	 * The listener's copy answers what EventGetters answers, for every kind of getter.
	 *
	 * @return void
	 */
	public function testTheListenersStringFromIsEventGetters(): void {
		$event = new class extends Event {
			/**
			 * @return string A padded string.
			 */
			public function getMessageId(): string {
				return '  m-1  ';
			}

			/**
			 * @return int A number.
			 */
			public function getStatus(): int {
				return 3;
			}

			/**
			 * @return array<int, string> Not a scalar.
			 */
			public function getLastError(): array {
				return ['x'];
			}

			/**
			 * @return null Nothing.
			 */
			public function getNothing(): mixed {
				return null;
			}

			/**
			 * @return bool A flag.
			 */
			public function isSimulated(): bool {
				return true;
			}
		};

		$listener = new DigitalPostDeliveredListener(
			berichtenbox: $this->createMock(originalClassName: BerichtenboxService::class),
			logger: new NullLogger(),
		);
		$copy = new ReflectionMethod($listener, 'stringFrom');
		foreach (['getMessageId', 'getStatus', 'getLastError', 'getNothing', 'isSimulated', 'getMissing'] as $getter) {
			self::assertSame(
				(new EventGetters())->stringFrom(event: $event, method: $getter),
				$copy->invoke($listener, $event, $getter),
				'getter ' . $getter
			);
		}

		self::assertSame('m-1', $copy->invoke($listener, $event, 'getMessageId'));
	}//end testTheListenersStringFromIsEventGetters()

	/**
	 * The drafts' copy answers what CaseRecordLookup answers, with and without a store.
	 *
	 * @return void
	 */
	public function testTheDraftsCaseRowIsCaseRecordLookup(): void {
		$objects = new class {
			/**
			 * The case, when it is c-1.
			 *
			 * @param string $id       The uuid.
			 * @param mixed  $register The register.
			 * @param mixed  $schema   The schema.
			 *
			 * @return array<string, mixed>|null The case.
			 */
			public function find(string $id, mixed $register = null, mixed $schema = null): ?array {
				return ($id === 'c-1' ? ['id' => 'c-1', 'register' => $register, 'schema' => $schema] : null);
			}
		};

		foreach ([[true, 'dossiq', 'case'], [false, 'dossiq', 'case'], [true, '', 'case'], [true, 'dossiq', '']] as [$available, $register, $schema]) {
			$settings = $this->createMock(originalClassName: SettingsService::class);
			$settings->method('getObjectService')->willReturn($available === true ? $objects : null);
			$settings->method('getConfigValue')->willReturnCallback(
				static fn (string $key): string => ($key === 'register' ? $register : ($key === 'case_schema' ? $schema : ''))
			);
			$caseTypes = $this->createMock(originalClassName: CaseTypeStore::class);
			$resolver = $this->createMock(originalClassName: CaseTypeResolver::class);

			$lookup = new CaseRecordLookup(settingsService: $settings, caseTypes: $caseTypes, resolver: $resolver);
			$drafts = new CaseDocumentDrafts(
				settingsService: $settings,
				caseTypes: $caseTypes,
				resolver: $resolver,
				draftData: $this->createMock(originalClassName: CaseDraftData::class),
				events: $this->createMock(originalClassName: IEventDispatcher::class),
				filer: $this->createMock(originalClassName: GeneratedDocumentFiler::class),
			);
			$copy = new ReflectionMethod($drafts, 'caseRow');
			foreach (['c-1', 'c-2', ''] as $caseId) {
				self::assertSame($lookup->caseRow(caseId: $caseId), $copy->invoke($drafts, $caseId), 'case ' . $caseId);
			}
		}

		// The comparison above is not between two empty answers: a configured store reads the case.
		$lookup = new CaseRecordLookup(
			settingsService: $this->settings(objects: $objects),
			caseTypes: $this->createMock(originalClassName: CaseTypeStore::class),
			resolver: $this->createMock(originalClassName: CaseTypeResolver::class),
		);
		self::assertSame(['id' => 'c-1', 'register' => 'dossiq', 'schema' => 'case'], $lookup->caseRow(caseId: 'c-1'));
	}//end testTheDraftsCaseRowIsCaseRecordLookup()

	/**
	 * A configured settings service.
	 *
	 * @param object $objects The object service.
	 *
	 * @return SettingsService The settings.
	 */
	private function settings(object $objects): SettingsService {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($objects);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key): string => ($key === 'register' ? 'dossiq' : ($key === 'case_schema' ? 'case' : ''))
		);

		return $settings;
	}//end settings()
}//end class
