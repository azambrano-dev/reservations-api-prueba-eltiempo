<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreReservationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Validacion puramente sintactica.
     *
     * - request_id: identificador opaco del cliente. No se impone formato UUID;
     *   required|string|max:64 es suficiente.
     * - product_id: SIN regla exists:products,id a proposito. Esa regla daria un
     *   422 para un producto inexistente, pero el contrato define 404 para ese
     *   caso y la existencia ya se resuelve en el SELECT ... FOR UPDATE del
     *   servicio, sin una query extra aqui.
     * - quantity: el max coincide con el techo de la columna INT UNSIGNED. Sin
     *   el, un valor mayor llega al INSERT y con sql_mode estricto revienta con
     *   error 1264 (out of range) -> QueryException no mapeada -> HTTP 500 ante
     *   entrada no autenticada. Con la regla, sale por 422 como cualquier otro
     *   payload invalido.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'request_id' => ['required', 'string', 'max:64'],
            'product_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'integer', 'min:1', 'max:4294967295'],
        ];
    }
}
