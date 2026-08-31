# API de reservas de inventario — plan de implementación

## Contexto

`reservations-api/src` es un esqueleto Laravel 12 limpio (sólo el `User` por defecto, `routes/api.php` vacío, migraciones base). La infraestructura ya está montada: nginx + php-fpm 8.3 + MySQL 8.4 en Docker, con `innodb-lock-wait-timeout=10`

Hay que construir `POST /api/reservations`, que descuenta stock de un producto de forma **atómica** e **idempotente**. El riesgo real del ejercicio no es el CRUD: es que dos peticiones simultáneas lean el mismo stock y ambas escriban, produciendo sobreventa (*lost update*), y que un reintento del cliente cree una reserva duplicada. El plan resuelve ambos a nivel de base de datos —no de código PHP— y aporta un arnés de concurrencia que **demuestra** el fallo en una implementación naive antes de acreditar la implementación correcta.

## Decisiones cerradas

| Decisión | Elección |
|---|---|
| Exclusión mutua | `UPDATE … WHERE stock >= :q` + `affectedRows` (un solo statement) |
| SoftDeletes | **Ninguno**, en ninguna entidad (rectifica el enunciado inicial) |
| Modelo de reserva | Confirmación inmediata, sin holds ni TTL |
| Rechazo por stock | Se persiste como fila `status=rejected` |
| Endpoints | Sólo `POST /api/reservations` |
| Idempotencia | `UNIQUE(request_id)` + violación de constraint como señal |
| Reintentos | `DB::transaction(..., attempts: 3)` (deadlock 1213 / lock wait 1205) |

Al no haber SoftDeletes: `UNIQUE(request_id)` plano sin ambigüedad de NULLs, y el `WHERE` del UPDATE no necesita filtrar `deleted_at`.

---

## 1. Esquema

**`database/migrations/*_create_products_table.php`**
- `id`, `name` (string 255), `stock` (**`unsignedInteger`**, default 0), `timestamps()`.
- `unsignedInteger` es la barrera declarativa: en `sql_mode` estricto un decremento bajo cero aborta con 1264 en vez de hacer wrap-around. Es la segunda línea de defensa tras el `WHERE stock >= :q`.

**`database/migrations/*_create_reservations_table.php`**
- `id`
- `request_id` string(64) **`->unique()`** ← el mecanismo de idempotencia
- `product_id` `foreignId()->constrained()->restrictOnDelete()`
- `quantity` `unsignedInteger`
- `remaining_stock` `unsignedInteger` — snapshot de auditoría del stock *después* de la operación. **No autoritativo**: nada lo lee para decidir. Documentar en el modelo.
- `status` string(16) con cast a enum PHP `ReservationStatus { Confirmed, Rejected }`
- `timestamps()`

**Factories + seeder** (`ProductFactory`, `ReservationFactory`, `DatabaseSeeder`) con un producto de stock conocido para el arnés de concurrencia.

## 2. Dominio

`app/Domain/Reservations/` (o `app/Services/`, según preferencia — mantener una sola convención):

- **`ReservationStrategy`** (interface): `reserve(string $requestId, int $productId, int $quantity): ReservationResult`
- **`AtomicReservationService`** — implementación real.
- **`NaiveReservationService`** — read → `usleep(race_delay)` → write. Existe **sólo** para que el arnés demuestre la sobreventa. Se enlaza por config.
- **`ReservationResult`** (readonly DTO): `reservation`, `wasReplay: bool`.
- Excepciones: `ProductNotFoundException`, `IdempotencyConflictException`.

**Binding** en `AppServiceProvider` según `config('reservations.strategy')` ← `RESERVATION_STRATEGY=atomic|naive`. Nuevo `config/reservations.php` con `strategy` y `race_delay_ms` (← `RESERVATION_RACE_DELAY`, ya presente en el `.env.example`).

### Flujo de `AtomicReservationService::reserve()`

Dentro de `DB::transaction(fn () => …, attempts: 3)`:

1. **UPDATE condicional** (statement único, sin lectura previa):
   ```sql
   UPDATE products SET stock = stock - :q, updated_at = :now
    WHERE id = :id AND stock >= :q
   ```
2. `affectedRows === 1` → éxito. Releer `stock` del producto **dentro de la misma transacción** (se mantiene el lock exclusivo, así que es el valor exacto post-decremento) → `remaining_stock`. `status = confirmed`.
3. `affectedRows === 0` → desambiguar **sólo en esta rama de fallo** (nunca en el camino feliz) con un `SELECT`:
   - producto inexistente → lanzar `ProductNotFoundException` (revierte).
   - producto existe → `status = rejected`, `remaining_stock` = stock actual.
