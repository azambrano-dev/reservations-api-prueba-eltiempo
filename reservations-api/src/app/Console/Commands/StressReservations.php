<?php

namespace App\Console\Commands;

use App\Enums\ReservationStatus;
use App\Models\Product;
use App\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Arnes de concurrencia para POST /api/reservations.
 *
 * Dispara N peticiones HTTP REALES y simultaneas contra nginx con curl_multi
 * (cada handle es una conexion propia, nginx las reparte entre workers de
 * php-fpm distintos, luego hay concurrencia real a nivel de InnoDB) y comprueba
 * invariantes sobre el estado final.
 *
 * Un bucle PHP en un solo proceso seria secuencial y no probaria nada.
 *
 * Ejemplos:
 *   php artisan reservations:stress --strategy=atomic --stock=10 --concurrency=50 --assert
 *   php artisan reservations:stress --strategy=naive  --stock=10 --concurrency=50 --race-delay=100 --assert
 *   php artisan reservations:stress --same-request-id --concurrency=20 --stock=10 --assert
 */
class StressReservations extends Command
{
    protected $signature = 'reservations:stress
        {--base-url=http://nginx_reservations : URL base de la API}
        {--product= : id de un producto existente; si se omite, se crea uno}
        {--stock=10 : stock al que se (re)ajusta el producto antes de la corrida}
        {--concurrency=50 : numero de peticiones simultaneas}
        {--quantity=1 : cantidad por peticion}
        {--strategy=atomic : atomic|naive (via cabecera X-Reservation-Strategy)}
        {--race-delay=0 : ms de retardo lectura->escritura para la estrategia naive}
        {--same-request-id : todas las peticiones comparten un request_id (sonda de idempotencia)}
        {--assert : comprueba invariantes y devuelve exit 1 si alguno falla}
        {--json : imprime solo el resumen JSON}
        {--keep : no borra las filas creadas al terminar}';

    protected $description = 'Lanza peticiones concurrentes reales contra POST /api/reservations y verifica invariantes';

    public function handle(): int
    {
        $baseUrl = rtrim((string) $this->option('base-url'), '/');
        $stock = (int) $this->option('stock');
        $concurrency = (int) $this->option('concurrency');
        $quantity = (int) $this->option('quantity');
        $strategy = (string) $this->option('strategy');
        $raceDelay = (int) $this->option('race-delay');
        $sameRequestId = (bool) $this->option('same-request-id');
        $json = (bool) $this->option('json');

        $createdProduct = false;
        if ($this->option('product')) {
            $product = Product::query()->findOrFail((int) $this->option('product'));
            $product->update(['stock' => $stock]);
        } else {
            $product = Product::query()->create([
                'name' => 'stress-'.Str::random(8),
                'stock' => $stock,
            ]);
            $createdProduct = true;
        }

        try {
            $sharedRequestId = (string) Str::uuid();
            $payloads = [];
            for ($i = 0; $i < $concurrency; $i++) {
                $payloads[] = [
                    'request_id' => $sameRequestId ? $sharedRequestId : (string) Str::uuid(),
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                ];
            }

            $httpCodes = $this->fire($baseUrl, $payloads, $strategy, $raceDelay);

            // Estado final, leido con una conexion limpia (fuera de cualquier tx).
            $product->refresh();
            $confirmed = Reservation::query()
                ->where('product_id', $product->id)
                ->where('status', ReservationStatus::Confirmed)
                ->get();
            $rejectedCount = Reservation::query()
                ->where('product_id', $product->id)
                ->where('status', ReservationStatus::Rejected)
                ->count();

            $confirmedQty = (int) $confirmed->sum('quantity');
            $remainingValues = $confirmed->pluck('remaining_stock')->map(fn ($v) => (int) $v)->values();
            $remainingUnique = $remainingValues->unique()->count() === $remainingValues->count();

            $counts = [
                'http_201' => 0,
                'http_200' => 0,
                'http_409' => 0,
                'http_422' => 0,
                'http_5xx' => 0,
                'http_other' => 0,
            ];
            foreach ($httpCodes as $code) {
                match (true) {
                    $code === 201 => $counts['http_201']++,
                    $code === 200 => $counts['http_200']++,
                    $code === 409 => $counts['http_409']++,
                    $code === 422 => $counts['http_422']++,
                    $code >= 500 => $counts['http_5xx']++,
                    default => $counts['http_other']++,
                };
            }

            $oversold = $confirmedQty > $stock || ($stock - $product->stock) !== $confirmedQty;

            $summary = [
                'strategy' => $strategy,
                'concurrency' => $concurrency,
                'quantity_per_request' => $quantity,
                'same_request_id' => $sameRequestId,
                'product_id' => $product->id,
                'stock_initial' => $stock,
                'stock_final' => (int) $product->stock,
                'stock_decremented' => $stock - (int) $product->stock,
                'reservations_confirmed' => $confirmed->count(),
                'reservations_rejected' => $rejectedCount,
                'confirmed_quantity_sum' => $confirmedQty,
                'remaining_stock_unique' => $remainingUnique,
                'oversold' => $oversold,
            ] + $counts;

            $checks = $this->option('assert') ? $this->checkInvariants($summary, $sameRequestId) : [];
            $passed = ! in_array(false, $checks, true);

            if ($this->option('assert')) {
                $summary['checks'] = $checks;
                $summary['assert_passed'] = $passed;
            }

            if ($json) {
                // Solo JSON en stdout: nada mas, para que el llamante pueda parsearlo.
                $this->output->writeln(json_encode($summary, JSON_PRETTY_PRINT));
            } else {
                $this->table(['metric', 'value'], collect($summary)->except('checks')->map(
                    fn ($v, $k) => [$k, is_bool($v) ? ($v ? 'true' : 'false') : $v],
                )->values());

                foreach ($checks as $label => $ok) {
                    $this->line(($ok ? '<info>PASS</info>' : '<error>FAIL</error>')." {$label}");
                }
            }

            return $passed ? self::SUCCESS : self::FAILURE;
        } finally {
            // En finally a proposito: se ejecuta tambien si una asercion aborta,
            // si el Process que invoca el comando expira o si CI cancela el job.
            // Sin esto quedan productos y reservas de estres huerfanos en la BD
            // de desarrollo (el arnes corre contra api-reservations, no la de test).
            if (! $this->option('keep')) {
                Reservation::query()->where('product_id', $product->id)->delete();
                if ($createdProduct) {
                    $product->delete();
                }
            }
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $payloads
     * @return array<int, int> codigos HTTP en el mismo orden
     */
    private function fire(string $baseUrl, array $payloads, string $strategy, int $raceDelay): array
    {
        $mh = curl_multi_init();
        $handles = [];

        foreach ($payloads as $payload) {
            $ch = curl_init("{$baseUrl}/api/reservations");
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($payload),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    "X-Reservation-Strategy: {$strategy}",
                    "X-Reservation-Race-Delay-Ms: {$raceDelay}",
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 30,
            ]);
            curl_multi_add_handle($mh, $ch);
            $handles[] = $ch;
        }

        // Arranca todas las transferencias y bloquea hasta que terminen.
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $codes = [];
        foreach ($handles as $ch) {
            $codes[] = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);
        }
        curl_multi_close($mh);

        return $codes;
    }

