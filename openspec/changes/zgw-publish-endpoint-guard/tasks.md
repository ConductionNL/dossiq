# Tasks: zgw-publish-endpoint-guard

## 1. Scope and publish guard (decision 245)

- [x] 1.1 (`lib/Controller/ZtcPublishController.php`) the three publish routes require `catalogi.schrijven` after the JWT check (REQ-ZBR-024).
  - `tests/Unit/Controller/ZtcPublishControllerContractTest.php` "all three publish routes require the catalogi write scope".
- [x] 1.2 (`lib/Service/ZgwBusinessRulesService.php`) `guardPublishEndpoint()` runs the CT-02b guard on the stored type and answers `notVerified()` when its read fails; the controller answers its refusal and saves nothing.
  - `tests/Unit/Service/ZgwBusinessRulesServicePublishEndpointTest.php`, and the controller test "a zaaktype the publish guard refuses is not saved" with its control "a zaaktype the publish guard passes is published".

## 2. Live

- [ ] 2.1 (live pass, decision 139) Publish a complete zaaktype and an incomplete one over the ZGW API with a client that has `catalogi.schrijven`, and one with a client that has not.
