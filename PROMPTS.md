# Promts

De acuerdo con el requisito de uso de IA, la siguiente prueba se realizo usando los modelos Opus 5 y Sonnet 5 de Claude Code. 

Los promts van tal cual los ecribí en Claude. No copiaré las respuesta completas solo los puntos importantes y decisiones que tome sobre estos.

## Promt Inicial

```bash
Actúa como un desarrollador backend senior, experto en PHP/Laravel y MySQL.

Contexto: Necesito implementar una API para reservas de invetario de productos. Los requisitos minimos a tener en cuenta son los siguiente:
- Ruta POST api/reservations.
- Manejo de concurrencia e idempotencia.
- Validación de existencia del producto y cantidad de stock.
- Stock no puede quedar negativo nunca.
- Las entidades de inicio son: products (id, name, stock, laravel timestamps con softdelete) y reservations (id, request_id, product_id, quantity, remaining_stock, status, laravel timestamps con softdelete)

De acuerdo con los requisitos anteriores, dame un resumen y plan de implementación tomando en cuenta lo siguiente:

1. Que huecos pueden haber en la especificación de los requerimientos, que cosas adicionales tendría que tener en cuenta y que necesita definición de mi parte.
2. Decisiones de diseño que debo cerrar antes de escribir cualquier linea de código o lógica de negocio.
3. Que hay que tomar en cuenta para que la concurrencia sea válida y no arroje falsos positivos.

Para está primera respuesta no escribas código y asume que tenemos el mismo rol/conocimientos y evita darme explicaciones innecesarias.

Respuesta esperada: lenguaje 100% técnico por cada punto a desarrollar sin ninguna introducción y resumen innecesarios.
```

### Análisis

**Qué detectó el modelo Opus:**
1. La colisión cuando el "request_id" llega con un payload distinto con sus tres opciones de salida posibles lo que obligaría a persistir una huella en el cuerpo de la petición.
2. Un hueco que no tuve en mente en mi planifición inicial sobre las reservas rechazadas, que si no peristen el endpoint deja de ser idempotente en la rama de fallo. Que un reintento después de una excepción por falta de stock vuelve a evaluar y puede devolver un 201 como registro exitoso.
3. Que MySQL no hace colisionar los NULL en un indíce unico, dejando pasar los duplicados vivos en la validación de un UNIQUE (request_id, deleted_at).

**Qué no detecto:**

La semántica del "remaining_stock" en el reintento. El modelo indíca que es un scapshot de auditoría, pero no lo tomó en cuenta en ningún momento para el caso en que, si al reenviar la solcitud, se debe devolver al valor del stock aquí almacenado y no el actual del global.


## Promt 2 - Correciones al plan inicial

```bash
Realiza cuatro ajustes al plan antes de que ejecutar e implementar:

1. Cambio la exclusión mutua a SELECT ... FOR UPDATE. El motivo es la persistencia de la reserva rechazada, en la cual necesito el stock real para poder escribir el remaining_stock real. Con el UPDATE condicional te obliga a hacer un SELECT en otra sentencia cayendo en el error de que el valor real puede estar obsoleto al insertar. El UPDATE condicional es más eficiente y no está mal, simplemente no me da lo que necesito persistir y mantengo el stock con UNSIGNED como segunda capa de defensa para esa validación.

2. Baja los attempts a 1. Esto porque cada transacción bloquearía una única fila de products con el mismo orden cada vez, por lo que no habría un deadlock posible. El bloqueo por tiempo de espera si puede darse, pero hacer un reintento es alargar el tiempo de espera del cliente, mando esto último a 503 con un mensaje de "reintentar luego", esto ponlo como un comentario en el servicio para que se lea como decisión propia.

3. Quita la relga del "exists:products,id". Con esa regla un producto inexistente sale por 422 en vez del 404 que definiste, y la existencia ya la resolvemos con el SELECT ... FOR UPDATE. Quita también la regla uuid de request_id:. Este campo es un identificador opaco del cliente así que con required|string|max:64 es suficiente.

4. Renombra app/Domain/Reservations/ a app/Services/. La interfaz y el DTO se
quedan, el motivo es que necesito intercambiar la implementación naive por
config. Lo que sobra es la etiqueta Domain, que promete una arquitectura que
este código no tiene y no entra en el alcance descrito.
```


