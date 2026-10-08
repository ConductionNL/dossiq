<?php

/**
 * HearingMinutesRecorder hands back no audit array for a caller to save.
 *
 * @category Tests
 * @package  OCA\Dossiq\Tests\Unit\Service\Bezwaar
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
 * @spec openspec/changes/bezwaar-audit-onto-openregister-trail/specs/bezwaar-awb-audit-trail/spec.md
 */

declare(strict_types=1);

namespace OCA\Dossiq\Tests\Unit\Service\Bezwaar;

use OCA\Dossiq\Service\Bezwaar\BezwaarAuditTrail;
use OCA\Dossiq\Service\Bezwaar\HearingMinutesRecorder;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionNamedType;
use RuntimeException;

/**
 * @covers \OCA\Dossiq\Service\Bezwaar\HearingMinutesRecorder
 */
class HearingMinutesRecorderTest extends TestCase {
	/**
	 * The recorder keeps BezwaarAuditTrail and returns no audit array (REQ-BAT-001).
	 *
	 * @return void
	 */
	public function testTheRecorderReturnsNoAuditArray(): void {
		$class = new ReflectionClass(HearingMinutesRecorder::class);

		$constructor = array_map(static fn ($p): string => (string) $p->getType(), $class->getConstructor()?->getParameters() ?? []);
		$this->assertContains(BezwaarAuditTrail::class, $constructor);
		$this->assertFalse($class->hasMethod('appendLateCorrectionAudit'), 'nothing may append to an embedded trail');

		$guard = $class->getMethod('guardRecordingConsent');
		$this->assertInstanceOf(ReflectionNamedType::class, $guard->getReturnType());
		$this->assertSame('void', $guard->getReturnType()->getName(), 'the consent guard must not hand back a trail to save');
		foreach ($guard->getParameters() as $parameter) {
			$this->assertNotSame('audit', $parameter->getName());
		}

		$recorder = new HearingMinutesRecorder(auditTrail: $this->createMock(BezwaarAuditTrail::class));
		$this->assertSame(
			['invitee' => 'person-1', 'present' => true, 'correctionReason' => 'Te laat aangemeld'],
			$recorder->lateCorrectionPayload(entry: ['invitee' => 'person-1', 'present' => true, 'correctionReason' => 'Te laat aangemeld'])
		);
		$this->expectException(RuntimeException::class);
		$recorder->lateCorrectionPayload(entry: ['invitee' => 'person-1', 'present' => false]);
	}
}
