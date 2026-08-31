# API Reservas de Inventario - Prueba Técnica PHP/MySQL El Tiempo

`POST /api/reservations` descuenta stock de un producto de forma atómica e idempotente. El problema real a abarcar en la prueba no es el CRUD, es que dos peticiones simultáneas lean el mismo stock y ambas escriban, y que un reintento del cliente cree una reserva duplicada. Las dos garantías viven en MySQL y no solo en PHP.

## Requisitos

- Docker Engine 24+
- Docker Compose v2
- Puertos `8010` (Nginx), `3310`(MySQL) y `9010` (App Laravel) libres.

## Instalación y Levatamiento de Entorno

### 1. Clonar Repositorio

```bash
git clone https://github.com/azambrano-dev/reservations-api-prueba-eltiempo
cd reservations-api-prueba-eltiempo
```
### 2. Configuración de entorno

Antes de levantar nada, copia los dos `.env.example` y rellena las credenciales:

```bash
cp config/mysql/.env.example config/mysql/.env
cp config/php/.env.example config/php/.env
```

**`config/mysql/.env`** — las credenciales con las que arranca el contenedor de MySQL:

```bash
MYSQL_ROOT_PASSWORD={contraseña_root}
MYSQL_USER=eltiempo
MYSQL_PASSWORD={contraseña_db_app}
MYSQL_DATABASE=api-reservations
TZ="America/Bogota"
```
**`config/php/.env`** — el `.env` de Laravel. Las de conexión tienen que coincidir con las de arriba:

```bash
DB_CONNECTION=mysql
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=api-reservations
DB_USERNAME=eltiempo
DB_PASSWORD=

# Estrategia de reserva: 'atomic' (correcta)
# o 'naive' (lee-espera-escribe, incorrecta a proposito, solo para pruebas).
RESERVATION_STRATEGY=atomic

# Retardo artificial en milisegundos entre la lectura y la escritura del stock.
# Solo se aplica en NaiveReservationService y sirve para ensanchar
# la ventana de carrera y hacer la sobreventa reproducible.
# Vacio o 0 = uso normal.
RESERVATION_RACE_DELAY=100
```

### 3. Levantar Servicios Docker

```bash
docker compose up -d --build
docker compose exec api_reservations composer install
docker compose exec api_reservations php artisan migrate --seed
```
La API queda expuesta en `http://localhost:8010`.

### 4. Base de datos

Las migraciones crean `products` y `reservations`. El seeder deja un producto con stock conocido para las pruebas.

La base de test es aparte (`api-reservations-test`) y la crea el init de MySQL al levantar el contenedor. Todo corre contra MySQL 8, no SQLite: SQLite serializa las escrituras a nivel de fichero y haría pasar hasta una implementación incorrecta.

## Pruebas

```bash
docker compose exec api_reservations php artisan test

# escenario del punto 7: stock 1, dos solicitudes de 1 unidad
docker compose exec api_reservations php raceDemo.php
docker compose exec api_reservations php raceDemo.php --implementation=naive --delay=50
```

Hay dos opciones de test de concurrencia a propósito. `raceDemo.php` *(implementada manualmente)* llama al servicio directamente con `pcntl_fork`, sin pasar por HTTP, así que si falla el problema está en el servicio y en ningún otro sitio. `ConcurrencyTest` lanza peticiones reales contra nginx y verifica que el camino completo aguanta lo mismo.

La ejecutada con `--implementation=naive --delay=50` **debe sobrevender**.

## Ambigüedades del enunciado y cómo las resolví

- **Regla 2 contra Regla 4.** La 2 dice que un `request_id` repetido devuelve la reserva previa; la 4 lo lista como error de validación. Son cosas opuestas. Resolví con la práctica habitual de llaves de idempotencia: mismo `request_id` con mismo payload devuelve la reserva existente, con payload distinto devuelve 409.

- **`remaining_stock` en el replay.** Devuelvo el valor almacenado, no el actual. Si la reserva original dejó 7 y desde entonces otros clientes bajaron el stock a 2, el replay sigue devolviendo 7: el cliente que reintenta pide el resultado de su operación, no el estado del mundo. Esto es lo que justifica ampliar el esquema con esa columna.