## Promt 3 - Correción adicional y ejecución
```bash
Un último detalle a corregir y ejecutar el plan: 
- En el paso 3 pon "SET stock = :remaining" en vez de "stock - :q", que ya tienes el valor calculado bajo el lock y así se ve que sale del mismo sitio que el remaining_stock.
```
---
### **A tener en cuenta** 
El alcance de los promts anteriores sobre los planes creados y reajustados por el modelo están ubicados en **`.claude/plans`**.

---

## Promt 4 - Revisión de solución

Este último promt lo optimice mediante el modelo Opus en un chat independiente fuera del proyecto para que tuviera mayor alcance en la validación.

```bash
Quiero una revisión adversarial de lo que llevamos. Te paso solo la lógica
crítica: "AtomicReservationService", las dos migraciones y "ConcurrencyTest".

Búscame el caso que rompe esto en producción bajo carga, no me confirmes que
está bien. Si en algún punto no encuentras nada real, dímelo en vez de
rellenar con cosas menores.

Mira sobre todo:

- Concurrencia: interleavings concretos de dos o más peticiones a la vez que
  acaben en sobreventa, stock negativo, reservas huérfanas o lost update.
- Transacciones: hasta dónde llega el lock y cuánto dura, qué pasa si salta
  una excepción en cada punto de la secuencia, cómo se comporta el rollback,
  si hay deadlock posible.
- Idempotencia: qué pasa con dos peticiones simultáneas del mismo request_id,
  y qué queda en la base si la primera se cayó a mitad de la transacción.
- Integridad: en qué estados pueden quedar products y reservations
  descuadrados entre sí.

De cada hallazgo quiero, en este orden: severidad (crítica, media o
cosmética); la secuencia exacta que lo reproduce, como timeline entre proceso
A y B con tiempos relativos; la corrección mínima; y si es un fallo real del
código, una mejora válida pero fuera del alcance de esta prueba, o una
preferencia de estilo.

Si me propones meter Redis, colas o cualquier cosa externa, justifícame por
qué no se resuelve solo con MySQL. Tampoco lo descartes de entrada si de
verdad hace falta.

No reescribas nada. Diagnóstico, no parche.
```

## Respuesta de la revisión IA

He mirado el núcleo con intención de romperlo. **El mecanismo de exclusión mutua e idempotencia es correcto**: no consigo construir ningún interleaving que produzca sobreventa, stock negativo, reserva huérfana ni lost update. El lock `X` sobre la fila de `products` serializa todo lo que comparte `product_id`, y el `UNIQUE(request_id)` + captura fuera de la transacción resuelve bien el duplicado, incluido el caso de que el ganador haga rollback. Los hallazgos reales están en el **mapeo de errores**, los **límites de entrada** y la **higiene del arnés**, no en la lógica de concurrencia.

### Hallazgos
--- 

#### 1. `quantity` sin cota superior → 500 con entrada no autenticada

**Severidad: media.**

`StoreReservationRequest` valida `quantity` como `required|integer|min:1`, sin `max`. La columna `quantity` es `INT UNSIGNED` (máx. 4 294 967 295).

Secuencia (una sola petición, no hace falta carga):
- `t0` — cliente: `POST` con `{"request_id":"x","product_id":1,"quantity":9999999999}`.
- `t1` — validación: `filter_var(FILTER_VALIDATE_INT)` en 64 bits acepta el valor; `min:1` pasa. 422 no salta.
- `t2` — servicio: `$stock (100) >= 9999999999` es falso → rama `rejected` → `Reservation::create(['quantity' => 9999999999, ...])`.
- `t3` — MySQL en `sql_mode` estricto: `INSERT` → error 1264 (*Out of range value*), SQLSTATE 22003 → `QueryException`.
- `t4` — `bootstrap/app.php` sólo mapea `errorInfo[1] === 1205`. Este es 1264 → cae al render por defecto → **HTTP 500**.

