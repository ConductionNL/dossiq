# Design: Procest Object Store

## Architecture
- **Pattern**: Pinia-based object store using `createObjectStore` from `@conduction/nextcloud-vue`
- **Purpose**: Data layer for Procest querying OpenRegister directly from frontend
- **Operations**: CRUD, search, pagination, file management, audit trails, relation resolution
- **File**: `src/store/modules/object.js`
