# API de E-commerce Segura con Swagger Completo

[![CI](https://github.com/alejandrocamposd17-beep/ecommerce-api-laravel/actions/workflows/ci.yml/badge.svg)](https://github.com/alejandrocamposd17-beep/ecommerce-api-laravel/actions/workflows/ci.yml)
![Cobertura](https://img.shields.io/badge/cobertura-%E2%89%A580%25-brightgreen)
![Larastan](https://img.shields.io/badge/Larastan-nivel%205-blue)
![Estilo](https://img.shields.io/badge/estilo-Laravel%20Pint-orange)

**Autor:** Alejandro Campos
**Stack:** Laravel 12 · PHP 8.4+ · MySQL · Laravel Sanctum · Stripe · L5-Swagger (OpenAPI 3) · Docker · GitHub Actions

API REST para un e-commerce que cubre el flujo completo: registro de clientes, catálogo de productos, órdenes con cupones de descuento, pagos y reembolsos con Stripe, correos en segundo plano y registro de auditoría. Toda la API está documentada en Swagger UI en la ruta `/api/documentation`.

**Demo en vivo:** se despliega en Render con un clic (plan gratuito, SQLite con datos de ejemplo que se reinician en cada despliegue):

[![Deploy to Render](https://render.com/images/deploy-to-render-button.svg)](https://render.com/deploy?repo=https://github.com/alejandrocamposd17-beep/ecommerce-api-laravel)

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
12. [Seguridad](#seguridad)
13. [Docker](#docker)
14. [Pruebas y CI](#pruebas-y-ci)
15. [Colección de Postman](#colección-de-postman)
16. [Demo en Render](#demo-en-render)

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
- **Cupones de descuento** (porcentaje o monto fijo) con vencimiento, límite de usos y subtotal mínimo; el uso se libera si la orden no se concreta.
- **Reembolsos** desde el panel admin (Stripe Refunds) o desde el dashboard de Stripe (webhook `charge.refunded`): la orden pasa a `refunded` y el stock vuelve al inventario.
- **Correos en cola** (Laravel Queues): confirmación de orden, de pago y de reembolso.
- **Idempotencia**: el webhook ignora eventos repetidos de Stripe y `POST /orders` acepta el encabezado `Idempotency-Key` para reintentar sin duplicar órdenes.
- **Registro de auditoría** de acciones administrativas (productos, cupones, reembolsos) con los valores antes y después.
- **Versionado** `/api/v1`; las rutas `/api/*` sin versión siguen funcionando con el encabezado `Deprecation`.
- Seeders con 20 productos de ejemplo y usuarios de prueba. Cada producto tiene su propia ilustración en `public/images/products` (SVG livianos, sin depender de servicios externos).

## Requisitos

- PHP 8.4 o superior (extensiones: `pdo_mysql`, `mbstring`, `openssl`, `curl`, `json`)
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
   stripe listen --forward-to localhost:8000/api/v1/stripe/webhook
   ```
   Copiar el `whsec_...` que muestra la consola en `STRIPE_WEBHOOK_SECRET`.

Métodos de pago de prueba útiles para el endpoint `/api/v1/payments/{id}/confirm`:

| payment_method            | Resultado                 |
|---------------------------|---------------------------|
| `pm_card_visa`            | Pago exitoso (default)    |
| `pm_card_mastercard`      | Pago exitoso              |
| `pm_card_chargeDeclined`  | Tarjeta rechazada (402)   |
| `pm_card_insufficientFunds` | Fondos insuficientes (402) |

## Documentación Swagger

- Swagger UI: `http://localhost:8000/api/documentation`
- JSON OpenAPI: `http://localhost:8000/docs/api-docs.json`

Para probar endpoints protegidos desde Swagger UI: hacer login en `/api/v1/auth/login`, copiar el `token` de la respuesta, presionar el botón **Authorize** y pegar el token (sin la palabra Bearer).

Si se modifican las anotaciones, regenerar con `php artisan l5-swagger:generate` (o dejar `L5_SWAGGER_GENERATE_ALWAYS=true` en desarrollo).

## Endpoints

Todas las rutas usan el prefijo **`/api/v1`**. Las rutas sin versión (`/api/...`) siguen respondiendo igual, pero incluyen los encabezados `Deprecation: true` y `Link` con la ruta nueva.

| Método | Ruta                               | Auth   | Descripción                                   |
|--------|------------------------------------|--------|-----------------------------------------------|
| POST   | `/api/v1/auth/register`            | No     | Registrar cliente y obtener token             |
| POST   | `/api/v1/auth/login`               | No     | Iniciar sesión y obtener token                |
| GET    | `/api/v1/auth/me`                  | Sí     | Usuario autenticado                           |
| POST   | `/api/v1/auth/logout`              | Sí     | Revocar token actual                          |
| GET    | `/api/v1/products`                 | No     | Listado público (search, category, per_page)  |
| GET    | `/api/v1/products/{id}`            | No     | Detalle de producto                           |
| POST   | `/api/v1/products`                 | Admin  | Crear producto                                |
| PUT    | `/api/v1/products/{id}`            | Admin  | Actualizar producto                           |
| DELETE | `/api/v1/products/{id}`            | Admin  | Eliminar producto                             |
| GET    | `/api/v1/orders`                   | Sí     | Historial de compras del usuario              |
| POST   | `/api/v1/orders`                   | Sí     | Crear orden (cupón e `Idempotency-Key` opcionales) |
| GET    | `/api/v1/orders/{id}`              | Sí     | Detalle de una orden propia                   |
| POST   | `/api/v1/orders/{id}/pay`          | Sí     | Crear PaymentIntent en Stripe                 |
| POST   | `/api/v1/orders/{id}/refund`       | Admin  | Reembolsar una orden pagada                   |
| GET    | `/api/v1/payments/{id}`            | Sí     | Consultar estado del pago                     |
| POST   | `/api/v1/payments/{id}/confirm`    | Sí     | Confirmar pago (modo prueba)                  |
| GET    | `/api/v1/admin/coupons`            | Admin  | Listar cupones                                |
| POST   | `/api/v1/admin/coupons`            | Admin  | Crear cupón                                   |
| PUT    | `/api/v1/admin/coupons/{id}`       | Admin  | Actualizar cupón                              |
| DELETE | `/api/v1/admin/coupons/{id}`       | Admin  | Eliminar cupón (si ya se usó, se desactiva)   |
| GET    | `/api/v1/admin/audit-logs`         | Admin  | Registro de auditoría (filtro `action`)       |
| POST   | `/api/v1/stripe/webhook`           | Stripe | Webhook firmado de Stripe                     |

## Flujo de compra de principio a fin

```bash
# 1. Registro
curl -X POST http://localhost:8000/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Alejandro Campos","email":"ale@test.com","password":"password123","password_confirmation":"password123"}'

# 2. Ver catálogo
curl http://localhost:8000/api/v1/products

# 3. Crear orden con cupón (usar el token del paso 1). La Idempotency-Key evita duplicados si se reintenta
curl -X POST http://localhost:8000/api/v1/orders \
  -H "Authorization: Bearer TOKEN" -H "Content-Type: application/json" \
  -H "Idempotency-Key: compra-0001-ale" \
  -d '{"items":[{"product_id":1,"quantity":1},{"product_id":5,"quantity":2}],"shipping_address":"San Salvador","coupon_code":"BIENVENIDA10"}'

# 4. Iniciar pago (devuelve client_secret y el id del pago)
curl -X POST http://localhost:8000/api/v1/orders/1/pay -H "Authorization: Bearer TOKEN"

# 5a. Con frontend: usar client_secret con Stripe.js / Elements.
# 5b. Sin frontend: confirmar desde el backend con tarjeta de prueba
curl -X POST http://localhost:8000/api/v1/payments/1/confirm \
  -H "Authorization: Bearer TOKEN" -H "Content-Type: application/json" \
  -d '{"payment_method":"pm_card_visa"}'

# 6. Historial
curl http://localhost:8000/api/v1/orders -H "Authorization: Bearer TOKEN"
```

Estados de la orden: `pending` → `paid` (pago exitoso) / `failed` (rechazado) / `cancelled`, y `paid` → `refunded` (reembolso). Solo las órdenes `pending` pueden pagarse; al pasar a `failed`, `cancelled` o `refunded` el stock se repone y el cupón vuelve a estar disponible.

Cupones de ejemplo: `BIENVENIDA10` (10% de descuento) y `ENVIOGRATIS` ($5 en compras desde $50). El descuento se aplica antes del IVA.

## Estructura de la base de datos

| Tabla                    | Descripción                                                  |
|--------------------------|--------------------------------------------------------------|
| `users`                  | Clientes y administradores (`role`, `phone`, `address`)      |
| `personal_access_tokens` | Tokens de Sanctum                                            |
| `products`               | Catálogo (`slug`, `price`, `stock`, `category`, `is_active`) |
| `orders`                 | Órdenes (`order_number`, `status`, `subtotal`, `discount`, `tax`, `total`, `idempotency_key`) |
| `order_items`            | Detalle por orden (guarda nombre y precio al momento de compra) |
| `payments`               | Transacciones de Stripe (`payment_intent`, `status`, `paid_at`, `refunded_at`) |
| `coupons`                | Cupones (`code`, `type`, `value`, `max_uses`, `used_count`, `expires_at`) |
| `stripe_events`          | IDs de eventos de Stripe ya procesados (idempotencia del webhook) |
| `audit_logs`             | Acciones de administradores (`action`, `changes` antes/después, IP) |

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
| 409    | Conflicto (stock insuficiente, orden pagada, demasiadas órdenes pendientes, reembolso de una orden no pagada) |
| 422    | Error de validación, cupón no válido o `Idempotency-Key` reutilizada con otros datos |
| 429    | Demasiadas peticiones (límite de velocidad)  |
| 502    | Error de comunicación con Stripe             |

## Estructura del proyecto

```
app/
├── Console/Commands/ExpirePendingOrders.php  # Cancela órdenes sin pagar y libera stock
├── Exceptions/ApiExceptionHandler.php   # Respuestas de error JSON centralizadas
├── Http/
│   ├── Controllers/Api/
│   │   ├── ApiController.php            # Base + esquemas OpenAPI
│   │   ├── AuthController.php
│   │   ├── ProductController.php
│   │   ├── OrderController.php          # Órdenes, cupones e Idempotency-Key
│   │   ├── PaymentController.php        # Pagos, reembolsos y webhook idempotente
│   │   ├── CouponController.php         # Administración de cupones
│   │   └── AuditLogController.php       # Consulta del registro de auditoría
│   ├── Middleware/                      # SecurityHeaders, EnsureUserIsAdmin, DeprecatedApiVersion
│   ├── Requests/                        # Form Requests (validaciones)
│   └── Resources/                       # API Resources (formato de salida)
├── Mail/                                # OrderPlaced, PaymentReceived, OrderRefunded (en cola)
├── Models/                              # User, Product, Order, OrderItem, Payment, Coupon, AuditLog, StripeEvent
└── Services/StripeService.php           # Integración con stripe/stripe-php
database/
├── migrations/                          # users, products, orders, payments, coupons, audit_logs, stripe_events
└── seeders/                             # UserSeeder, ProductSeeder, CouponSeeder
routes/api.php                           # Definición de rutas
config/l5-swagger.php                    # Configuración Swagger
config/shop.php                          # IVA, tiempo de expiración y límite de órdenes pendientes
tests/Feature/                           # ApiFlowTest, SecurityTest y AdvancedFeaturesTest
render.yaml                              # Despliegue de la demo en Render
docs/                                    # Colección de Postman
```

## Usuarios de prueba

| Rol      | Email                   | Contraseña |
|----------|-------------------------|------------|
| Admin    | admin@ecommerce.com     | password   |
| Cliente  | alejandro@example.com   | password   |
| Cliente  | cliente@example.com     | password   |

## Seguridad

Además de Sanctum, roles y Form Requests, la API incluye estas protecciones (cada una con su prueba en `tests/Feature`):

| Riesgo | Protección |
|---|---|
| Eventos de Stripe repetidos | Cada evento se guarda por su ID (índice único): si Stripe lo reenvía, se responde 200 sin volver a aplicarlo. |
| Órdenes duplicadas por reintentos | `Idempotency-Key` por usuario: la misma llave devuelve la misma orden y con otros datos responde 422. |
| Cambios sin rastro | Las acciones de admin (productos, cupones, reembolsos) quedan en `audit_logs` con usuario, IP y valores antes/después. |
| Abuso de cupones | El cupón se bloquea dentro de la transacción (`lockForUpdate`) para no superar su límite de usos con órdenes simultáneas. |
| Webhooks falsos de Stripe | Se rechaza cualquier webhook si `STRIPE_WEBHOOK_SECRET` no está configurado (con un secreto vacío cualquiera podría firmar eventos) y se verifica la firma HMAC. |
| Pagos manipulados | Una orden solo pasa a `paid` si el **monto, la moneda y el `order_id`** del PaymentIntent coinciden con la orden. |
| Cobrar órdenes cerradas | No se confirma ni se marca como pagada una orden expirada, fallida o cancelada (su stock ya se liberó); se registra para reembolso. |
| Acaparar inventario | Las órdenes sin pagar expiran a los 30 min (`orders:expire-pending`, cada 5 min) y cada usuario puede tener máximo 5 pendientes. |
| Fuerza bruta y abuso | Límites de velocidad: 10/min en registro y login por IP, 10/min en órdenes y pagos por usuario y 60/min en toda la API. |
| Confirmación de pagos desde el servidor | `/payments/{id}/confirm` (tarjetas de prueba) se desactiva en producción; ahí se confirma con Stripe.js. El `payment_method` debe tener formato `pm_…`. |
| Escalada de privilegios | El registro siempre crea `customer` (se ignora `role` en la petición); solo `admin` gestiona productos. |
| Acceso a datos ajenos | Órdenes y pagos de otros usuarios responden 404 (no se revela que existen). |
| Fuga de información | Los errores de Stripe se registran en el log y el cliente recibe un mensaje genérico; sin `APP_DEBUG` no se exponen trazas. |
| Productos ocultos | Los productos desactivados no se muestran al público. |
| Cabeceras HTTP | `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy` y `Cache-Control: no-store` en respuestas autenticadas. |
| Tokens | Expiran a las 24 h (`SANCTUM_TOKEN_EXPIRATION`) y `logout` los revoca. |

> **Tareas programadas:** para que las órdenes expiren, ejecuta `php artisan schedule:work` (desarrollo) o agrega al cron del servidor `* * * * * php artisan schedule:run`. Con Docker ya corre en el servicio `scheduler`.

## Docker

```bash
cp .env.example .env            # opcional: agrega tus claves de Stripe
docker compose up --build
```

- API y Swagger: http://localhost:8000/api/documentation
- Levanta 4 servicios: `app` (API), `db` (MySQL 8.4), `scheduler` (expiración de órdenes) y `queue` (envío de correos).
- Al iniciar ejecuta migraciones, carga los datos de ejemplo una sola vez y genera Swagger.
- La imagen corre sin privilegios de root (`www-data`).

## Pruebas y CI

```bash
php artisan test
```

- **Pruebas de integración** (SQLite en memoria): flujo de compra, stock, pagos simulados con Mockery, webhooks firmados e idempotentes, cupones, reembolsos, correos, auditoría, versionado, roles, límites de velocidad y cabeceras de seguridad.
- **Cobertura mínima de 80%**: el CI falla si baja (`php artisan test --coverage --min=80`).
- **Calidad de código**: estilo con **Laravel Pint** (preset laravel) y análisis estático con **Larastan nivel 5**.
- **GitHub Actions** en cada push y PR: `composer validate`, pruebas con cobertura en PHP 8.4, Pint, Larastan, generación de Swagger (valida las anotaciones OpenAPI), `composer audit` y build de la imagen Docker.
- **Dependabot** revisa semanalmente las dependencias de Composer, las Actions y la imagen de Docker.

## Colección de Postman

Importa `docs/ecommerce-api.postman_collection.json` en Postman. Al ejecutar **Login** el token se guarda automáticamente, y **Crear orden** / **Iniciar pago** guardan los IDs para los siguientes pasos.

## Demo en Render

`render.yaml` define el despliegue en el plan gratuito de Render con Docker y SQLite:

1. Pulsa el botón **Deploy to Render** de arriba (o en Render: *New → Blueprint* y elige este repositorio).
2. Render construye la imagen y la publica en `https://<nombre>.onrender.com`. Swagger queda en `/api/documentation`.
3. Opcional: agrega `STRIPE_KEY`, `STRIPE_SECRET` y `STRIPE_WEBHOOK_SECRET` (modo prueba) en *Environment* para probar los pagos.

Notas: el servicio gratuito se suspende tras 15 minutos sin uso (la primera petición tarda unos segundos en despertar) y la base de datos vuelve a los datos de ejemplo en cada reinicio. Los correos se escriben en el log del servicio.

---

Desarrollado por **Alejandro Campos**.
