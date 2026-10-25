# The portal channel is named after the portal

## Why

The boards call the contact channel of the resident portal "Mijn
Zuiddrecht"; live called it "Webformulier". Decision (coordinator, 24 Oct):
label it with the portal's name (portaliq's portalName, in the page's config
state), as the outbound messages already do.

## What changes

- A manifest label written as `config:<key>|<fallback>` takes the app
  config's value, or the fallback when it is empty (withConfigLabels, applied
  where the lenses are). The case page's chips and contact-moments table use
  `config:portalName|Web form` for `webformulier`.
- Log contact names the option after the portal too; portalName() is one
  shared util, also used by the outbound-message formatter.