Cualquiera puede provocar 500 y ruido en logs con un `curl`. La existencia inválida de producto sale por 404 limpio; una cantidad absurda no.

**Corrección mínima:** añadir `max` a la regla de `quantity` (el techo de la columna, o un límite de negocio como `max:1000000`).

**Clasificación:** fallo real del código. El enunciado pide explícitamente "validación de ... cantidad".

---

#### 2. Deadlock (1213) y cualquier `QueryException` distinta de 1205 → 500 sin red de seguridad

**Severidad: media.**

El comentario del servicio afirma que el deadlock "no puede ocurrir aquí: cada transacción bloquea una sola fila de `products` por PK y siempre en el mismo orden". La conclusión (no hay deadlock en el camino feliz con productos existentes) es correcta, pero **no por el motivo que da**: se sostiene porque la adquisición de locks es monótona y de un solo salto —la transacción sólo espera una vez, en el `SELECT ... FOR UPDATE`, y después opera sobre filas que ya posee o sobre registros de índice nuevos—. En cuanto el esquema evolucione (reservas multilínea, un endpoint de alta de productos, un `FOR UPDATE` sobre el índice secundario) esa garantía desaparece.

Y con `attempts: 1` no hay margen: si un 1213 aparece alguna vez (cambio de esquema, un índice que añada un DBA, diferencias de locking por versión de InnoDB), llega a `bootstrap/app.php`, que **sólo** contempla 1205, y sale como **500**. El arnés afirma `http_5xx === 0` como invariante dura, así que un único deadlock pone la suite en rojo sin diagnóstico.

Secuencia (hipotética, requiere un `FOR UPDATE` adicional que hoy no existe, p. ej. reservas de 2 líneas):
- `t0` — A: `FOR UPDATE` producto 1 (ok). B: `FOR UPDATE` producto 2 (ok).
- `t1` — A: `FOR UPDATE` producto 2 → espera a B.
- `t2` — B: `FOR UPDATE` producto 1 → espera a A. Ciclo.
- `t3` — InnoDB mata a una: 1213 → `attempts:1` la relanza → sin mapear → 500.

**Corrección mínima:** mapear 1213 → 503 + `Retry-After` en `withExceptions()`, junto al 1205 (mismo remedio de cliente). **No** subir `attempts`: haría que también se reintente el 1205, que es justo lo que la decisión de diseño quiere evitar.

**Clasificación:** mezcla. El razonamiento del comentario es una preferencia de estilo a corregir; el 500 no mapeado ante 1213/1264/otros es un fallo real de robustez.

---

#### 3. La rama 404 toma gap locks, no un lock de fila por PK

**Severidad: cosmética (media si algún día se añade alta de productos).**

`Product::query()->lockForUpdate()->find($idInexistente)` bajo REPEATABLE READ no puede bloquear un registro que no existe: InnoDB pone un **gap lock** (o next-key sobre el gap / `supremum`) para impedir inserciones fantasma en ese hueco de la PK.

Consecuencias:
- El comentario "bloquea una sola fila de `products` (por PK)" es incorrecto para toda petición que termine en 404.
- Dos peticiones concurrentes al mismo `product_id` inexistente: los gap locks son mutuamente compatibles, no se bloquean entre sí; cada una lanza `ProductNotFoundException` y hace rollback. Sin daño.
- Un `id` inexistente mayor que el máximo real bloquea el gap hasta `supremum` → **bloquea cualquier `INSERT` en `products` con id superior** mientras dure esa transacción (microsegundos). Hoy no hay endpoint de alta de productos, así que el impacto es nulo dentro del alcance; con uno, un escaneo de IDs altos genera contención sobre la creación de productos y abre la puerta a 1213 (ver hallazgo 2).
- Cada 404 abre `BEGIN`/`ROLLBACK` y maquinaria de locking en vez de un `SELECT 1` barato. Bajo carga abusiva de IDs inválidos, los 404 son más caros de lo necesario.