4. **INSERT** de la reserva con los valores finales.

**Orden UPDATE→INSERT, justificación:** evita una columna nullable y un segundo UPDATE. Si dos peticiones concurrentes traen el mismo `request_id`, ambas decrementan (serializadas por el lock de fila), pero la perdedora choca contra el `UNIQUE` y **el rollback de la transacción deshace su decremento**. Efecto neto: un único descuento.

### Manejo del duplicado — el punto delicado

`Illuminate\Database\UniqueConstraintViolationException` se captura **fuera** del closure de `DB::transaction`, nunca dentro. Razón: tras el rollback hace falta un **snapshot nuevo** para poder leer la fila que ganó la carrera; bajo REPEATABLE READ, una lectura dentro de la transacción abortada no la vería.

Al recuperar la reserva existente se comparan `product_id` y `quantity` con los del request entrante — están ya en la fila, no hace falta guardar un fingerprint del payload. Si difieren → `IdempotencyConflictException` (409). Si coinciden → `ReservationResult(wasReplay: true)`.

**Por qué InnoDB hace esto correcto:** el INSERT de la perdedora se **bloquea** en la comprobación de clave duplicada hasta que la ganadora hace commit o rollback. Cuando llega el 1062, la fila ganadora ya está comprometida y es legible.

### Nivel de aislamiento

Se deja REPEATABLE READ (default). Es seguro porque el `UPDATE … WHERE stock >= :q` hace *current read*, no lectura de snapshot. **Comentar esto en el código** — es exactamente el punto donde la intuición falla.

## 3. Capa HTTP

- `routes/api.php`: `Route::post('reservations', …)->name('reservations.store')`.
- **`StoreReservationRequest`**: `request_id` requerido, string, ≤64, `uuid` (o regex si se admiten otros formatos); `product_id` requerido, entero, `exists:products,id`; `quantity` requerido, entero, `min:1`. El `exists` es un 422 temprano con buen mensaje, **no** la garantía real — ésa la dan la FK y el `affectedRows`.
- **`ReservationController::store`** delgado: invoca la strategy, mapea el resultado.
- **`ReservationResource`**: `id, request_id, product_id, quantity, remaining_stock, status, created_at`.

**Códigos de respuesta**

| Caso | Código | Notas |
|---|---|---|
| Confirmada (nueva) | `201` | |
| Rechazada por stock (nueva) | `409` | body = la reserva `rejected` persistida |
| Replay de confirmada | `200` | + header `Idempotency-Replayed: true` |
| Replay de rechazada | `409` | + mismo header |
| `request_id` reusado con otro payload | `409` | código de error distinto en el body |
| Producto inexistente | `404` | |
| Validación | `422` | |
| Lock wait agotado tras 3 intentos | `503` | + `Retry-After` |

Mapeo en `bootstrap/app.php → withExceptions()`, con envelope de error uniforme (`{"error": {"code", "message"}}`). Ninguna llamada externa, evento ni job dentro de la transacción.

## 4. Entorno de test — corregir antes de escribir tests

`phpunit.xml` fuerza hoy `DB_CONNECTION=mysql` con `DB_DATABASE=":memory:"`, que no existe en MySQL; y `.env` sigue en `DB_CONNECTION=sqlite`. **SQLite invalidaría por completo la sección 5**: serializa las escrituras a nivel de fichero y haría pasar incluso la implementación naive.

- `phpunit.xml`: `DB_DATABASE=api-reservations-test`, `DB_HOST=mysql`, quitar `:memory:`.
- Crear esa base en el arranque de MySQL (script en `config/mysql/initdb/` o comando artisan de setup).
- **`pm.max_children = 20`** en `config/php/www.conf` acota la concurrencia real en vuelo: pedir 50 peticiones simultáneas produce 20 en paralelo y 30 encoladas. Subirlo a ~60 para el arnés, o dimensionar la prueba a ≤20. Sin este ajuste el test da verde sin haber solapado.
- `php.ini` menciona `pcntl_fork`, pero **la extensión `pcntl` no está instalada** en `config/php/Dockerfile`. Usar `curl_multi` / `proc_open` (sin tocar la imagen), o añadir `pcntl` a los `docker-php-ext-install`.

## 5. Tests

