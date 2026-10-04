# API de E-commerce Segura con Swagger Completo

**Autor:** Alejandro Campos
**Stack:** Laravel 12 · PHP 8.2+ · MySQL · Laravel Sanctum · Stripe · L5-Swagger (OpenAPI 3)

API REST para un e-commerce básico que cubre el flujo completo: registro de clientes, catálogo de productos, creación de órdenes y procesamiento seguro de pagos con Stripe. Toda la API está documentada en Swagger UI en la ruta `/api/documentation`.

---

## Tabla de contenido

1. [Características](#características)
2. [Requisitos](#requisitos)
3. [Instalación paso a paso](#instalación-paso-a-paso)
4. [Configuración de Stripe](#configuración-de-stripe)
5. [Documentación Swagger](#documentación-swagger)
6. [Endpoints](#endpoints)
7. [Flujo de compra de principio a fin](#flujo-de-compra-de-principio-a-fin)
8. [Estructura de la base de datos](#estructura-de-la-base-de-datos)
9. [Manejo de errores](#manejo-de-errores)
10. [Estructura del proyecto](#estructura-del-proyecto)
11. [Usuarios de prueba](#usuarios-de-prueba)

---

## Características

- Autenticación con tokens Bearer usando **Laravel Sanctum** (registro, login, logout, perfil).
- Roles `admin` y `customer`: solo admin puede crear, editar o eliminar productos.
- **CRUD completo de productos** con listado público, búsqueda y paginación.
- **Órdenes de compra** con validación de stock, cálculo de IVA (13%) y descuento de inventario dentro de una transacción.
- **Historial de compras** por usuario.
- **Pagos con Stripe** (`stripe/stripe-php`): creación de PaymentIntent, confirmación en modo prueba y webhook con verificación de firma.
- **Devolución de stock**: si Stripe rechaza el pago o se cancela el PaymentIntent, la orden pasa a `failed`/`cancelled` y el inventario reservado vuelve al catálogo. La operación es idempotente (no repone dos veces si el rechazo llega por `/confirm` y luego por el webhook).
- Validaciones con **Form Requests** y respuestas de error JSON consistentes.
- Documentación **Swagger/OpenAPI** generada con `darkaonline/l5-swagger` a partir de atributos PHP 8.
- Seeders con 20 productos de ejemplo y usuarios de prueba. Cada producto tiene su propia ilustración en `public/images/products` (SVG livianos, sin depender de servicios externos).

## Requisitos

- PHP 8.2 o superior (extensiones: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `json`)
- Composer 2.x
- MySQL 8 / MariaDB 10.4+
- Cuenta de Stripe en modo prueba (gratuita)

## Instalación paso a paso

```bash
# 1. Clonar el repositorio
git clone https://github.com/alejandrocamposd17-beep/ecommerce-api-laravel.git
cd ecommerce-api-laravel

# 2. Instalar dependencias
composer install

# 3. Crear el archivo de entorno y la llave de la aplicación
cp .env.example .env
php artisan key:generate

# 4. Crear la base de datos en MySQL
#    CREATE DATABASE ecommerce_api CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
#    Luego editar DB_DATABASE, DB_USERNAME y DB_PASSWORD en .env

# 5. Ejecutar migraciones y seeders
php artisan migrate --seed

# 6. Generar la documentación Swagger
php artisan l5-swagger:generate

# 7. Levantar el servidor
php artisan serve
```

Abrir en el navegador: **http://localhost:8000/api/documentation**

> En Windows con XAMPP/Laragon el proceso es el mismo. Si `php artisan serve` usa otro puerto, actualizar `APP_URL` y `L5_SWAGGER_CONST_HOST` en `.env`.

### Opción rápida con SQLite (sin MySQL)

En `.env` cambiar `DB_CONNECTION=mysql` por `DB_CONNECTION=sqlite` y comentar las demás líneas `DB_*`. Al correr `php artisan migrate --seed`, Laravel ofrece crear `database/database.sqlite`.

### Windows: si `php artisan serve` falla con "Failed to listen on 127.0.0.1:8000"

Es un problema conocido de PHP en Windows. Dos soluciones:

- En `php.ini` cambiar `variables_order` a `"EGPCS"` y volver a abrir la terminal.
- O levantar el servidor de PHP directamente desde la carpeta `public`:
  ```powershell
  cd public
  php -S 127.0.0.1:8000 ..\vendor\laravel\framework\src\Illuminate\Foundation\resources\server.php
  ```

## Configuración de Stripe

1. Crear cuenta en https://dashboard.stripe.com y activar el **modo de prueba**.
2. En *Developers > API keys* copiar la clave publicable y la secreta:
   ```env
   STRIPE_KEY=pk_test_...
   STRIPE_SECRET=sk_test_...
   ```
3. (Opcional) Para probar el webhook localmente, instalar Stripe CLI y ejecutar:
   ```bash
   stripe listen --forward-to localhost:8000/api/stripe/webhook
   ```
   Copiar el `whsec_...` que muestra la consola en `STRIPE_WEBHOOK_SECRET`.

Métodos de pago de prueba útiles para el endpoint `/api/payments/{id}/confirm`:

| payment_method            | Resultado                 |
|---------------------------|---------------------------|
| `pm_card_visa`            | Pago exitoso (default)    |
| `pm_card_mastercard`      | Pago exitoso              |
| `pm_card_chargeDeclined`  | Tarjeta rechazada (402)   |
| `pm_card_insufficientFunds` | Fondos insuficientes (402) |

## Documentación Swagger

- Swagger UI: `http://localhost:8000/api/documentation`
- JSON OpenAPI: `http://localhost:8000/docs/api-docs.json`

Para probar endpoints protegidos desde Swagger UI: hacer login en `/api/auth/login`, copiar el `token` de la respuesta, presionar el botón **Authorize** y pegar el token (sin la palabra Bearer).

Si se modifican las anotaciones, regenerar con `php artisan l5-swagger:generate` (o dejar `L5_SWAGGER_GENERATE_ALWAYS=true` en desarrollo).

## Endpoints

| Método | Ruta                          | Auth   | Descripción                                   |
|--------|-------------------------------|--------|-----------------------------------------------|
| POST   | `/api/auth/register`          | No     | Registrar cliente y obtener token             |
| POST   | `/api/auth/login`             | No     | Iniciar sesión y obtener token                |
| GET    | `/api/auth/me`                | Sí     | Usuario autenticado                           |
| POST   | `/api/auth/logout`            | Sí     | Revocar token actual                          |
| GET    | `/api/products`               | No     | Listado público (search, category, per_page)  |
| GET    | `/api/products/{id}`          | No     | Detalle de producto                           |
| POST   | `/api/products`               | Admin  | Crear producto                                |
| PUT    | `/api/products/{id}`          | Admin  | Actualizar producto                           |
| DELETE | `/api/products/{id}`          | Admin  | Eliminar producto                             |
| GET    | `/api/orders`                 | Sí     | Historial de compras del usuario              |
| POST   | `/api/orders`                 | Sí     | Crear orden de compra                         |
| GET    | `/api/orders/{id}`            | Sí     | Detalle de una orden propia                   |
| POST   | `/api/orders/{id}/pay`        | Sí     | Crear PaymentIntent en Stripe                 |
| GET    | `/api/payments/{id}`          | Sí     | Consultar estado del pago                     |
| POST   | `/api/payments/{id}/confirm`  | Sí     | Confirmar pago (modo prueba)                  |
| POST   | `/api/stripe/webhook`         | Stripe | Webhook firmado de Stripe                     |

## Flujo de compra de principio a fin

```bash
# 1. Registro
curl -X POST http://localhost:8000/api/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Alejandro Campos","email":"ale@test.com","password":"password123","password_confirmation":"password123"}'

# 2. Ver catálogo
curl http://localhost:8000/api/products

# 3. Crear orden (usar el token del paso 1)
curl -X POST http://localhost:8000/api/orders \
  -H "Authorization: Bearer TOKEN" -H "Content-Type: application/json" \
  -d '{"items":[{"product_id":1,"quantity":1},{"product_id":5,"quantity":2}],"shipping_address":"San Salvador"}'

# 4. Iniciar pago (devuelve client_secret y el id del pago)
curl -X POST http://localhost:8000/api/orders/1/pay -H "Authorization: Bearer TOKEN"

# 5a. Con frontend: usar client_secret con Stripe.js / Elements.
# 5b. Sin frontend: confirmar desde el backend con tarjeta de prueba
curl -X POST http://localhost:8000/api/payments/1/confirm \
  -H "Authorization: Bearer TOKEN" -H "Content-Type: application/json" \
  -d '{"payment_method":"pm_card_visa"}'

# 6. Historial
curl http://localhost:8000/api/orders -H "Authorization: Bearer TOKEN"
```

Estados de la orden: `pending` → `paid` (pago exitoso) / `failed` (rechazado) / `cancelled`. Solo las órdenes `pending` pueden pagarse; al pasar a `failed` o `cancelled` el stock se repone.

## Estructura de la base de datos

| Tabla                    | Descripción                                                  |
|--------------------------|--------------------------------------------------------------|
| `users`                  | Clientes y administradores (`role`, `phone`, `address`)      |
| `personal_access_tokens` | Tokens de Sanctum                                            |
| `products`               | Catálogo (`slug`, `price`, `stock`, `category`, `is_active`) |
| `orders`                 | Órdenes (`order_number`, `status`, `subtotal`, `tax`, `total`) |
| `order_items`            | Detalle por orden (guarda nombre y precio al momento de compra) |
| `payments`               | Transacciones de Stripe (`payment_intent`, `status`, `paid_at`) |

Relaciones: `users 1..N orders`, `orders 1..N order_items`, `products 1..N order_items`, `orders 1..N payments`.

## Manejo de errores

Todas las respuestas siguen el mismo formato:

```json
{ "success": true,  "message": "Operación exitosa", "data": { ... } }
{ "success": false, "message": "Los datos enviados no son válidos.", "errors": { "email": ["..."] } }
```

| Código | Significado                                  |
|--------|----------------------------------------------|
| 401    | Token ausente o inválido                     |
| 402    | Pago rechazado por Stripe                    |
| 403    | Sin permisos (solo admin)                    |
| 404    | Recurso inexistente o ajeno al usuario       |
| 409    | Conflicto (stock insuficiente, orden pagada) |
| 422    | Error de validación (Form Request)           |
| 502    | Error de comunicación con Stripe             |

## Estructura del proyecto

```
app/
├── Exceptions/ApiExceptionHandler.php   # Respuestas de error JSON centralizadas
├── Http/
│   ├── Controllers/Api/
│   │   ├── ApiController.php            # Base + esquemas OpenAPI
│   │   ├── AuthController.php
│   │   ├── ProductController.php
│   │   ├── OrderController.php
│   │   └── PaymentController.php
│   ├── Requests/                        # Form Requests (validaciones)
│   └── Resources/                       # API Resources (formato de salida)
├── Models/                              # User, Product, Order, OrderItem, Payment
└── Services/StripeService.php           # Integración con stripe/stripe-php
database/
├── migrations/                          # users, products, orders, order_items, payments
└── seeders/                             # UserSeeder, ProductSeeder
routes/api.php                           # Definición de rutas
config/l5-swagger.php                    # Configuración Swagger
```

## Usuarios de prueba

| Rol      | Email                   | Contraseña |
|----------|-------------------------|------------|
| Admin    | admin@ecommerce.com     | password   |
| Cliente  | alejandro@example.com   | password   |
| Cliente  | cliente@example.com     | password   |

## Pruebas

```bash
php artisan test
```

---

Desarrollado por **Alejandro Campos**.
