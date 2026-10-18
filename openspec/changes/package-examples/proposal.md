# Example cases ship with their package

## Why

OpenRegister's example-set installer refuses the whole set when one lookup
finds nothing. The Zuiddrecht set looked up a role type that only the
on-request bezwaar-beroep package ships, and would have looked up the Jeugdwet
and tender case types the same way, so an instance without those packages
would get no example data from any app. The boards DqBezwaar, DqSociaalDomein
and DqAanbesteding still need a case of their kind to be measured on.

## What changes

- A package may declare an `examples` part: `examples/*.json` files in the
  seed file format, written with the package after its seed rows.
- A string in an example row that is exactly `{{date:N}}` or
  `{{datetime:N HH:MM}}` is filled relative to the install day, so a case in
  flight stays in flight.
- Example rows install only on an instance whose operator loaded example data
  in the setup wizard; a production install never gets a fake case.
- bezwaar-beroep (1.1.0), jeugdwet and aanbesteding ship one example case
  each, with the records the case page shows for it.
- The Zuiddrecht set looks up nothing that only an on-request package ships;
  a unit test holds it to that.
