<?php

namespace App\Services;

use App\Enums\ReservationStatus;
use App\Exceptions\IdempotencyConflictException;
use App\Exceptions\ProductNotFoundException;
use App\Models\Product;
use App\Models\Reservation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class AtomicReservationService implements ReservationStrategy
{
    public function reserve(string $requestId, int $productId, int $quantity): ReservationResult
    {
        try {
            /*
             * attempts: 1 -- sin reintento, y es una decision, no un descuido:
             *
             *  - Deadlock (1213) no puede ocurrir aqui: cada transaccion bloquea
             *    una sola fila de `products` (por PK) y siempre en el mismo orden,
             *    asi que no hay dos recursos que puedan cruzarse en un ciclo de
             *    espera. Si algun dia entran reservas multi-linea habria que
             *    bloquear las filas ordenadas por product_id y reconsiderar esto.
             *  - Lock wait timeout (1205) si puede darse (innodb_lock_wait_timeout
             *    esta fijado en 10s en el compose). Reintentar solo alargaria la
             *    espera de un cliente que ya lleva 10s bloqueado; preferimos
             *    devolver el control ya. El handler HTTP lo traduce a 503 +
             *    Retry-After para que el cliente reintente cuando quiera.
             */
            $reservation = DB::transaction(function () use ($requestId, $productId, $quantity) {
                /*
                 * SELECT ... FOR UPDATE (lockForUpdate). Bajo REPEATABLE READ un
                 * SELECT normal leeria el snapshot de la transaccion y podria
                 * quedar obsoleto; el locking read lee la ultima version
                 * committeada y ademas retiene un lock exclusivo sobre la fila
                 * hasta el COMMIT. Por eso el $stock leido aqui sigue siendo el
                 * valor real cuando escribimos la reserva mas abajo: nadie mas
                 * puede tocar esa fila mientras tanto.
                 */
                $product = Product::query()->lockForUpdate()->find($productId);

                if ($product === null) {
                    throw new ProductNotFoundException($productId);
                }

                $stock = (int) $product->stock;

                if ($stock >= $quantity) {
                    $remaining = $stock - $quantity;

                    // Escribimos el valor ya calculado (no `stock - quantity` en
                    // SQL): asi queda explicito que products.stock y
                    // reservations.remaining_stock salen del mismo numero.
                    $product->stock = $remaining;
                    $product->save();

                    $status = ReservationStatus::Confirmed;
                } else {
                    // Stock insuficiente: el producto no se toca. Persistimos la
                    // reserva rechazada con el stock real (leido bajo el lock)
                    // para que el endpoint sea idempotente tambien en el fallo.
                    $remaining = $stock;
                    $status = ReservationStatus::Rejected;
                }

                return Reservation::create([
                    'request_id' => $requestId,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                    'remaining_stock' => $remaining,
                    'status' => $status,
                ]);
            }, attempts: 1);

            return new ReservationResult($reservation, wasReplay: false);
        } catch (UniqueConstraintViolationException) {
            /*
             * Otra transaccion con el mismo request_id gano la carrera (o es un
             * reintento del cliente). El INSERT de arriba se bloqueo contra el
             * indice UNIQUE hasta que la ganadora hizo COMMIT, y entonces obtuvo
             * el 1062; DB::transaction ya hizo rollback, deshaciendo el decremento
             * de esta transaccion. La leemos FUERA de la transaccion abortada:
             * hace falta un snapshot nuevo para ver la fila que la ganadora
             * dejo committeada.
             */
            return $this->replay($requestId, $productId, $quantity);
        }
    }

    private function replay(string $requestId, int $productId, int $quantity): ReservationResult
    {
        $existing = Reservation::query()->where('request_id', $requestId)->firstOrFail();

        if ((int) $existing->product_id !== $productId || (int) $existing->quantity !== $quantity) {
            throw new IdempotencyConflictException($requestId);
        }

        return new ReservationResult($existing, wasReplay: true);
    }
}