**Corrección mínima:** corregir el comentario para que refleje el gap lock. Si se añade alta de productos, contar con contención de gap desde las sondas 404 (y revisar `attempts`).

**Clasificación:** preferencia de estilo (el comentario) hoy; fallo real latente si cambia el alcance.

---

#### 4. El retorno de `$product->save()` se ignora

**Severidad: cosmética (latente).**

En la única línea que mantiene el invariante de no-sobreventa:

```php
$product->stock = $remaining;
$product->save();               // <- retorno descartado
$status = ReservationStatus::Confirmed;
```

Si `save()` devolviera `false` (un listener de `saving`/`updating` que retorna `false`), el código sigue: crea la reserva `confirmed` con `remaining_stock = $remaining` pero **sin haber persistido el decremento** → la siguiente petición lee el stock viejo → sobreventa.

Hoy no hay ningún hook de modelo, así que no hay disparador. Es fragilidad, no bug vivo.

**Corrección mínima:** `$product->saveOrFail();` o comprobar el booleano y lanzar.

**Clasificación:** mejora válida fuera del alcance estricto (endurecimiento).

---

#### 5. `request_id` vive en un espacio de nombres global sin identidad de cliente

**Severidad: media (divulgación de información / éxito espurio); discutible si está en alcance.**

No hay autenticación y se quitó la regla `uuid`, así que `request_id` es `required|string|max:64` y su unicidad es global. Dos clientes distintos que usen una clave corta y previsible (`"1"`, `"order-42"`) colisionan.

Secuencia:
- `t0` — Cliente A: `POST {request_id:"order-42", product_id:1, quantity:2}` → 201, reserva #100.
- `t1` — Cliente B (otro sistema, sin relación): `POST {request_id:"order-42", product_id:1, quantity:2}`.
- `t2` — Servicio: `INSERT` choca con `UNIQUE` → `replay()` → `firstOrFail()` trae la #100 → `product_id` y `quantity` coinciden → **200 `Idempotency-Replayed: true`** con el cuerpo de la reserva de A (`ReservationResource` expone `product_id`, `quantity`, `remaining_stock`, `created_at`).
- B cree que su pedido se registró; en realidad recibió el de A. Si los payloads difirieran, B recibiría un **409 `idempotency_conflict`** por una operación que era legítimamente nueva.

Sin integridad rota (el producto se decrementa una vez, hay una reserva), pero sí fuga de datos entre llamantes y semántica engañosa.

**Corrección mínima dentro del alcance:** documentar en el contrato que `request_id` **debe** ser globalmente único (UUID v4), que es justo lo que la regla `uuid` eliminada garantizaba a nivel sintáctico. Solución real requiere un modelo de identidad (clave compuesta `(client_id, request_id)`), fuera de alcance.

**Clasificación:** consecuencia real de una decisión que tomaste a conciencia (quitar `uuid` + no hay auth). La señalo para que la asumas explícitamente, no para revertirla.

---

#### 6. Limpieza del `ConcurrencyTest` sin `finally`, sobre la BD de desarrollo

**Severidad: media (DX/CI), nula para producción.**

`ConcurrencyTest::stress()` fuerza `DB_DATABASE=api-reservations` (la BD que sirve php-fpm, no la de test). `StressReservations` crea un producto, y al final:

```php
if (! $this->option('keep')) {
    Reservation::query()->where('product_id', $product->id)->delete();
    if ($createdProduct) { $product->delete(); }
}
```

Esa limpieza no está en `finally`. Si `Process::timeout(120)` dispara, si CI cancela el job, o si una aserción del propio comando aborta antes, quedan **productos y reservas de estrés huérfanos en la BD de desarrollo**. Si se invoca con `--product=1` (un producto sembrado real), un corte deja su `stock` clavado en el `--stock` forzado (10) en lugar de su valor sembrado (100), y el siguiente test o inspección manual parte de un estado falso.

