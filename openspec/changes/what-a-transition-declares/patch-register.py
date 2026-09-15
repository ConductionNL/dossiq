#!/usr/bin/env python3
"""Add what a transition declares to the dossiq register.

Run from the clone root. Every edit asserts its anchor is unique first: a
short pattern is a substring of a longer one, and a silent multi-match is
exactly what a scripted edit gets wrong.
"""
import json
import sys

P = 'lib/Settings/dossiq_register.json'
s = open(P).read()


def swap(old, new, count=1):
    global s
    n = s.count(old)
    assert n == count, f'anchor matched {n} times, expected {count}: {old[:70]!r}'
    s = s.replace(old, new, count)


# ---------------------------------------------------------------- statusRecord
swap(
    '''                "slug": "statusRecord",
                "icon": "ProgressClock",
                "version": "1.1.0",''',
    '''                "slug": "statusRecord",
                "icon": "ProgressClock",
                "version": "1.2.0",''',
)

swap(
    '''                    "noWorkflowTemplate": {
                        "type": "boolean",
                        "default": false,
                        "description": "True when the transition was admin free-form on a caseType without an active workflowTemplate",
                        "title": "No Workflow Template"
                    }''',
    '''                    "noWorkflowTemplate": {
                        "type": "boolean",
                        "default": false,
                        "description": "True when the transition was admin free-form on a caseType without an active workflowTemplate",
                        "title": "No Workflow Template"
                    },
                    "actor": {
                        "type": "string",
                        "maxLength": 255,
                        "description": "Who made this move. Written by the engine on every transition, and read by the four-eyes rule: a transition may declare an earlier act whose performer may not make it, and the performer is read from this chain rather than inferred. A record written before this property existed falls back to the object owner, so the rule binds history that already exists rather than only cases started afterwards.",
                        "title": "Actor",
                        "facetable": true
                    }''',
)

# ------------------------------------------------------------ workflowTemplate
swap(
    '''                "slug": "workflowTemplate",
                "icon": "SitemapOutline",
                "version": "1.2.0",''',
    '''                "slug": "workflowTemplate",
                "icon": "SitemapOutline",
                "version": "1.3.0",''',
)

old_transitions = '''"description": "JSON-encoded array of StatusTransition objects. Each transition has: id (UUID), fromStatus (UUID), toStatus (UUID), label (string), guards (array of Guard), automaticActions (array of ActionRef), allowedRoles (array of UUID. Legacy, normalised on read to routingRule.or-set), routingRule (optional object same shape as workflowStep.routingRule). Guard types: checklist, requiredField, requiredDocument, roleGuard. Action types: sendEmail, createTask, createSubCase, webhook, setField, notify",'''
new_transitions = '''"description": "JSON-encoded array of StatusTransition objects. Each transition has: id (UUID), fromStatus (UUID), toStatus (UUID), label (string), guards (array of Guard), automaticActions (array of ActionRef), allowedRoles (array of UUID. Legacy, normalised on read to routingRule.or-set), routingRule (optional object same shape as workflowStep.routingRule). Guard types: checklist, requiredField, requiredDocument, roleGuard. Action types: sendEmail, createTask, createSubCase, webhook, setField, notify. V1.3 additive, three declarations a transition may carry, all optional and absent on every template shipped before them (see what-a-transition-declares). `requiresSettled`: an array of dependencies that must be settled before the transition is AVAILABLE. Each entry is {kind, obligationKind?, field?, value?, documentType?, label?} with kind one of obligationOpen, fieldPresent, fieldEquals or documentPresent. An unsettled dependency WITHHOLDS the transition rather than refusing it afterwards, and the reason is published where the transition would have been. The last three kinds are the same vocabulary statusType.derivedWhen uses, so an author who has written one has written the other. `explanation`: the sentence an administrator writes for the handler, rendered at the moment of choosing rather than after the move. An empty explanation renders nothing. `notPerformedBy`: {act, askInstead?}, naming the LABEL of an earlier transition whose performer may not make this one. A negative rule about an act, not a role: the same person legitimately approves other cases they did not prepare. The engine reads the performer from the case status record chain.",'''
swap(old_transitions, new_transitions)

open(P, 'w').write(s)
json.load(open(P))
print('register patched and still valid JSON')
