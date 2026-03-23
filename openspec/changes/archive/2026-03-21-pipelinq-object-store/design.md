# Design: Pipelinq Object Store

## Architecture
- **Pattern**: Pinia-based object store using `createObjectStore` from `@conduction/nextcloud-vue`
- **Purpose**: Data layer for Pipelinq querying OpenRegister
- **Operations**: CRUD, search, pagination, file management, audit trails, relation resolution