**Corrección mínima:** `try { ... } finally { limpieza }` en `handle()`; y en `setUp()` del test, barrer por prefijo de nombre (`stress-%`) antes de arrancar.

**Clasificación:** fallo real, en el arnés (no en la API).

---

#### 7. `attempts: 1` + serialización total en una fila + `innodb_lock_wait_timeout = 10s` → flakiness de CI

**Severidad: media (fiabilidad de la suite).**

`test_atomic_strategy_never_oversells_under_load` (50 peticiones, mismo producto) y `test_concurrent_requests_with_same_request_id` (25, mismo producto) afirman recuentos exactos (`http_201 == 10`, `http_409 == 40`, `http_200 == 24`) y `http_5xx == 0`.

Las 50 peticiones se serializan sobre el mismo lock `X`. El techo de concurrencia sostenible del arnés es `~10s / (tiempo por transacción)`. En un runner sano cada transacción es sub-milisegundo y 50 en fila caben de sobra; en un runner cargado, un stall de GC/IO en php-fpm o MySQL empuja al último en la cola más allá de 10s:

- `t0` — 50 handles `curl_multi` disparan; la nº 1 toma el lock.
- `t0+δ` — 2…50 encoladas sobre la fila.
- `tN` — si el procesado acumulado supera 10s, la nº 50 recibe 1205 → 503.
- Resultado: `http_201 + http_409 == 49`, `http_5xx == 1` → falla `sin respuestas 5xx` y los `assertSame` de recuento → **suite roja de forma intermitente**.

Es el precio de `attempts: 1` aflorando como fragilidad de test, no como bug de producción (en producción, 503 + `Retry-After` es la respuesta correcta y buscada).

**Corrección mínima:** para el entorno de test, subir `innodb_lock_wait_timeout` (p. ej. 50s) o acotar `--concurrency`; documentar el techo `timeout/t_tx` como límite del arnés.

**Clasificación:** mejora válida fuera del alcance (robustez de la suite).

---

#### 8. Un run verde del arnés atómico no prueba solapamiento real de N vías

**Severidad: cosmética.**

`curl_multi` añade los handles en bucle y `pm` (si es `dynamic`) arranca workers bajo demanda: las primeras peticiones pueden completarse antes de que existan los 50 workers. Todas las invariantes (`remaining_stock` sin repetidos, `stock_decremented == confirmed_quantity_sum`) sólo tienen valor **si hubo solapamiento**; si no lo hubo, el test pasa siendo vacío. El `test_naive_strategy_oversells_under_load` es la mitigación —demuestra que el arnés *sabe* detectar la sobreventa— y es suficiente, pero conviene tenerlo presente.

**Corrección mínima:** afirmar un suelo de solapamiento observado (marca temporal o contador de transacciones concurrentes vía `performance_schema.data_locks` durante la corrida).

**Clasificación:** preferencia / endurecimiento del arnés.

---

#### 9. El detector `remaining_stock sin repetidos` está acoplado a `quantity min:1`

**Severidad: cosmética.**

El invariante fuerte del arnés —dos confirmadas con el mismo `remaining_stock` prueban un lost update— sólo se sostiene porque cada decremento confirmado bajo el lock es estrictamente monótono decreciente, y eso depende de `quantity >= 1`. Si la regla se relajara a `min:0`, dos confirmadas legítimas con `quantity = 0` compartirían `remaining_stock` y el detector daría un **falso positivo** de lost update.

**Corrección mínima:** comentar la dependencia en `checkInvariants()`, o derivar el "sin repetidos" sólo sobre confirmadas con `quantity > 0`.

**Clasificación:** preferencia de estilo (documentar un acoplamiento).

---

### Lo que está bien (no rellené con esto la lista de arriba)

