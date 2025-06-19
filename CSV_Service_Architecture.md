# CSV Service Architecture Documentation

## 1. Overall Architecture

### High-Level Overview
The CSV service architecture is designed to handle data import operations with a focus on flexibility and extensibility. The system follows a layered architecture pattern:

```
┌─────────────────┐
│   Controllers   │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│    Services     │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│    Mappers      │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Configuration  │
└─────────────────┘
```

### Key Components
- **Controllers**: Handle HTTP requests and coordinate service operations
- **Services**: Implement core business logic for data processing
- **Mappers**: Transform data between different formats
- **Configuration**: Manage import settings and mappings

### Dependency Flow
1. Controllers receive requests and validate input
2. Services process the data according to business rules
3. Mappers transform data between formats
4. Configuration provides settings and mappings

## 2. Component Responsibilities

### Controllers

#### RoleController
- **Purpose**: Manages role assignment for CSV columns
- **Key Methods**:
  - `index()`: Displays role configuration interface
  - `postIndex()`: Processes role assignments
- **Routes**:
  - GET `/import/file/roles`: Display role configuration
  - POST `/import/file/roles`: Save role assignments

### Services

#### RoleService
- **Purpose**: Handles role-related operations for CSV data
- **Key Methods**:
  - `getColumns()`: Extracts column headers from CSV
  - `getExampleData()`: Retrieves sample data for preview
  - `getExampleDataFromCamt()`: Handles CAMT format data

#### MapperService
- **Purpose**: Transforms data between different formats
- **Features**:
  - Supports multiple mapping strategies
  - Handles data validation
  - Manages data transformation rules

## 3. Data Models & DTOs

### Configuration
```php
class Configuration {
    private string $contentType;
    private array $roles;
    private array $mapping;
    private bool $doMapping;
    // ...
}
```

### Transaction
```php
class Transaction {
    private Configuration $configuration;
    private array $data;
    // ...
}
```

## 4. Error Handling & Validation

### Exception Hierarchy
- `ImporterErrorException`: Base exception for import errors
- `InvalidArgumentException`: Invalid input data
- `UnableToProcessCsv`: CSV processing errors

### Validation Rules
1. **Input Validation**:
   - File format validation
   - Required fields checking
   - Data type validation

2. **Business Rules**:
   - Role assignment validation
   - Mapping rule validation
   - Data consistency checks

### Error Response Format
```json
{
    "error": {
        "message": "Error description",
        "code": "ERROR_CODE",
        "details": {}
    }
}
```

## 5. Extension Points & Configuration

### Configuration Options
- **File Format Settings**:
  - Delimiter (comma, semicolon, tab)
  - Headers presence
  - Content type (CSV, CAMT)

- **Role Configuration**:
  - Column role assignments
  - Mapping rules
  - Validation rules

### Extension Points
1. **Custom Mappers**:
   - Implement `MapperInterface`
   - Register in service container

2. **Custom Validators**:
   - Extend base validator
   - Register validation rules

3. **Custom Transformers**:
   - Implement transformation interface
   - Register in configuration

## 6. Usage Examples

### Role Assignment
```http
POST /import/file/roles
Content-Type: application/json

{
    "roles": {
        "column1": "date",
        "column2": "amount",
        "column3": "description"
    },
    "do_mapping": true
}
```

### Response
```json
{
    "success": true,
    "message": "Roles assigned successfully",
    "data": {
        "configuration": {
            "roles": {
                "column1": "date",
                "column2": "amount",
                "column3": "description"
            },
            "do_mapping": true
        }
    }
}
```

### Error Response
```json
{
    "error": {
        "message": "Invalid role assignment",
        "code": "INVALID_ROLE",
        "details": {
            "column": "column1",
            "role": "invalid_role"
        }
    }
}
``` 