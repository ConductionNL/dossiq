# Case Management Design

## Architecture
All case data stored as OpenRegister objects. Frontend uses shared object store (`createObjectStore`) with `_filters` and `_search` parameters for filtering and search.

## Components
1. **CaseList.vue** - Enhanced with filter dropdowns for handler, priority, overdue
2. **CustomPropertiesPanel.vue** - New component showing case properties from property definitions
3. **DocumentChecklist.vue** - New component showing required documents completion status
4. **caseValidation.js** - Enhanced with case type validity window error messages

## Data Flow
- Filters passed as `_filters[field]` query params to OpenRegister API
- Search uses `_search` parameter
- Property definitions fetched by case type reference
- Document types fetched by case type reference, matched against case documents
