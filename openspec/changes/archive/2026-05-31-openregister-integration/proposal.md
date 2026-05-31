# Proposal: openregister-integration

## Summary

Complete the OpenRegister integration foundation by registering all 27 schema types in the frontend store initialization, adding an OpenRegister availability check utility, and using a data-driven registration pattern instead of repetitive conditionals.

## Scope

### In Scope
- Refactor store.js to register all 27 schemas (was 13) using data-driven pattern
- Add `src/utils/openregisterCheck.js` for frontend availability/configuration checks
- OpenSpec change artifacts

### Out of Scope
- Backend changes (repair step, settings service already work correctly)
- Schema JSON updates (handled by register-i18n spec)