- **Caída a mitad de transacción:** la caída de conexión / kill del worker dispara ROLLBACK automático de InnoDB → sin decremento, sin fila de reserva, sin huérfano. Si la caída es *después* del COMMIT y antes de la respuesta, el reintento del cliente con el mismo `request_id` entra por el `replay()` y devuelve 200. Correcto por diseño.
- **Mismo `request_id` concurrente, mismo producto:** el lock `X` sobre la fila los serializa por completo; el perdedor decrementa, choca con el `UNIQUE`, y el rollback deshace su decremento. Neto: un único descuento, una única reserva. Correcto.
- **El ganador hace rollback:** el `INSERT` del perdedor deja de estar bloqueado y **tiene éxito** (la fila del ganador ya no está); neto una reserva. Correcto.
- **Captura del `UniqueConstraintViolationException` fuera de la transacción + `replay()` con snapshot nuevo:** sólido. El 1062 sólo se levanta *después* de que el ganador hace COMMIT (InnoDB retiene el `INSERT` duplicado hasta que la transacción bloqueante termina), así que `firstOrFail()` siempre encuentra la fila —dentro del alcance, donde no hay ruta de borrado de reservas en producción—.
- **`lockForUpdate()->find()`** sí emite `FOR UPDATE`; `save()` actualiza por PK bajo el lock retenido, sin ventana optimista. Correcto. (Ojo: `Product::find($id)->lockForUpdate()` *no* bloquearía; el orden actual es el bueno.)
- **1205** sólo ocurre en el `SELECT ... FOR UPDATE` inicial (primera sentencia), así que el rollback es limpio con independencia de `innodb_rollback_on_timeout`. Correcto.
- **`stock` no puede quedar negativo** por esta vía: guarda `>=` en PHP + columna `UNSIGNED`. Se sostiene.

---

### Sobre meter Redis / colas

**No hace falta y no lo justificaría aquí.** Decremento atómico, idempotencia y no-sobreventa se resuelven enteros con un lock de fila InnoDB + un índice único. Lo único que MySQL-solo no te da:

1. **Amortiguar tormentas de lock-wait** con reintentos/encolado — pero eso es responsabilidad del cliente, y `attempts: 1` + 503 `Retry-After` es una respuesta deliberada y defendible.
2. **Aislar el espacio de `request_id` entre llamantes** (hallazgo 5) — necesita un modelo de identidad, no Redis.

Introducir Redis o una cola aquí sería resolver un problema que esta carga no tiene, y añadiría un segundo sistema de coordinación que hay que mantener consistente con la fuente de verdad (MySQL) — es decir, más superficie de descuadre, no menos.

---
---

## Decisiones sobre las hallazgos

La revisión realizada por el modelo no encontró nada en el núcleo, lo que catalogo como bueno ya que le pedí explicitamente eso `-que dijera directamente cuándo no hubiera nada real en vez de rellenar-` de paso también verificó algo que tenía en cuenta y pendiente esperando la revisión, sobre le caso de que sea el request ganador (por decirlo de alguna manera) quien ejecute el rollback, el INSERT perdedor se desbloquea y pasa creando una reserva con éxito, lo cual sería lo correcto.

Los hallazgos vemos que cayeron más en el mapeo de los errores, límites de entrada, etc. A continuación detallo el por qué doy como **Aceptadas** solo 5 y **Rechazo** las otras 4 por decisión propia, junto con su motivo cada una.

--- 

### 1. `quantity` sin cota sueprior -> *ACEPTADA*
**Motivo:** Esto es efectivamente un fallo real, puesto que una cantidad de 10 digitos pasa la validación y reventaria el flujo al momento de hacer el INSERT con un 1264 que no tomé en cuenta, y si no se mapea, terminará reventando un error 500.


### 2. Mapear el 1213 a HTTP 503 -> *ACEPTADA*
**Motivo:** De la respuesta dada le doy el punto y acepto solo el mapeo del error más no el diagnostico completo. Dado que la decisión que tome para dejar los *`attemps=1`* la sostengom (y el mismo modelo coincide en no subirlo teniendo claro el alcance). Lo que sí me convenció en la decisión es lo otro, de si llega aparecer el 1213 hoy quedaría como un error 500 porque no lo no comtemplo en el Exception, así que sí, dejarlo sin mapear es un hueco que veo bien acertado por el modelo.


