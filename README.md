# KV SSO SaaS Microservice Platform

A fully functional **microservices-based** SaaS application using **Laravel 10**, demonstrating:

- 🏗️ **5 independent, loosely coupled microservices** with different database backends
- 🔐 **Secure multi-tenant JWT authentication** (SSO) with RBAC/ABAC
- 🔄 **Saga Orchestration pattern** for distributed transactions with compensating rollbacks
- 🔗 **Cross-service filtering** of inventory and orders by product attributes
- 🐳 **Docker Compose** for local development orchestration

---

## 🏛️ Architecture

```
                        ┌─────────────────────────────┐
                        │       API Gateway (nginx)    │
                        │         Port 8000            │
                        └───────────┬─────────────────┘
                                    │
            ┌───────────────────────┼────────────────────────┐
            │           │           │           │             │
     ┌──────▼──────┐  ┌─▼──────┐  ┌▼────────┐ ┌▼──────────┐ ┌▼────────────┐
     │Auth Service │  │  User  │  │ Product │ │Inventory  │ │   Order     │
     │  Port 8001  │  │Service │  │ Service │ │ Service   │ │  Service    │
     │  Laravel+   │  │8002    │  │  8003   │ │  8004     │ │   8005      │
     │  MySQL      │  │MySQL   │  │PostgreSQL│ │  MySQL    │ │  MySQL      │
     └─────────────┘  └────────┘  └─────────┘ └───────────┘ └─────────────┘
            │                          │              │              │
     ┌──────▼──────┐              ┌────▼──────────────▼──────────────▼────┐
     │  MySQL DB   │              │                Redis                   │
     │  auth_db    │              │    (Caching, Sessions, Queues)         │
     └─────────────┘              └────────────────────────────────────────┘
```

### Services Overview

| Service | Port | Database | Responsibility |
|---------|------|----------|----------------|
| **Auth Service** | 8001 | MySQL | JWT SSO, multi-tenant auth, RBAC |
| **User Service** | 8002 | MySQL | User profiles, tenant-scoped |
| **Product Service** | 8003 | **PostgreSQL** | Product catalog, categories |
| **Inventory Service** | 8004 | MySQL | Stock management, reservations |
| **Order Service** | 8005 | MySQL | Orders + Saga orchestration |
| **API Gateway** | 8000 | - | nginx reverse proxy |

---

## 🚀 Quick Start

### Prerequisites
- Docker & Docker Compose
- Git

### 1. Clone and Start

```bash
git clone https://github.com/kasunvimarshana/KV_SSO_SAAS_Microservice.git
cd KV_SSO_SAAS_Microservice
docker-compose up -d
```

### 2. Wait for services to be ready

```bash
docker-compose ps
# Wait until all services are "healthy"
```

### 3. Verify all services are running

```bash
curl http://localhost:8000/health          # API Gateway
curl http://localhost:8001/api/health      # Auth Service
curl http://localhost:8002/api/health      # User Service
curl http://localhost:8003/api/health      # Product Service
curl http://localhost:8004/api/health      # Inventory Service
curl http://localhost:8005/api/health      # Order Service
```

---

## 🔐 Authentication & Multi-Tenancy

### Register and Login

```bash
# Register a new user
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{
    "name": "John Doe",
    "email": "john@example.com",
    "password": "password123",
    "password_confirmation": "password123",
    "tenant_code": "default"
  }'

# Response:
# {
#   "message": "User registered successfully",
#   "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9...",
#   "token_type": "Bearer",
#   "user": {
#     "id": 3,
#     "name": "John Doe",
#     "email": "john@example.com",
#     "role": "staff",
#     "tenant_id": 1,
#     "tenant_code": "default"
#   }
# }

# Login with seeded admin
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "admin@default.com", "password": "password"}'
```

### JWT Token Structure

The JWT contains tenant and role information used by all services:
```json
{
  "sub": "1",
  "tenant_id": "1",
  "tenant_code": "default",
  "role": "admin",
  "permissions": ["*"],
  "email": "admin@default.com",
  "exp": 1234567890
}
```

**All subsequent requests require:** `Authorization: Bearer <token>`

---

## 📦 Product Service (PostgreSQL)

