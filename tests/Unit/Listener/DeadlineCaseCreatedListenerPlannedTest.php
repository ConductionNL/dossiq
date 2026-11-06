<?php

/**
 * DeadlineCaseCreatedListener hands the planned definition to the other clocks.
 *
 * The resolution is real here: only the store of definitions is doubled. What
 * is asserted is that a case of a type whose only definition is a service norm
 * reaches bindForCase() WITH that norm, on the path where no statutory term
 * starts, and that the statutory start never sees the norm.
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/specs/termijn-binding/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Listener;

use DateTimeImmutable;
use OCA\Dossiq\Exception\NoTermijnDefinitieException;
use OCA\Dossiq\Listener\DeadlineCaseCreatedListener;
use OCA\Dossiq\Service\CaseTermsService;
use OCA\Dossiq\Service\CaseTypeSlugResolver;
use OCA\Dossiq\Service\ObjectSchemaSlugResolver;
use OCA\Dossiq\Service\Term\TermResolution;
use OCA\Dossiq\Service\TermijnService;
use OCA\Dossiq\Service\TermKind;
use OCA\OpenRegister\Db\ObjectEntity;
use OCA\OpenRegister\Event\ObjectCreatedEvent;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * @covers \OCA\Dossiq\Listener\DeadlineCaseCreatedListener
 * @uses   \OCA\Dossiq\Service\Term\TermResolution
 * @uses   \OCA\Dossiq\Service\TermKind
 * @uses   \OCA\Dossiq\Service\Intake\ReceiptMoment
 */
class DeadlineCaseCreatedListenerPlannedTest extends TestCase {

	/**
	 * The resolution the statutory start was handed.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private array $statutoryResolutions = [];

	/**
	 * The planned definition the other clocks were handed.
	 *
	 * @var array<int, array<string, mixed>|null>
	 */
	private array $plannedDefinitions = [];

	/**
	 * A listener over a store holding these definitions.
	 *
	 * @param array<int, array<string, mixed>> $rows The case type's definitions.
	 *
	 * @return DeadlineCaseCreatedListener The listener.
	 */
	private function listener(array $rows): DeadlineCaseCreatedListener {
		$terms = $this->createMock(TermijnService::class);
		$terms->method('definitionsFor')->willReturn($rows);
		$terms->method('createTermijnInstance')->willReturnCallback(
			function (string $caseId, string $caseType, ?DateTimeImmutable $start = null, ?array $resolution = null): array {
				$this->statutoryResolutions[] = $resolution;
				if ($resolution === null) {
					throw new NoTermijnDefinitieException(message: 'none');
				}

				return ['id' => 't1'];
			}
		);

		$caseTerms = $this->createMock(CaseTermsService::class);
		$caseTerms->method('bindForCase')->willReturnCallback(
			function (
				string $caseId,
				string $caseTypeId,
				?DateTimeImmutable $start = null,
				string $plannedStart = '',
				?array $plannedDefinition = null,
			): array {
				$this->plannedDefinitions[] = $plannedDefinition;
				return [];
			}
		);

		$schemaSlugs = $this->createMock(ObjectSchemaSlugResolver::class);
		$schemaSlugs->method('resolveFromPayload')->willReturn('case');
		$caseTypeSlugs = $this->createMock(CaseTypeSlugResolver::class);
		$caseTypeSlugs->method('toSlug')->willReturn('building-permit');

		return new DeadlineCaseCreatedListener(
			termService: $terms,
			slugResolver: $schemaSlugs,
			caseTypeSlugs: $caseTypeSlugs,
			logger: new NullLogger(),
			caseTerms: $caseTerms,
			resolution: new TermResolution(terms: $terms),
		);
	}//end listener()

	/**
	 * A created case.
	 *
	 * @return ObjectCreatedEvent The event.
	 */
	private static function created(): ObjectCreatedEvent {
		$entity = new ObjectEntity();
		$entity->setUuid('case-1');
		$entity->setSchema('case');
		$entity->setObject(['caseType' => 'ct-uuid']);

		return new ObjectCreatedEvent($entity);
	}//end created()

	/**
	 * Only a service norm: no statutory resolution, and the norm reaches the other clocks.
	 *
	 * @return void
	 */
	public function testAServiceNormReachesThePlannedClockAndNotTheStatutoryOne(): void {
		$norm = ['id' => 'td-building-permit', 'kind' => TermKind::PLANNED, 'standardDurationDays' => 56];

		$this->listener(rows: [$norm])->handle(self::created());

		self::assertSame([null], $this->statutoryResolutions, 'The statutory start is handed no resolution.');
		self::assertSame([$norm], $this->plannedDefinitions, 'The other clocks get the norm.');
	}//end testAServiceNormReachesThePlannedClockAndNotTheStatutoryOne()

	/**
	 * A statutory term only: it starts, and no planned definition is handed on.
	 *
	 * @return void
	 */
	public function testAStatutoryTermStartsAndNoNormIsHandedOn(): void {
		$statutory = ['id' => 'td-klacht-behandeling', 'kind' => TermKind::STATUTORY, 'standardDurationDays' => 42];

		$this->listener(rows: [$statutory])->handle(self::created());

		self::assertSame('td-klacht-behandeling', $this->statutoryResolutions[0]['definition']['id'] ?? null);
		self::assertSame([null], $this->plannedDefinitions);
	}//end testAStatutoryTermStartsAndNoNormIsHandedOn()
}//end class
