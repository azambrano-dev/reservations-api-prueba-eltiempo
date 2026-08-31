# API de reservas de inventario — plan de implementación

## Contexto

`reservations-api/src` es un esqueleto Laravel 12 limpio (sólo el `User` por defecto, `routes/api.php` vacío, migraciones base). La infraestructura ya está montada: nginx + php-fpm 8.3 + MySQL 8.4 en Docker, con `innodb-lock-wait-timeout=10`

Hay que construir `POST /api/reservations`, que descuenta stock de un producto de forma **atómica** e **idempotente**. El riesgo real del ejercicio no es el CRUD: es que dos peticiones simultáneas lean el mismo stock y ambas escriban, produciendo sobreventa (*lost update*), y que un reintento del cliente cree una reserva duplicada. El plan resuelve ambos a nivel de base de datos —no de código PHP— y aporta un arnés de concurrencia que **demuestra** el fallo en una implementación naive antes de acreditar la implementación correcta.

## Decisiones cerradas

| Decisión | Elección |
|---|---|
| Exclusión mutua | `SELECT … FOR UPDATE` sobre la fila del producto |
| SoftDeletes | **Ninguno**, en ninguna entidad (rectifica el enunciado inicial) |
| Modelo de reserva | Confirmación inmediata, sin holds ni TTL |
| Rechazo por stock | Se persiste como fila `status=rejected` |
| Endpoints | Sólo `POST /api/reservations` |
| Idempotencia | `UNIQUE(request_id)` + violación de constraint como señal |
| Reintentos | **`attempts: 1`** — sin reintento; lock wait → 503 |

**Por qué lock pesimista y no `UPDATE … WHERE stock >= :q`:** el UPDATE condicional es más eficiente y tampoco está mal, pero no da lo que hay que persistir. Con `remaining_stock` en la tabla —y sobre todo en la rama `rejected`— hace falta el stock **real** en el momento de la decisión; el UPDATE condicional obliga a un `SELECT` en otra sentencia, cuyo valor puede quedar obsoleto antes del INSERT. El `FOR UPDATE` mantiene el lock durante toda la transacción, así que el valor leído sigue siendo exacto al escribir la reserva, en ambas ramas.

Al no haber SoftDeletes: `UNIQUE(request_id)` plano sin ambigüedad de NULLs, y ni el SELECT ni el UPDATE necesitan filtrar `deleted_at`.

---

## 1. Esquema

**`database/migrations/*_create_products_table.php`**
- `id`, `name` (string 255), `stock` (**`unsignedInteger`**, default 0), `timestamps()`.
- `unsignedInteger` es la **segunda capa de defensa**: la primera es el `FOR UPDATE` + la comprobación en el servicio; si esa lógica se rompiera, en `sql_mode` estricto un decremento bajo cero aborta con 1264 en vez de hacer wrap-around. El invariante `stock >= 0` queda garantizado por el esquema, no sólo por el código.

**`database/migrations/*_create_reservations_table.php`**
- `id`
- `request_id` string(64) **`->unique()`** ← el mecanismo de idempotencia
- `product_id` `foreignId()->constrained()->restrictOnDelete()`
- `quantity` `unsignedInteger`
- `remaining_stock` `unsignedInteger` — snapshot de auditoría del stock *después* de la operación (en `rejected`, el stock real sin tocar). **No autoritativo**: nada lo lee para decidir. Documentar en el modelo.
- `status` string(16) con cast a enum PHP `ReservationStatus { Confirmed, Rejected }`
- `timestamps()`

**Factories + seeder** (`ProductFactory`, `ReservationFactory`, `DatabaseSeeder`) con un producto de stock conocido para el arnés de concurrencia.

## 2. Servicios

`app/Services/` (sin carpeta `Domain/`: la interfaz y el DTO se quedan porque hacen falta para intercambiar la implementación naive por config, pero sin prometer una arquitectura que este código no tiene):

