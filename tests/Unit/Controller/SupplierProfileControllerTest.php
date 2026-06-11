<?php

/**
 * SupplierProfileController Unit Tests
 *
 * Tests the operator-side write controller for the leverancier-zaakportaal
 * master-data-mutations chain member.
 *
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @copyright 2026 Conduction B.V.
 * SPDX-License-Identifier: EUPL-1.2
 * SPDX-FileCopyrightText: 2026 Conduction B.V. <info@conduction.nl>
 *
 * @spec openspec/changes/leverancier-zaakportaal-12-master-data-mutations/tasks.md
 */

declare(strict_types=1);

namespace OCA\Procest\Tests\Unit\Controller;

use OCA\Procest\Controller\SupplierProfileController;
use OCA\Procest\Service\SupplierMasterDataMutationService;
use OCA\Procest\Service\SupplierScopeService;
use OCP\AppFramework\Http;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\TestCase;

/**
 * @covers \OCA\Procest\Controller\SupplierProfileController
 */
class SupplierProfileControllerTest extends TestCase
{
    /**
     * Build a controller bound to mocked services + a mocked request.
     *
     * @param array<string, mixed> $params Request param map.
     * @param SupplierMasterDataMutationService|null $mutation Optional override.
     *
     * @return SupplierProfileController
     */
    private function makeController(
        array $params,
        ?SupplierMasterDataMutationService $mutation=null,
    ): SupplierProfileController {
        $request = $this->createMock(IRequest::class);
        $request->method('getParam')->willReturnCallback(
            function (string $key, $default=null) use ($params) {
                return $params[$key] ?? ($default ?? '');
            }
        );

        $user = $this->createMock(IUser::class);
        $user->method('getUID')->willReturn('admin');
        $session = $this->createMock(IUserSession::class);
        $session->method('getUser')->willReturn($user);

        return new SupplierProfileController(
            $request,
            $mutation ?? $this->createMock(SupplierMasterDataMutationService::class),
            $this->createMock(SupplierScopeService::class),
            $session,
        );
    }//end makeController()

    public function testUpdateAddressRequiresSupplierRef(): void
    {
        $c = $this->makeController([]);
        $r = $c->updateAddress();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
    }//end testUpdateAddressRequiresSupplierRef()

    public function testUpdateAddressRequiresAddressPayload(): void
    {
        $c = $this->makeController(['supplierRef' => 's1']);
        $r = $c->updateAddress();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
    }//end testUpdateAddressRequiresAddressPayload()

    public function testUpdateAddressSucceeds(): void
    {
        $mut = $this->createMock(SupplierMasterDataMutationService::class);
        $mut->expects($this->once())->method('updateAddress')
            ->with('s1', ['street' => 'Damstraat 1'], 'admin')
            ->willReturn(['id' => 's1']);
        $c = $this->makeController(['supplierRef' => 's1', 'address' => ['street' => 'Damstraat 1']], mutation: $mut);
        $r = $c->updateAddress();
        $this->assertSame(Http::STATUS_OK, $r->getStatus());
        $this->assertTrue($r->getData()['ok']);
    }//end testUpdateAddressSucceeds()

    public function testUpdateAddressReturns500OnNullRow(): void
    {
        $mut = $this->createMock(SupplierMasterDataMutationService::class);
        $mut->method('updateAddress')->willReturn(null);
        $c = $this->makeController(['supplierRef' => 's1', 'address' => ['street' => 'X']], mutation: $mut);
        $r = $c->updateAddress();
        $this->assertSame(Http::STATUS_INTERNAL_SERVER_ERROR, $r->getStatus());
    }//end testUpdateAddressReturns500OnNullRow()

    public function testUpdateContactRequiresContactPerson(): void
    {
        $c = $this->makeController(['supplierRef' => 's1']);
        $r = $c->updateContact();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
    }//end testUpdateContactRequiresContactPerson()

    public function testUpdateContactSucceeds(): void
    {
        $mut = $this->createMock(SupplierMasterDataMutationService::class);
        $mut->expects($this->once())->method('updateContactPerson')
            ->with('s1', 'Jane Doe', 'admin')
            ->willReturn(['id' => 's1']);
        $c = $this->makeController(['supplierRef' => 's1', 'contactPerson' => 'Jane Doe'], mutation: $mut);
        $r = $c->updateContact();
        $this->assertSame(Http::STATUS_OK, $r->getStatus());
    }//end testUpdateContactSucceeds()

    public function testRequestIbanChangeRequiresIban(): void
    {
        $c = $this->makeController(['supplierRef' => 's1']);
        $r = $c->requestIbanChange();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
    }//end testRequestIbanChangeRequiresIban()

    public function testRequestIbanChangeReturns400OnBadIban(): void
    {
        $mut = $this->createMock(SupplierMasterDataMutationService::class);
        $mut->method('requestIbanChange')->willReturn(['ok' => false, 'reason' => 'Invalid IBAN']);
        $c = $this->makeController(['supplierRef' => 's1', 'iban' => 'BADIBAN'], mutation: $mut);
        $r = $c->requestIbanChange();
        $this->assertSame(Http::STATUS_BAD_REQUEST, $r->getStatus());
        $this->assertSame('Invalid IBAN', $r->getData()['error']);
    }//end testRequestIbanChangeReturns400OnBadIban()

    public function testRequestIbanChangeSucceedsAndReturnsCaseRef(): void
    {
        $mut = $this->createMock(SupplierMasterDataMutationService::class);
        $mut->method('requestIbanChange')->willReturn(['ok' => true, 'caseRef' => 'case-99']);
        $c = $this->makeController(['supplierRef' => 's1', 'iban' => 'NL91ABNA0417164300'], mutation: $mut);
        $r = $c->requestIbanChange();
        $this->assertSame(Http::STATUS_OK, $r->getStatus());
        $data = $r->getData();
        $this->assertTrue($data['ok']);
        $this->assertSame('case-99', $data['caseRef']);
        $this->assertSame('Wijziging ingediend', $data['message']);
    }//end testRequestIbanChangeSucceedsAndReturnsCaseRef()

    public function testSubmitAccreditationDelegates(): void
    {
        $mut = $this->createMock(SupplierMasterDataMutationService::class);
        $mut->expects($this->once())->method('submitForVerification')
            ->with('s1', 'iso27001', ['ref-a', 'ref-b'], 'admin')
            ->willReturn(['ok' => true, 'caseRef' => 'case-12']);
        $c = $this->makeController([
            'supplierRef' => 's1',
            'dataType'    => 'iso27001',
            'attachments' => ['ref-a', 'ref-b'],
        ], mutation: $mut);
        $r = $c->submitAccreditation();
        $this->assertSame(Http::STATUS_OK, $r->getStatus());
    }//end testSubmitAccreditationDelegates()
}//end class