- **`status` aparece en la respuesta de ejemplo pero no en el esquema mínimo.** Persisto la reserva rechazada por stock. Si no lo hiciera, el endpoint solo sería idempotente en el camino feliz: el mismo `request_id` daría 409 ahora y 201 más tarde si entra stock. Con la fila persistida, la respuesta es siempre la misma.

- **Unicidad de `request_id`.** No hay concepto de cliente ni tenant en el enunciado, así que la unicidad es global. El cliente es responsable de generar identificadores que no colisionen.

## Decisiones técnicas tomadas

#### **1. `SELECT … FOR UPDATE` en lugar de `UPDATE … WHERE stock >= :q`.** 
**Motivo:** El UPDATE condicional es más eficiente y tampoco está mal, pero con `affectedRows = 0` no distingo "producto inexistente" de "stock insuficiente" sin una consulta extra, y esa consulta caería fuera del lock. Como persisto la reserva rechazada con su `remaining_stock`, necesito el stock real en el momento de decidir. El `FOR UPDATE` me da existencia, stock y bloqueo en un solo paso, y vale para las dos ramas.

---

#### **2. `UNIQUE(request_id)`**
**Motivo:**  es la garantía de idempotencia. La consulta previa en PHP es una optimización para no abrir transacción en un reintento. Si quito la consulta, el sistema sigue siendo correcto; si quito el índice único, deja de serlo.

---

#### 3.  **`stock UNSIGNED`.** 
**Motivo:** Convierte "nunca puede haber stock negativo" en algo que la base impone, no que el código promete. Si la lógica fallara, MySQL aborta la operación en vez de corromper el inventario.

--- 

#### 4. **Sin reintentos (`attempts: 1`).** 
**Motivo:** Cada transacción bloquea una sola fila de `products` y siempre en el mismo orden, así que no hay deadlock posible en este diseño. El lock wait timeout sí puede darse, pero reintentarlo solo alarga la espera de un cliente que ya lleva diez segundos bloqueado: sale 503 con `Retry-After`. Con reservas multi-línea habría que bloquear ordenando por `product_id` y reconsiderar esto.

---

#### 5. **El `FormRequest` valida forma, no estado.** 
**Motivo:** Tipos, rangos y campos obligatorios. La existencia del producto y la disponibilidad de stock se comprueban dentro de la transacción, que es el único punto donde la respuesta no puede quedar obsoleta entre la validación y la escritura.

---

#### 6. **`request_id` sin formato impuesto.** 
**Motivo:** Es un identificador opaco del cliente. El ejemplo del enunciado usa `REQ-2026-0001`, que no es un UUID.

---

#### 7. **Laravel por velocidad de setup; la lógica crítica en SQL explícito.**
**Motivo:** Nada de `$product->decrement()`. La garantía tiene que ser auditable leyendo un archivo, no depender de lo que haga el ORM por dentro.

---

#### 6. **Sin DDD ni capa de repositorios.** 
**Motivo:** Trabajo habitualmente con arquitectura modular y DDD; para este alcance sería sobre-ingeniería. La lógica crítica está aislada en un servicio para que la garantía transaccional se lea en un solo sitio. La interfaz que hay existe solo para poder intercambiar la implementación ingenua desde config.

## Decisiones Fuera del Alcance

Acá describo de manera resumida lo que mantuve fuera del alcance para mantener la simplicidad dado el enunciado de la prueba, pero que **tomaría en cuenta** en caso de  escalar este sistema de manera más robusta.

- `GET /reservations/{id}` resolviendo también por `request_id`: es la contraparte natural de la llave de idempotencia, porque un cliente que perdió la respuesta debería poder consultarla y no solo reenviar el POST. Es lo primero que añadiría.

- Reserva en dos fases con expiración (hold → confirm/release), que cambia el modelo entero: haría falta `expires_at`, separación entre stock físico y disponible, y un job de liberación.

- Ledger de movimientos de stock. Es lo correcto en producción y es el patrón de doble entrada que uso a diario, pero aquí sobra.

---