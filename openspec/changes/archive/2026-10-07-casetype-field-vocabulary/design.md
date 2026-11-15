# Design: casetype-field-vocabulary

## D-1. The enum and the map move together, or neither moves

`propertyDefinition.propertyType` is the vocabulary. The
`x-openregister-extends-form.map` is what reaches the case schema. A value
added to one and not the other is a field an administrator can choose and
the case cannot hold.

That is why this is one change and not two. Ten rows move on one file
because the file is where both halves live.

## D-2. The vocabulary is the engine's, not a new one

`PropertyValidatorHandler.php:44-63` already accepts twenty types and six
constraint keys. Every value this change adds comes off that list. Nothing
here invents a type name, and nothing here validates a value: the engine
does, and it already does.

The alternative is the one xxllnc took, and the depth study measured its
cost: 39 attribute types, nine of them BAG lookups, because the registry
went into the type system. Adding a type per need is how a vocabulary
reaches 39.

## D-3. Format carries the variants, not the type

Single line against multi line, markdown against html, date against
datetime: three pairs that are one type and a format each. Putting them in
`propertyType` would double the enum and break every consumer that
switches on the type.

So `propertyType` stays small and `format` carries the variant, which is
what JSON Schema does and what the engine already reads.

## D-4. Constraints are declared, never enforced here

`pattern`, `minimum` and `maximum` are keys on the definition that the map
forwards. dossiq writes no validator. B7 reads `partial` today because
`maxLength` is the only constraint that reaches the schema, not because
the engine cannot check the others.

## D-5. The computed key forwards the JSON AST and not Twig

D3 chose. `x-openregister-calculations` is auditable, diffable and safe by
construction; `computed` is sandboxed Twig, more expressive, and a sandbox
to keep safe forever. A functional administrator writes the expression and
an auditor reads it a year later, so the one that diffs wins.

Forwarding one and not the other is a decision that should be visible in
the file, so the map forwards exactly one key and the other stays
unreachable on purpose.

## D-6. `enumValues` gets an input, because choosing `enum` today is a trap

The map already forwards `enum` to `enumValues`. The tab never offered a
way to fill it. An administrator picks `enum`, saves, and gets a dropdown
with nothing in it and no error.

This is a defect on a shipped path, it is inside a file this change is
already editing, and it is under fifteen minutes. It is fixed here rather
than reported.

## D-7. An unknown stored type is kept, never rewritten

A case type authored on a wider vocabulary and read on an older instance
must not have its properties silently retyped to `string`. The unknown
value is kept, the field renders read-only with its stored type named, and
the administrator is told which instance understands it.

Silently coercing a `geo` field to a string loses a coordinate nobody will
notice is gone.

## D-8. The source key is declared here and resolved elsewhere

`x-openregister-property-source` is openregister's key, resolved by
integriq. This change forwards it so a case type can declare it. dossiq
resolves nothing and ships no adapter, per D2.

## D-9. The help text is the key the renderer already has a role for

The proposal asked for a `helpText` key. The case form is rendered by
`propertiesFromDefinitions` in `@conduction/nextcloud-vue`, and it reads
exactly two text roles off a definition: `description`, which becomes the
sentence under the input, and `definition`, which it falls back to when the
first is empty. Three dossiq fields into two roles does not go.

So `description` is the help text, relabelled as help in the tab and
described as help in the schema, and `definition` keeps the field's own
documentation. B10's clause is that neither field is labelled as help, and
that is fixed by labelling one, not by adding a third.

## D-10. `itemsType` is `items`, because the vocabulary's key takes a shape

The vocabulary's `items` key carries a sub-schema, `{"type": "string"}`, not
a type name. A definition storing the name alone would forward `items:
"string"`, which is malformed, and nothing on the path would say so. The
tab asks for the entry type and stores the shape.

## D-11. The map reads role to field, and the two definitions of it disagree

dossiq's `x-openregister-extends-form.map` is written as role to definition
field: `"title": "name"` means "the title comes from the definition's name".
That is the direction the shipped consumer reads, `propertiesFromDefinitions`
in `@conduction/nextcloud-vue`, which iterates `[target, source]`.

OpenRegister's new `ExtendingFormDeclaration` reads it the other way, as form
field to vocabulary key, and validates the right-hand side. Read that way,
dossiq's map forwards `name`, `propertyType`, `enumValues`, `isRequired` and
`defaultValue`, five keys the vocabulary does not hold, and every one of them
would be refused by name.

The map is not flipped here. The renderer is the half that runs, and flipping
would break every case type on every instance to satisfy a validator that has
not merged. It is reported instead, and dossiq's contract test checks the side
the renderer reads.

## D-12. A key the platform has not published is carried, not forwarded

`x-openregister-property-source` is the name integriq's
`registry-backed-field-source` asked OpenRegister for, and OpenRegister has
not shipped it. A form may only forward a key the vocabulary holds, so
forwarding it now would put dossiq in exactly the position this change exists
to end: a key in our file that nothing on the other side defines.

The definition carries the administrator's answer regardless, and the contract
test fails the day the vocabulary does hold the key. That failure is the
reminder to move it into the map, which is cheaper than a note nobody reads.