- **`ReservationStrategy`** (interface): `reserve(string $requestId, int $productId, int $quantity): ReservationResult`
- **`AtomicReservationService`** — implementación real.
- **`NaiveReservationService`** — read → `usleep(race_delay)` → write, **sin** `FOR UPDATE`. Existe **sólo** para que el arnés demuestre la sobreventa. Se enlaza por config.
- **`ReservationResult`** (readonly DTO): `reservation`, `wasReplay: bool`.
- Excepciones en `app/Exceptions/`: `ProductNotFoundException`, `IdempotencyConflictException`.

**Binding** en `AppServiceProvider` según `config('reservations.strategy')` ← `RESERVATION_STRATEGY=atomic|naive`. Nuevo `config/reservations.php` con `strategy` y `race_delay_ms` (← `RESERVATION_RACE_DELAY` ).

### Flujo de `AtomicReservationService::reserve()`

Dentro de `DB::transaction(fn () => …, attempts: 1)`:

1. **`SELECT id, stock FROM products WHERE id = :id FOR UPDATE`** — toma el lock exclusivo sobre la fila por PK. A partir de aquí ninguna otra transacción puede leer-para-escribir ni modificar esa fila.
2. Fila inexistente → `ProductNotFoundException` (404).
3. `stock >= quantity` → `UPDATE products SET stock = stock - :q`; `remaining_stock = stock - quantity`, `status = confirmed`.
4. `stock < quantity` → sin tocar el producto; `remaining_stock = stock` (valor real, leído bajo el lock), `status = rejected`.
5. **INSERT** de la reserva con los valores finales.

El lock se libera en el COMMIT, de modo que los valores de los pasos 3 y 4 siguen siendo válidos cuando se escribe la fila de `reservations`. Ésa es toda la razón de ser del `FOR UPDATE` aquí.

**Orden SELECT→UPDATE→INSERT:** si dos peticiones concurrentes traen el mismo `request_id`, ambas pasan por el lock de forma serializada y ambas decrementan, pero la perdedora choca contra el `UNIQUE` y **el rollback de la transacción deshace su decremento**. Efecto neto: un único descuento.

### `attempts: 1` — decisión explícita

Comentar en el servicio, para que se lea como decisión propia y no como omisión:

- **Deadlock (1213) es imposible aquí**: cada transacción bloquea una única fila de `products`, siempre en el mismo orden. No hay ciclo de espera que construir. Si en el futuro entrasen reservas multi-línea, habría que bloquear las filas ordenadas por `product_id` y reconsiderar los reintentos.
- **Lock wait timeout (1205) sí puede darse** (el compose lo fija en 10s). Reintentar sólo alarga la espera de un cliente que ya lleva 10 segundos bloqueado. Se traduce a **503 + `Retry-After`** con mensaje de reintentar más tarde, y se devuelve el control de inmediato.

### Nivel de aislamiento

Se deja REPEATABLE READ (default). El `SELECT … FOR UPDATE` es *locking read*: lee la última versión comprometida, no el snapshot de la transacción. **Comentar esto en el código** — es exactamente el punto donde la intuición falla, y es lo que hace que un `SELECT` normal en su lugar sea incorrecto bajo RR.

### Manejo del duplicado — el punto delicado

`Illuminate\Database\UniqueConstraintViolationException` se captura **fuera** del closure de `DB::transaction`, nunca dentro. Razón: tras el rollback hace falta un **snapshot nuevo** para poder leer la fila que ganó la carrera; bajo REPEATABLE READ, una lectura dentro de la transacción abortada no la vería.

Al recuperar la reserva existente se comparan `product_id` y `quantity` con los del request entrante — están ya en la fila, no hace falta guardar un fingerprint del payload. Si difieren → `IdempotencyConflictException` (409). Si coinciden → `ReservationResult(wasReplay: true)`.

**Por qué InnoDB hace esto correcto:** el INSERT de la perdedora se **bloquea** en la comprobación de clave duplicada hasta que la ganadora hace commit o rollback. Cuando llega el 1062, la fila ganadora ya está comprometida y es legible.

## 3. Capa HTTP

