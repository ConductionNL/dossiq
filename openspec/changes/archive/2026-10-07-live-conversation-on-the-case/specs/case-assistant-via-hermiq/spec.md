## ADDED Requirements

### Requirement: Dictation is hermiq's, and the declared tools assume no typed input (REQ-LIVE-05)

dossiq SHALL NOT ship a voice input path of its own, per decision D13
which places the assistant in hermiq. Every tool dossiq declares to the
assistant SHALL be callable from a plain instruction with no assumption
that it was typed, so a dictated instruction reaches it exactly as a typed
one does. A structural test SHALL fail when a declared tool names a
keyboard affordance or a typed format in its contract.

#### Scenario: a dictated instruction reaches a declared tool
@e2e exclude dossiq exposes no MCP route of its own: the tool provider is consumed in-process by hermiq through IMcpToolProvider, so there is no endpoint a browser can call and nothing to dictate into on a dossiq instance. What dossiq owes is asserted instead by tests/Unit/Architecture/DeclaredToolAssumesNoTypingTest.php::testNoDeclaredToolAssumesItWasTyped, which reads every declared contract and fails naming a tool that assumes a keyboard.

- **GIVEN** an instance with hermiq present
- **WHEN** a person dictates an instruction that matches a declared dossiq tool
- **THEN** the tool SHALL be called with the same arguments a typed instruction would produce

#### Scenario: dossiq ships no recogniser

- **GIVEN** the dossiq tree
- **WHEN** it is read for speech recognition code
- **THEN** none SHALL be found

#### Scenario: a tool that assumes typing fails the build

- **GIVEN** a declared tool whose contract names a keyboard affordance
- **WHEN** the structural test runs
- **THEN** it SHALL fail, naming the tool
