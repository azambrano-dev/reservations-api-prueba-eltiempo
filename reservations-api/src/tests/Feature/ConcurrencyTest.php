<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Process;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Pruebas de concurrencia REAL: delegan en el comando `reservations:stress`, que
 * dispara N peticiones HTTP simultaneas contra nginx con curl_multi. Cada
 * peticion la sirve un worker distinto de php-fpm, asi que hay concurrencia
 * autentica a nivel de InnoDB -- cosa que un test PHPUnit normal (un solo
 * proceso, una sola conexion) no puede reproducir.
 *
 * No usan RefreshDatabase: su transaccion envolvente ocultaria los datos a los
 * procesos externos. El comando siembra y limpia sus propias filas, y se le
 * fuerza a la MISMA base que usa php-fpm (la del contenedor, no la de test).
 */
class ConcurrencyTest extends TestCase
{
    private const BASE_URL = 'http://nginx_reservations';

    protected function setUp(): void
    {
        parent::setUp();

        $up = Process::timeout(5)->run('curl -s -o /dev/null -w "%{http_code}" ' . self::BASE_URL . '/up');

        if (trim($up->output()) !== '200') {
            $this->markTestSkipped('nginx no accesible en ' . self::BASE_URL . ' (ejecutar dentro de docker compose)');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function stress(array $options): array
    {
        $args = ['php', 'artisan', 'reservations:stress', '--json', '--assert', '--base-url=' . self::BASE_URL];

        foreach ($options as $key => $value) {
            $args[] = $value === true ? "--{$key}" : "--{$key}={$value}";
        }

        $result = Process::timeout(120)
            // php-fpm sirve las peticiones contra la base del contenedor; el
            // comando tiene que escribir y leer en esa misma base, no en la de
            // test que inyecta phpunit.xml.
            ->env(['DB_DATABASE' => 'api-reservations'])
            ->run($args);

        $json = json_decode($result->output(), true);
        $this->assertIsArray($json, "salida no-JSON del comando:\n" . $result->output() . $result->errorOutput());

        return ['exit' => $result->exitCode(), 'summary' => $json, 'raw' => $result->output()];
    }

    public function test_atomic_strategy_never_oversells_under_load(): void
    {
        $run = $this->stress([
            'strategy' => 'atomic',
            'stock' => 10,
            'concurrency' => 50,
            'quantity' => 1,
        ]);

        $s = $run['summary'];

        $this->assertSame(0, $run['exit'], "invariantes violadas:\n" . $run['raw']);
        $this->assertFalse($s['oversold']);
        $this->assertSame(0, $s['http_5xx']);
        $this->assertSame(10, $s['reservations_confirmed']);
        $this->assertSame(10, $s['http_201']);
        $this->assertSame(40, $s['http_409']);
        $this->assertSame(0, $s['stock_final']);
        $this->assertTrue($s['remaining_stock_unique'], 'remaining_stock repetido => lost update');
        $this->assertSame($s['stock_decremented'], $s['confirmed_quantity_sum']);
    }

    public function test_concurrent_requests_with_same_request_id_create_exactly_one_reservation(): void
    {
        $run = $this->stress([
            'strategy' => 'atomic',
            'same-request-id' => true,
            'concurrency' => 25,
            'stock' => 10,
            'quantity' => 1,
        ]);

        $s = $run['summary'];

        $this->assertSame(0, $run['exit'], $run['raw']);
        $this->assertSame(1, $s['reservations_confirmed'] + $s['reservations_rejected']);
        $this->assertSame(1, $s['stock_decremented']);
        $this->assertSame(1, $s['http_201'], 'exactamente una peticion crea la reserva');
        $this->assertSame(25, $s['http_200'] + $s['http_201'], 'el resto son replays de la misma reserva');
        $this->assertSame(0, $s['http_5xx']);
    }

    /**
     * Prueba de validez del arnes: la implementacion naive DEBE sobrevender.
     * Un arnes que nunca ha detectado el bug no acredita nada cuando pasa
     * contra la implementacion correcta. La suite queda verde porque aqui
     * afirmamos que la sobreventa ocurre.
     */
    #[Group('race-demo')]
    public function test_naive_strategy_oversells_under_load(): void
    {
        $run = $this->stress([
            'strategy' => 'naive',
            'stock' => 10,
            'concurrency' => 50,
            'quantity' => 1,
            'race-delay' => 50,
        ]);

        $s = $run['summary'];

        $this->assertNotSame(0, $run['exit'], 'la naive deberia violar invariantes');
        $this->assertTrue(
            $s['oversold'] || ! $s['remaining_stock_unique'],
            'se esperaba sobreventa o remaining_stock repetido con la estrategia naive',
        );
        $this->assertGreaterThan(10, $s['reservations_confirmed']);
    }
}