- `routes/api.php`: `Route::post('reservations', …)->name('reservations.store')`.
- **`StoreReservationRequest`** — validación puramente sintáctica:
  - `request_id`: `required|string|max:64`. Es un identificador **opaco** del cliente; no se impone formato UUID.
  - `product_id`: `required|integer|min:1`. **Sin `exists:products,id`**: esa regla devolvería 422 para un producto inexistente en vez del 404 definido, y la existencia ya la resuelve el `SELECT … FOR UPDATE` sin una query extra.
  - `quantity`: `required|integer|min:1`.
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
| Producto inexistente | `404` | desde el `FOR UPDATE`, no desde la validación |
| Validación | `422` | |
| Lock wait timeout | `503` | + `Retry-After`, sin reintento |

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
- 422 por cada regla de validación (falta `request_id`, `request_id` > 64 chars, `quantity` = 0 y negativa, `product_id` no entero).
- **404** con `product_id` inexistente (verifica que la ruta de error es la del servicio, no la del validador).
- Camino feliz: 201, `stock` decrementado exactamente, `remaining_stock` == stock posterior.
- Stock insuficiente: 409, fila `rejected` persistida con **`remaining_stock` == stock actual del producto**, stock intacto.
- Replay idéntico: 200 + header, **una sola fila**, stock sin cambios respecto al primer POST.
- Replay con payload distinto: 409 de conflicto.
- `quantity` exactamente igual al stock → 201 y stock final 0 (frontera de la comparación).

### Concurrencia (`tests/Feature/ConcurrencyTest.php` + comando artisan)

**`php artisan reservations:stress --concurrency= --quantity= --strategy=`**: prepara el producto, lanza N procesos hijo con peticiones HTTP **reales** contra nginx (conexión propia cada uno), recoge códigos y emite JSON con el resultado. Un bucle PHP en un solo proceso sería secuencial y no probaría nada.

El test **no puede usar `RefreshDatabase`**: su transacción envolvente oculta los datos a los procesos externos. Sembrar con datos comprometidos y limpiar a mano.

**Aserciones sobre invariantes**, con `stock=10` y 50 peticiones de `quantity=1`:
- `stock` final `== 0`, nunca negativo.
- Exactamente 10 respuestas `201` y 40 `409`.
- **Cero 5xx** — con `attempts: 1`, un 503 por lock wait sería un fallo del dimensionado del arnés y hay que verlo, no absorberlo.
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
app/Services/{ReservationStrategy,AtomicReservationService,NaiveReservationService,ReservationResult}.php
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
routes/api.php                        ruta POST
bootstrap/app.php                     withExceptions -> mapeo HTTP
app/Providers/AppServiceProvider.php  binding de la strategy
database/seeders/DatabaseSeeder.php
phpunit.xml                           BD de test MySQL real (quitar :memory:)
config/php/www.conf                   pm.max_children para el arnés
../../config/php/.env(.example)       RESERVATION_STRATEGY
```

## 7. Verificación end-to-end

```bash
docker compose up -d --build
docker compose exec api_reservations php artisan migrate:fresh --seed

# camino feliz
curl -sX POST localhost:8010/api/reservations \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"request_id":"req-001","product_id":1,"quantity":2}' -i

# idempotencia: repetir el mismo comando -> 200 + Idempotency-Replayed: true,
# stock sin cambios

# producto inexistente -> 404, no 422
curl -sX POST localhost:8010/api/reservations \
  -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d '{"request_id":"req-002","product_id":999999,"quantity":1}' -i

docker compose exec api_reservations php artisan test
docker compose exec api_reservations php artisan reservations:stress --concurrency=50 --strategy=atomic
docker compose exec api_reservations php artisan reservations:stress --concurrency=50 --strategy=naive  # debe sobrevender
docker compose exec api_reservations ./vendor/bin/pint --test
```

Verificación de que el lock se toma donde se cree: durante el stress, `SELECT * FROM performance_schema.data_locks WHERE OBJECT_NAME = 'products'` debe mostrar el `X` lock `RECORD` sobre la fila del producto, y transacciones en espera sobre ella.