```bash
export TOKEN="your_jwt_token_here"

# Create a category
curl -X POST http://localhost:8000/api/categories/ \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"name": "Electronics", "description": "Electronic gadgets"}'

# Create a product
curl -X POST http://localhost:8000/api/products/ \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Laptop Pro X",
    "code": "LAPTOP-001",
    "category_id": 1,
    "price": 1299.99,
    "cost": 800.00,
    "unit": "pcs",
    "attributes": {"brand": "TechCo", "color": "silver", "warranty": "2 years"}
  }'

# Filter products by name
curl "http://localhost:8000/api/products/?name=Laptop" \
  -H "Authorization: Bearer $TOKEN"

# Filter products by category
curl "http://localhost:8000/api/products/?category_id=1" \
  -H "Authorization: Bearer $TOKEN"

# Search products (full text)
curl "http://localhost:8000/api/products/search?q=laptop" \
  -H "Authorization: Bearer $TOKEN"
```

---

## 📊 Inventory Service

```bash
# Create inventory record
curl -X POST http://localhost:8000/api/inventory/ \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "product_id": "1",
    "warehouse": "main",
    "quantity": 500,
    "reorder_level": 50
  }'

# Filter inventory by product name (Cross-Service!)
# This calls Product Service internally to find matching products
curl "http://localhost:8000/api/inventory/filter-by-product-attributes?product_name=Laptop" \
  -H "Authorization: Bearer $TOKEN"

# Filter inventory by product code
curl "http://localhost:8000/api/inventory/filter-by-product-attributes?product_code=LAP" \
  -H "Authorization: Bearer $TOKEN"

# Filter inventory by category
curl "http://localhost:8000/api/inventory/filter-by-product-attributes?category_id=1" \
  -H "Authorization: Bearer $TOKEN"

# Check low stock items
curl "http://localhost:8000/api/inventory/low-stock" \
  -H "Authorization: Bearer $TOKEN"
```

---

## 🛒 Order Service with Saga Pattern

### Create Order (Triggers Saga Orchestration)

```bash
curl -X POST http://localhost:8000/api/orders/ \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "items": [
      {"product_id": "1", "quantity": 2, "price": 1299.99},
      {"product_id": "2", "quantity": 1, "price": 29.99}
    ],
    "shipping_address": "123 Main Street, City, Country",
    "notes": "Please handle with care"
  }'

# Successful Response (SAGA COMPLETED):
# {
#   "message": "Order created successfully",
#   "saga_status": "COMPLETED",
#   "saga_id": "550e8400-e29b-41d4-a716-446655440000",
#   "order": {
#     "id": 1,
#     "status": "confirmed",
#     "total_amount": "2629.97",
#     "items": [...]
#   }
# }
```

### Saga Steps Explained

```
Order Creation Saga:
┌──────────────────────────────────────────────────────────┐
│  STEP 1: Create Order (PENDING) - Order Service DB       │
│  STEP 2: Validate Products - ──► Product Service         │
│  STEP 3: Reserve Inventory - ──► Inventory Service       │
│  STEP 4: Confirm Order (CONFIRMED) - Order Service DB    │
└──────────────────────────────────────────────────────────┘

Compensating Transactions (if failure):
┌──────────────────────────────────────────────────────────┐
│  COMPENSATE STEP 3: Release inventory reservations       │
│  COMPENSATE STEP 1: Mark order as FAILED                 │
└──────────────────────────────────────────────────────────┘
```

### Saga Failure Example (Insufficient Stock)

```bash
# This will fail if inventory is insufficient
curl -X POST http://localhost:8000/api/orders/ \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"items": [{"product_id": "1", "quantity": 99999, "price": 1299.99}]}'

# Failure Response (SAGA COMPENSATED):
# {
#   "error": "Insufficient stock for product 1. Available: 500, Requested: 99999",
#   "saga_status": "COMPENSATED",
#   "saga_id": "660e8400-e29b-41d4-a716-446655440001",
#   "compensations": [
#     {"step": "COMPENSATE_FAIL_ORDER", "order_id": 2, "success": true}
#   ],
#   "failed_step": "STEP_3_RESERVE_INVENTORY"
# }
```

### Cancel Order (Triggers Compensation)

```bash
# Cancel an order - releases inventory reservations
curl -X POST http://localhost:8000/api/orders/1/cancel \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{"reason": "Customer changed mind"}'

# Response includes compensations applied
```