### 3. Corregir el cometnario por estética del Gap Lock -> *ACEPTADA*
**Motivo:** Es un buen hallazgo de la revisión aunque sea algo cosmético puesto que tiene razón. Aca solo cambio el comentario y nada de código porque el comportamiento es lo que espero, lo que toma como mal fue mi forma de describirlo y ejecutado por los promts enviados anteriormente.

### 4. El *`saveOrFail`* -> *ACEPTADA*
**Motivo:** Igual que el anterior lo catalogo y acepto porque acá efectivamente es la única linea donde se sosteniene el no sobrevender y estoy tirandole un save(). Y sí, hoy no hay ningun obrserver que puede explotar este bug dentro del alcance de lo implementado, pero no tengo forma de defenderlo porque ignoro el retorno justo allí, y porque solo es un cambio menor de ajustar el método.

### 6. El `Finally` en la limpieza del test -> *ACEPTADA*
**Motivo:** Es efectivamente un fallo real, aunque está en un test y no en la API, es una buena observación ya que el test escribe directo sobre la base de datos principal y si en algún momento por x o y razón se corta el proceso, la limpieza nunca llega a ejecutarse y quedan registros de productos o reservas colgados dentro de la db o stock clavado a un producto que ejecuto la prueba forzando el valor y lo siguiente que se corra va partir de un *false* y será el tipico caso de pasar un buen rato buscando un bug que nunca existió. 

---

### 5. Colocar la regla del uuid para el request -> *RECHAZADA*
**Motivo:** Acá rechazo solo por mantener el alcancé y el minimalismo explicito del enunciado en la prueba enviada y no imponer una regla sobre algo ya dado en el ejemplo propio de la misma. No digo que está mal, puesto que dentro de otro alcance la observación de aplicar esta regla está ok, pero implementarla sería agregar identidad del cliente, clave compuesta y demás cosas, que no quise meter para el alcance de la prueba.

### 7. Subir el `innodb_lock_wait_timeout` en el entorno de test -> *RECHAZADA*
**Motivo:** Esto haría justo lo contrario de lo que quise el configurarlo de esa manera para el alcance de esta prueba. El 5053 es la respuesta que tuve en mente en el diseño porque si aparece en los tests no es solo ruido, sino que 50 transacciones de 1ms duraron más de 10s, lo que querría indicar que algo está mal en el dimsensionado o la máquina. Adicional subir esto solo para que el entorno pase y quede todo ok, sería apagar una alarma que quiero monitorear por decisión.

### 8. Afirmar un suelo en el solapamiento con `performance_schema`  -> *RECHAZADA*
**Motivo:** Rechazo dado a que ya tengo esto cubierto por otro lado con el test contra la implementación ingenua (NaiveReservationService), que es el que detecta la sobreventa cuando la hay de menera directa, adcionalmente el modelo lo cataloga como endurecimiento sosteniendo el razonamiento. 

### 9. El detector del `remaining_stock` acoplado a `quantity >= 1` -> *RECHAZADA*
**Motivo:** El acoplamiento existe, pero es con una regla del contrato, no con un detalle de implementación. El *min:1* lo dejo allí por mantener lo que pide el enunciado de la prueba y no porque viniera bien. Adcional el escenario que plantea el model de *"relajar a min:0"* la regla no va a pasar puesto que una reserva de 0 unidades no significaria nada.


## Promt 5/Final - Ejecución de hallazgos aceptados.
```bash
Ejecuta las correciones dadas solo para los hallazgos 1. quantity sin cota sueprior, 2. Mapear el 1213 a HTTP 503, 3. Corregir el cometnario por estética del Gap Lock, 4. El saveOrFail y la 6. el finally en la limpieza del arnés. Los demás hallazgos quedan como rechazaos  por decisión y criterio propio.
```