    /**
     * @param  array<string, mixed>  $s
     * @return array<string, bool>
     */
    private function checkInvariants(array $s, bool $sameRequestId): array
    {
        $checks = [];

        // Invariantes universales.
        $checks['stock final no negativo'] = $s['stock_final'] >= 0;
        $checks['sin respuestas 5xx'] = $s['http_5xx'] === 0;
        $checks['stock decrementado == suma de cantidades confirmadas'] =
            $s['stock_decremented'] === $s['confirmed_quantity_sum'];
        $checks['sin sobreventa'] = $s['oversold'] === false;
        // Detector fuerte de lost update: dos confirmadas con el mismo
        // remaining_stock son prueba directa de que dos transacciones leyeron
        // el mismo valor base.
        $checks['remaining_stock sin repetidos entre confirmadas'] = $s['remaining_stock_unique'] === true;

        if ($sameRequestId) {
            $checks['idempotencia: exactamente 1 reserva'] =
                ($s['reservations_confirmed'] + $s['reservations_rejected']) === 1;
            $checks['idempotencia: stock movido como mucho una vez'] =
                $s['stock_decremented'] <= $s['quantity_per_request'];
        } else {
            // stock=10, quantity=1, concurrency>=10 => 10 confirmadas exactas.
            $expectedConfirmed = intdiv($s['stock_initial'], $s['quantity_per_request']);
            if ($s['concurrency'] >= $expectedConfirmed) {
                $checks["confirmadas == {$expectedConfirmed}"] =
                    $s['reservations_confirmed'] === $expectedConfirmed;
            }
        }

        return $checks;
    }
}