### View Saga Logs

```bash
# List all sagas
curl "http://localhost:8000/api/sagas/" \
  -H "Authorization: Bearer $TOKEN"

# Get specific saga details with all steps
curl "http://localhost:8000/api/sagas/550e8400-e29b-41d4-a716-446655440000" \
  -H "Authorization: Bearer $TOKEN"

# Response:
# {
#   "saga": {
#     "saga_id": "550e8400...",
#     "status": "COMPLETED",
#     "started_at": "2024-01-15T10:30:00Z",
#     "completed_at": "2024-01-15T10:30:01Z"
#   },
#   "steps": [
#     {"step": "1_CREATE_ORDER", "status": "SUCCESS", "timestamp": "..."},
#     {"step": "2_VALIDATE_PRODUCTS", "status": "SUCCESS", "timestamp": "..."},
#     {"step": "3_RESERVE_INVENTORY", "status": "SUCCESS", "timestamp": "..."},
#     {"step": "4_CONFIRM_ORDER", "status": "SUCCESS", "timestamp": "..."}
#   ]
# }
```

### Cross-Service Order Filtering

```bash
# Filter orders by product name (calls Product Service internally)
curl "http://localhost:8000/api/orders/filter-by-product?product_name=Laptop" \
  -H "Authorization: Bearer $TOKEN"

# Filter orders by product code
curl "http://localhost:8000/api/orders/filter-by-product?product_code=LAP-001" \
  -H "Authorization: Bearer $TOKEN"

# Filter orders by category
curl "http://localhost:8000/api/orders/filter-by-product?category_id=1" \
  -H "Authorization: Bearer $TOKEN"
```

---

## 👤 User Service

```bash
# List users (tenant-scoped)
curl "http://localhost:8000/api/users/" \
  -H "Authorization: Bearer $TOKEN"

# Create user profile (linked to auth user)
curl -X POST http://localhost:8000/api/users/ \
  -H "Authorization: Bearer $TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "name": "Jane Smith",
    "email": "jane@company.com",
    "external_id": "auth_user_id_from_auth_service",
    "phone": "+1-555-0100",
    "address": "456 Oak Street, City"
  }'
```

---

## 🏢 Multi-Tenant Architecture

Each request is tenant-scoped via JWT:
- Token contains `tenant_id` and `tenant_code`
- All queries automatically filter by `tenant_id`
- Cross-tenant data access is impossible by design

```bash
# Seeded tenants:
# - "default" tenant: admin@default.com / password
# - "acme" tenant: admin@acme.com / password

# Login as Acme tenant admin
curl -X POST http://localhost:8000/api/auth/login \
  -H "Content-Type: application/json" \
  -d '{"email": "admin@acme.com", "password": "password"}'
```

---

## 🧪 Running Tests

Tests use SQLite in-memory databases and mock external services.

```bash
# Run all tests for a service
cd auth-service && composer install && php artisan test

# Individual services
cd user-service && composer install && php artisan test
cd product-service && composer install && php artisan test
cd inventory-service && composer install && php artisan test
cd order-service && composer install && php artisan test
```

### Test Coverage

| Service | Tests | Key Scenarios |
|---------|-------|---------------|
| Auth Service | 8 tests | Register, login, JWT validation, blacklisting |
| User Service | 7 tests | CRUD, tenant isolation |
| Product Service | 8 tests | CRUD, search, filtering, tenant isolation |
| Inventory Service | 10 tests | Reserve/release, saga compensation, low-stock |
| Order Service | 12 tests | Saga success/failure/compensation, filtering |

---

## 🔄 Saga Pattern Deep Dive

The **Saga Orchestration** pattern manages distributed transactions:

### Why Saga?
In microservices, a single business operation (creating an order) spans multiple services. Traditional ACID transactions don't work across service boundaries. The Saga pattern solves this by:
1. Breaking the transaction into local steps
2. Defining compensating transactions for each step
3. Executing compensations in reverse order when failures occur

### Saga Implementation

