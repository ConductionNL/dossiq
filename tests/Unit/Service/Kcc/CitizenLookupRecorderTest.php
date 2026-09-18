<?php

/**
 * Every citizen lookup leaves a row, and a failed row leaves the lookup alone.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Kcc
 *
 * @author    Conduction Development Team <info@conduction.nl>
 * @copyright 2026 Conduction B.V.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * @link https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 * SPDX-License-Identifier: EUPL-1.2
 *
 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Kcc;

use OCA\Dossiq\Service\Kcc\CitizenLookupRecorder;
use OCA\Dossiq\Service\SettingsService;
use OCP\IRequest;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The audit sink for a citizen lookup.
 *
 * @covers \OCA\Dossiq\Service\Kcc\CitizenLookupRecorder
 */
class CitizenLookupRecorderTest extends TestCase {

	/**
	 * The doubled object service.
	 *
	 * @var object
	 */
	private object $objects;

	/**
	 * Stand up an OpenRegister that accepts writes and remembers them.
	 *
	 * @return void
	 */
	protected function setUp(): void {
		$this->objects = new class {
			/**
			 * Every row written.
			 *
			 * @var array<int, array<string, mixed>>
			 */
			public array $rows = [];

			/**
			 * Whether the sink refuses.
			 *
			 * @var bool
			 */
			public bool $refuses = false;

			/**
			 * Record a write.
			 *
			 * @param array<string, mixed> $object   The row.
			 * @param string               $register The register.
			 * @param string               $schema   The schema.
			 * @param string|null          $uuid     Unused.
			 *
			 * @return array<string, mixed> The row.
			 */
			public function saveObject(array $object, string $register, string $schema, ?string $uuid = null): array {
				if ($this->refuses === true) {
					throw new \RuntimeException('OpenRegister refused the audit row');
				}

				$this->rows[] = ['object' => $object, 'schema' => $schema];
				return $object;
			}
		};
	}//end setUp()

	/**
	 * The recorder on the doubled OpenRegister.
	 *
	 * @param bool $available Whether OpenRegister answers at all.
	 *
	 * @return CitizenLookupRecorder The recorder.
	 */
	private function recorder(bool $available = true): CitizenLookupRecorder {
		$settings = $this->createMock(originalClassName: SettingsService::class);
		$settings->method('getObjectService')->willReturn($available === true ? $this->objects : null);
		$settings->method('getConfigValue')->willReturnCallback(
			static fn (string $key, string $default = ''): string => ($key === 'register' ? 'dossiq' : $default)
		);

		$request = $this->createMock(originalClassName: IRequest::class);
		$request->method('getRemoteAddress')->willReturn('10.0.0.7');

		return new CitizenLookupRecorder(
			settingsService: $settings,
			request: $request,
			logger: $this->createMock(originalClassName: LoggerInterface::class),
		);
	}//end recorder()

	/**
	 * A permitted lookup names the account, the citizen and what was revealed.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	public function testAPermittedLookupIsRecorded(): void {
		$written = $this->recorder()->record(
			employeeId: 'sanne',
			subjectId: '999990032',
			allowed: true,
			fields: ['summary', 'transcript'],
			ground: 'kcc-rol',
		);

		$this->assertTrue($written);
		$this->assertCount(1, $this->objects->rows);

		$row = $this->objects->rows[0]['object'];
		$this->assertSame(CitizenLookupRecorder::SCHEMA, $this->objects->rows[0]['schema']);
		$this->assertSame('sanne', $row['employeeId']);
		$this->assertSame('999990032', $row['subjectId']);
		$this->assertSame('read', $row['action']);
		$this->assertSame('10.0.0.7', $row['ipAddress']);
		$this->assertSame(['summary', 'transcript'], $row['geraadpleegdeVelden']);
		$this->assertSame('kcc-rol', $row['authorisationGround']);
		$this->assertSame(CitizenLookupRecorder::RESULT_ALLOWED, $row['result']);
		// `caseId` is absent on purpose: a lookup is about a citizen, and the
		// schema stopped requiring it at 1.1.0 for exactly this row.
		$this->assertArrayNotHasKey('caseId', $row);
	}//end testAPermittedLookupIsRecorded()

	/**
	 * A refusal is recorded too, and that is the half that catches enumeration.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	public function testARefusedLookupIsRecordedAsRefused(): void {
		$this->recorder()->record(
			employeeId: 'mallory',
			subjectId: '999990032',
			allowed: false,
			fields: [],
			ground: 'geen kcc-rol',
		);

		$row = $this->objects->rows[0]['object'];
		$this->assertSame('mallory', $row['employeeId']);
		$this->assertSame(CitizenLookupRecorder::RESULT_REFUSED, $row['result']);
		$this->assertSame([], $row['geraadpleegdeVelden']);
	}//end testARefusedLookupIsRecordedAsRefused()

	/**
	 * A sink that throws does not reach the caller.
	 *
	 * The fail-open the class comment argues for. It is right here because the
	 * act has already been authorised, so the record is evidence and not a
	 * gate, and an audit outage must not become a contact-centre outage.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	public function testAFailedWriteDoesNotReachTheCaller(): void {
		$this->objects->refuses = true;

		$written = $this->recorder()->record(employeeId: 'sanne', subjectId: '999990032', allowed: true);

		$this->assertFalse($written, 'the recorder must report the failure rather than throw it');
		$this->assertSame([], $this->objects->rows);
	}//end testAFailedWriteDoesNotReachTheCaller()

	/**
	 * An absent OpenRegister is reported, not thrown.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	public function testAnAbsentOpenRegisterIsReported(): void {
		$this->assertFalse(
			$this->recorder(available: false)->record(
				employeeId: 'sanne',
				subjectId: '999990032',
				allowed: true
			)
		);
	}//end testAnAbsentOpenRegisterIsReported()

	/**
	 * The moment is an ISO 8601 instant the schema's `date-time` accepts.
	 *
	 * A format OpenRegister refuses would be swallowed by the catch above, so
	 * every row would vanish and nothing would say why.
	 *
	 * @return void
	 *
	 * @spec openspec/changes/citizen-lookup-is-guarded-and-recorded/specs/security-hardening/spec.md#requirement-every-citizen-lookup-is-recorded-refusals-included-req-sec-cl-3
	 */
	public function testTheMomentIsAnIsoInstant(): void {
		$this->recorder()->record(employeeId: 'sanne', subjectId: '999990032', allowed: true);

		$moment = $this->objects->rows[0]['object']['moment'];
		$this->assertInstanceOf(
			\DateTimeImmutable::class,
			\DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $moment)
				?: throw new RuntimeException('not an ATOM instant: ' . $moment)
		);
	}//end testTheMomentIsAnIsoInstant()
}//end class
