<?php

namespace App\Common\Services;

use App\Common\Repositories\CuentaContableProfileRepository;
use Exception;

/**
 * Crea una cuenta personalizada (fuera del catálogo NIIF oficial) bajo una
 * cuenta existente de la empresa — ej. "Banco Pichincha Cta. Corriente" bajo
 * la cuenta oficial "Cuentas Bancarias". Hereda tipo/naturaleza/tipo_estado
 * del padre (una subcuenta de Activo no puede ser de otro tipo), y autogenera
 * el código: exactamente 2 dígitos más que el padre, siguiente número libre
 * entre sus hijos directos — mismo patrón de longitud que usa el propio plan
 * NIIF oficial en cada nivel (ej. "10102" -> "1010201", "1010202", ...).
 */
class CuentaContablePersonalizadaService
{
    public function crear(string $profileId, string $parentId, string $nombre, ?string $descripcion = null): array
    {
        $repo = new CuentaContableProfileRepository();

        $padre = $repo->get($parentId);
        if (!$padre || $padre->profile_id !== $profileId) {
            throw new Exception('La cuenta padre indicada no existe en esta empresa.');
        }

        $codigo = $this->siguienteCodigo($repo, $profileId, $padre->id, $padre->codigo);

        $creada = $repo->create([
            'codigo'      => $codigo,
            'nombre'      => $nombre,
            'tipo'        => $padre->tipo->value,
            'naturaleza'  => $padre->naturaleza->value,
            'tipo_estado' => $padre->tipo_estado,
            'es_detalle'  => true,
            'descripcion' => $descripcion ?? $nombre,
            'parent_id'   => $padre->id,
        ], $profileId);

        // El padre pasa a ser una cuenta agrupadora: ya no se debería postear
        // directo sobre ella ahora que tiene una subcuenta postable propia.
        if ($padre->es_detalle) {
            $repo->update($padre->id, ['es_detalle' => false]);
        }

        return $creada->toArray();
    }

    private function siguienteCodigo(CuentaContableProfileRepository $repo, string $profileId, string $parentId, string $codigoPadre): string
    {
        $hijos = array_filter(
            $repo->getByProfileId($profileId),
            fn($c) => $c->parent_id === $parentId
        );

        $maxSufijo = 0;
        foreach ($hijos as $hijo) {
            $sufijo = (int)substr($hijo->codigo, strlen($codigoPadre));
            $maxSufijo = max($maxSufijo, $sufijo);
        }

        return $codigoPadre . str_pad((string)($maxSufijo + 1), 2, '0', STR_PAD_LEFT);
    }
}