### Feature, secuenciales (`tests/Feature/`)
Con `RefreshDatabase`, sobre MySQL:
- 422 por cada regla de validación (falta `request_id`, `quantity` = 0 y negativa, `product_id` inexistente).
- Camino feliz: 201, `stock` decrementado exactamente, `remaining_stock` == stock posterior.
- Stock insuficiente: 409, fila `rejected` persistida, **stock intacto**.
- Replay idéntico: 200 + header, **una sola fila**, stock sin cambios respecto al primer POST.
- Replay con payload distinto: 409 de conflicto.
- `quantity` exactamente igual al stock → 201 y stock final 0 (frontera del `>=`).

### Concurrencia (`tests/Feature/ConcurrencyTest.php` + comando artisan)

**`php artisan reservations:stress --concurrency= --quantity= --strategy=`**: prepara el producto, lanza N procesos hijo con peticiones HTTP **reales** contra nginx (conexión propia cada uno), recoge códigos y emite JSON con el resultado. Un bucle PHP en un solo proceso sería secuencial y no probaría nada.

El test **no puede usar `RefreshDatabase`**: su transacción envolvente oculta los datos a los procesos externos. Sembrar con datos comprometidos y limpiar a mano.

**Aserciones sobre invariantes**, con `stock=10` y 50 peticiones de `quantity=1`:
- `stock` final `== 0`, nunca negativo.
- Exactamente 10 respuestas `201` y 40 `409`.
- **Cero 5xx** — un deadlock sin manejar es un fallo aunque el stock quede consistente.
- `SUM(quantity)` de las confirmadas `== stock_inicial - stock_final`.
- Los `remaining_stock` de las confirmadas forman la secuencia `9…0`, **sin repetidos ni huecos**. Es el detector fuerte: dos reservas con el mismo `remaining_stock` son prueba directa de un *lost update* que las demás aserciones no ven. Afirmar sólo `stock >= 0` sería un falso positivo garantizado.

**Test de idempotencia concurrente**: N peticiones simultáneas con el **mismo** `request_id` → exactamente 1 fila, el stock baja exactamente una vez, las N respuestas apuntan al mismo `reservation.id`. Un `SELECT-then-INSERT` pasaría la versión secuencial y fallaría ésta.

**Test de validez del arnés** (`@group race-demo`): el mismo arnés contra `NaiveReservationService` con `RESERVATION_RACE_DELAY=100` debe **fallar de forma determinista** con sobreventa. Un arnés que nunca se ha visto fallar no acredita nada; este test es lo que da valor a los dos anteriores. Se marca como test que *espera* la sobreventa, para que la suite quede verde.

## 6. Archivos a crear/tocar

**Nuevos**
```
database/migrations/*_create_products_table.php
database/migrations/*_create_reservations_table.php
database/factories/{Product,Reservation}Factory.php
app/Models/{Product,Reservation}.php
app/Enums/ReservationStatus.php
app/Domain/Reservations/{ReservationStrategy,AtomicReservationService,NaiveReservationService,ReservationResult}.php
app/Exceptions/{ProductNotFound,IdempotencyConflict}Exception.php
app/Http/Controllers/Api/ReservationController.php
app/Http/Requests/StoreReservationRequest.php
app/Http/Resources/ReservationResource.php
app/Console/Commands/StressReservations.php
config/reservations.php
tests/Feature/{StoreReservationTest,ConcurrencyTest}.php
```

**Modificados**
```
routes/api.php                    ruta POST
bootstrap/app.php                 withExceptions -> mapeo HTTP
app/Providers/AppServiceProvider.php  binding de la strategy
database/seeders/DatabaseSeeder.php
phpunit.xml                       BD de test MySQL real (quitar :memory:)
config/php/www.conf               pm.max_children para el arnés
../../config/php/.env(.example)   RESERVATION_STRATEGY
```

## 7. Verificación end-to-end

```bash
docker compose up -d --build
docker compose exec api_reservations php artisan migrate:fresh --seed

# camino feliz
curl -sX POST localhost:8010/api/reservations \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"request_id":"<uuid>","product_id":1,"quantity":2}' -i

# idempotencia: repetir el mismo comando -> 200 + Idempotency-Replayed: true,
# stock sin cambios

docker compose exec api_reservations php artisan test
docker compose exec api_reservations php artisan reservations:stress --concurrency=50 --strategy=atomic
docker compose exec api_reservations php artisan reservations:stress --concurrency=50 --strategy=naive  # debe sobrevender
docker compose exec api_reservations ./vendor/bin/pint --test
```

Verificación de que el lock existe donde se cree: durante el stress, `SELECT * FROM performance_schema.data_locks` debe mostrar el `X` lock sobre la fila de `products`.