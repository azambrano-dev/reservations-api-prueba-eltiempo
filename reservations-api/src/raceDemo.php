<?php

/**
 *  Se presenta la siguiente prueba de concurrencia aicional para demostar el cumplimiento 
 *  del punto 7 de la prueba que "No solo se debe afirmar que funciona, si no demotrarlo"
 *
 *  Lo implementé por fork() para simular la concurrencia directa al servicio de reservas
 *  sin pasar por un servidor HTTP, con el obejetivo de demostrar la funcionalidad 
 *  de manera real y reproducible sin depender de como esté configurado el servicio 
 *  nginx o php-fpm del proyecto mediante docker.
 * 
 *  Para la versión por cliente HTTP, la prueba está en tests/Feature/ConcurrencyTest
 *  que simula la concurrencia vía HTTP con múltiples requests simultáneos.
 * 
 *  Para ejecutar la prueba, se puede usar los siguientes comandos:
 * 
 *  1. ´sudo docker compose exec api_reservations php raceDemo.php´
 *  3. ´sudo docker compose exec api_reservations php raceDemo.php --implementation=naive --delay=50´ 
 * 
*/

require __DIR__ . '/vendor/autoload.php';

$app = require __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\AtomicReservationService;
use App\Services\NaiveReservationService;
use Illuminate\Support\Facades\DB;

$options = getopt('', [ 'implementation::', 'delay::', 'stock::', 'workers::' ]);

$implementation = $options['implementation'] ?? 'atomic';
$stock = (int) ($options['stock'] ?? 1);
$delay = (int) ($options['delay'] ?? 0);
$workers = (int) ($options['workers'] ?? 1);

// Asignamos el delay a la configuración de la aplicación para que sea accesible en el servicio de reservas
config([ 'reservations.race_delay_ms' => $delay ]);

// Limpiamos la tabla de reservas antes de la demo y agregamos stock al producto de prueba
DB::table('reservations')->delete();
DB::table('products')->updateOrInsert(
    [ 'id' => 1 ],
    [ 'name' => 'Producto de prueba', 'stock' => $stock, 'created_at' => now(), 'updated_at' => now() ]
);

echo "Demo de concurrencia directa\n";
echo "Tipo de implementación: $implementation\n";
echo "Stock inicial: $stock\n";
echo "Delay: $delay ms\n";
echo "Workers: $workers\n\n";

// Asignamos 500 ms de delay inicial para que todos los workers estén listos y se ejecuten al mismo tiempo
$startTime = hrtime(true) + 500_000; 

for ( $i = 0; $i < $workers; $i++ )  
{
    if ( pcntl_fork() !== 0 ) { continue; }

    // Purgamos la conexión a la db para que cada worker tenga su propia conexión
    DB::purge();
    DB::reconnect();

    $service = app( $implementation === 'naive' ? NaiveReservationService::class : AtomicReservationService::class );

    // Esperamos hasta el tiempo de inicio para que todos los workers se ejecuten al mismo tiempo
    while ( hrtime(true) < $startTime );

    try {
        $reservation = $service->reserve("REQUEST-{$i}", 1, 1)->reservation;
        $output = [
            'worker' => $i,
            'request_id' => $reservation->request_id,
            'status' => $reservation->status->value,
            'remaining_stock' => $reservation->remaining_stock,
        ];
        echo "Reserva exitosa: Worker: {$i}, ID {$reservation->id}, Estado: {$reservation->status->value}, Stock restante: {$reservation->remaining_stock}\n";
    } catch ( \Throwable $e ) {
        $output = [
            'worker' => $i,
            'status' => 'error',
            'error' => class_basename($e) . ': ' . $e->getMessage(),
        ];

        echo "Error al reservar: {$e->getMessage()}\n";
    }

    file_put_contents("/tmp/race_demo_worker_{$i}.json", json_encode($output, JSON_PRETTY_PRINT));
    exit(0);
}

while ( pcntl_waitpid(0, $status) !== -1 );

$confirmed = 0;

for ( $i = 0; $i < $workers; $i++ ) {
    $output = json_decode(file_get_contents("/tmp/race_demo_worker_{$i}.json"), true);
    unlink("/tmp/race_demo_worker_{$i}.json");

    printf(
        "Worker %d: Request ID: %s, Status: %s, Remaining Stock: %s\n",
        $output['worker'],
        $output['request_id'],
        $output['status'],
        $output['remaining_stock'] ?? 'N/A'
    );

    $confirmed += $output['status'] === 'confirmed' ? 1 : 0; 
}

$finalStock = (int) DB::table('products')->where('id', 1)->value('stock');

echo "\nResumen de la demo:\n";
echo "Total de reservas confirmadas: $confirmed\n";
echo "Stock final del producto: $finalStock\n";

$ok = $confirmed === $stock && $finalStock === 0;

echo $ok ? "Success: Resultado esperado, no hubo sobreventa.\n" : "Failed: Resultado inesperado, Hubo sobreventa.\n";

exit($ok ? 0 : 1);