```php
// Order Service - SagaOrchestrator::createOrderSaga()
// 
// HAPPY PATH:
// ─────────────────────────────────────────────────────────
// Step 1: Order created (PENDING) ──── local DB
// Step 2: Products validated ──────── Product Service
// Step 3: Inventory reserved ──────── Inventory Service
// Step 4: Order confirmed ─────────── local DB
//
// FAILURE PATH (e.g., insufficient stock at Step 3):
// ─────────────────────────────────────────────────────────
// Step 3 FAILS: "Insufficient stock"
// COMPENSATE Step 3: Release any partial reservations
// COMPENSATE Step 1: Mark order as FAILED
// Return COMPENSATED status with compensation details
```

---

## 🔒 RBAC Authorization

Roles: `super_admin` → `admin` → `manager` → `staff` → `viewer`

```
super_admin: Can manage tenants, all permissions
admin:       Can manage users, products, inventory, orders within tenant
manager:     Can manage inventory and orders
staff:       Can create orders, view products/inventory
viewer:      Read-only access
```

---

## 📁 Project Structure

```
KV_SSO_SAAS_Microservice/
├── api-gateway/                # nginx reverse proxy
│   ├── Dockerfile
│   └── nginx.conf
├── auth-service/               # Authentication & Authorization
│   ├── app/Http/Controllers/   # AuthController, TenantController
│   ├── app/Services/           # JwtService
│   └── tests/                  # Unit + Feature tests
├── user-service/               # User Profile Management
├── product-service/            # Product Catalog (PostgreSQL)
├── inventory-service/          # Inventory & Stock Management
│   └── app/Services/           # ProductServiceClient (cross-service)
├── order-service/              # Order Management + Saga
│   ├── app/Services/           # SagaOrchestrator, ProductServiceClient
│   │                           # InventoryServiceClient
│   └── tests/Unit/             # SagaOrchestratorTest
├── docker/
│   ├── mysql/init.sql          # Creates all MySQL databases
│   └── postgres/init.sql       # Creates PostgreSQL databases
└── docker-compose.yml          # Full stack orchestration
```

---

## 🌐 API Reference

### Auth Service (via Gateway: /api/auth/)
| Method | Endpoint | Auth | Description |
|--------|----------|------|-------------|
| POST | /api/auth/register | - | Register user |
| POST | /api/auth/login | - | Login |
| POST | /api/auth/logout | ✅ | Logout (blacklist token) |
| POST | /api/auth/refresh | - | Refresh JWT token |
| POST | /api/auth/validate-token | - | Validate JWT |
| GET | /api/auth/me | ✅ | Get current user |
| GET | /api/tenants/ | ✅ super_admin | List tenants |

### Product Service (via Gateway: /api/products/, /api/categories/)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /api/products/?name=&code=&category_id= | List/filter products |
| POST | /api/products/ | Create product |
| GET | /api/products/search?q= | Full-text search |
| GET | /api/products/by-ids?ids=1,2,3 | Bulk fetch by IDs |
| GET | /api/categories/ | List categories |

### Inventory Service (via Gateway: /api/inventory/)
| Method | Endpoint | Description |
|--------|----------|-------------|
| GET | /api/inventory/filter-by-product-attributes?product_name= | Cross-service filter |
| POST | /api/inventory/{id}/reserve | Reserve stock (Saga step) |
| POST | /api/inventory/{id}/release | Release stock (Saga compensation) |
| POST | /api/inventory/{id}/adjust | Adjust stock level |
| GET | /api/inventory/low-stock | Get low stock items |

### Order Service (via Gateway: /api/orders/, /api/sagas/)
| Method | Endpoint | Description |
|--------|----------|-------------|
| POST | /api/orders/ | Create order (triggers Saga) |
| GET | /api/orders/filter-by-product?product_name= | Cross-service filter |
| POST | /api/orders/{id}/cancel | Cancel + compensate |
| POST | /api/orders/{id}/complete | Complete order |
| GET | /api/sagas/{sagaId} | View saga details |

---

## ⚙️ Environment Variables

Each service uses a shared `JWT_SECRET` for cross-service token validation. Set in each service's `.env`:

```env
JWT_SECRET=your_shared_secret_min_32_chars_here
```

---

## 🐳 Docker Services

```yaml
Services:
  mysql:5432       # auth_db, user_db, inventory_db, order_db
  postgres:5432    # product_db
  redis:6379       # Cache, sessions, queues
  auth-service     # :8001
  user-service     # :8002
  product-service  # :8003
  inventory-service # :8004
  order-service    # :8005
  api-gateway      # :8000 (nginx)
```
