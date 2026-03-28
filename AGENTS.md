# AGENTS.md - eRede PHP SDK Development Guide

## Project Overview
eRede PHP SDK is a payment processing integration library for the e.Rede gateway. It provides transaction authorization, capture, refunds, and query operations. The codebase follows strict type safety (PHP 8.1+, PHPStan level 8) and uses a service-oriented architecture.
The oficial API documentation is at ./docs/2026-03-23-e-rede-doc.md.

## Architecture & Data Flows

### Service Layer Pattern
All payment operations follow a consistent Service pattern:
- **eRede** (main facade): Entry point at `src/Rede/eRede.php` that orchestrates services
- **Service hierarchy**: `AbstractService` → `AbstractTransactionsService` → specific services
  - `CreateTransactionService`: Handles authorization/capture
  - `CancelTransactionService`: Handles refunds  
  - `CaptureTransactionService`: Handles post-auth captures
  - `GetTransactionService`: Query transaction status

Services extend `AbstractService` which handles HTTP communication (CURL) with the e.Rede API. All services receive `Store` credentials and optional `LoggerInterface` for debugging.

### Serialization Pattern
Two-way serialization is critical:
- **Outbound**: Transaction objects → JSON via `SerializeTrait` (implements `RedeSerializable extends JsonSerializable`)
  - `jsonSerialize()` filters null values using `array_filter()`
- **Inbound**: JSON response → Domain objects via `CreateTrait::create()` pattern
  - Recursively hydrates object properties from stdClass data
  - Special handling: DateTime properties use string-to-DateTime conversion
  
Key domain objects implementing both interfaces: `Transaction`, `Authorization`, `Capture`, `Refund`, etc.

### HTTP Communication
`AbstractService::sendRequest()` handles all API calls:
- Constructs headers (User-Agent with version/store/platform info, Transaction-Response flag)
- Uses CURL for requests (required: `ext-curl`, `ext-json`)
- Processes response through `jsonUnserialize()` to populate Transaction object

## Critical Data Models

### Transaction (Core Model)
Located at `src/Rede/Transaction.php` (1100+ lines). Represents a payment with:
- Payment method: `creditCard()`, `debit()` fluent methods
- Request data: amount, reference, installments, soft descriptor
- Response data: tid, authorizationCode, returnCode, authorization object
- Advanced features: 3DS2 (ThreeDSecure), Cart (items), Consumer (buyer), Capture control

**Pattern**: Fluent interface with getter/setter methods. Properties initially null, populated by API response.

### Store
`src/Rede/Store.php`: Encapsulates credentials (filiation/PV, token) and environment (sandbox vs production). OAuth2 bearer token support for modern auth flows.

## Developer Workflows

### Testing with Environment Variables
Tests require credentials:
```bash
export REDE_PV=your_filiation
export REDE_TOKEN=your_token
```
Optional debug logging:
```bash
export REDE_DEBUG=1  # Enables LogLevel::Debug in Monolog
```

Run tests:
- Docker: `docker compose up --build`

### Quality Tools
Configured in `composer.json`:
- **PHPUnit 9.5**: Unit tests at `test/Rede/eRedeTest.php` (extends TestCase with setUp/tearDown)
- **PHPStan 1.8.6**: Static analysis at level 8 (strict). Config in `phpstan.neon`
- **PHP-CS-Fixer 3.11**: Code formatting
- **PHPCS 3.7**: Code sniffer with Squiz standard
- **PHPCPD 6.0**: Detects code duplication

Run all checks before committing (implies: maintain strict typing, avoid null assignments).

## Code Conventions

### Naming & Classes
- **Namespace**: All classes in `Rede\` namespace with PSR-4 autoload
- **Trait usage**: 
  - `SerializeTrait`: Implements JSON serialization (use in all domain models)
  - `CreateTrait`: Implements JSON deserialization via `create(stdClass)` static method
- **Interfaces**:
  - `RedeSerializable`: Marks objects that output JSON (extends JsonSerializable)
  - `RedeUnserializable`: Marks objects that parse JSON via `jsonUnserialize(string)`
- **Exceptions**: Custom `RedeException` at `src/Rede/Exception/RedeException.php`

### Method Style
- **Fluent builders**: `$transaction->creditCard(...)->cart(...)` returns `$this` for chaining
- **Validation**: Constructor and setter validation throw `InvalidArgumentException`
- **Type hints**: All parameters and returns explicitly typed (PHP 8.1 union types, nullable)
- **Private properties with public getters/setters**: No magic methods

### Response Handling
API responses use status codes in `returnCode` field (not HTTP status):
- `'00'` = Success
- Other codes = Errors in `returnMessage` field
- Full response hydrated into `Transaction` object, inspect `$transaction->getReturnCode()` and `$transaction->getAuthorization()`

## Integration Points

### Required Dependencies
- `psr/log`: PSR-3 logging interface (Monolog recommended)
- `monolog/monolog`: Used in test setup for debug output
- `ext-curl`, `ext-json`: PHP extensions

### API Gateway
e.Rede REST API (configured via `Store::getEnvironment()`):
- Production: `https://api.erede.com.br`
- Sandbox: `https://api-sandbox.erede.com.br`
- Endpoints: `/transactions`, `/transactions/{tid}/capture`, `/transactions/{tid}/cancel`

### Response Integration
After calling service (e.g., `(new eRede($store))->create($transaction)`):
1. Check `$transaction->getReturnCode() === '00'` for success
2. Access response: `$transaction->getTid()`, `$transaction->getAuthorization()->getAuthorizationCode()`
3. Store transaction for later query/capture: use tid with `GetTransactionService`

## Adding New Features

When extending the SDK:
1. **New domain model**: Extend with `SerializeTrait` and `CreateTrait` to support request/response serialization
2. **New service**: Extend `AbstractTransactionsService` or `AbstractService` and implement `execute()` to define HTTP method and endpoint
3. **Validation**: Add in model constructors and services before calling `sendRequest()`
4. **Testing**: Add test to `eRedeTest::setUp()` which creates Store with logger for debugging
5. **Keep backwards compatible**: eRede is at v1.1.0 as library; avoid breaking constructor changes

