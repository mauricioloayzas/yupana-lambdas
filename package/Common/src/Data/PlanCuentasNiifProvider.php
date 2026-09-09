<?php

namespace App\Common\Data;

/**
 * Catálogo NIIF de la Superintendencia de Compañías del Ecuador, bundleado
 * junto al código (plan-cuentas-niif.json) en vez de vivir en una tabla
 * maestra de DynamoDB: es dato de referencia estático — nada en la app lo
 * crea/edita/borra vía API, solo se lee para clonarlo hacia una empresa — así
 * que va versionado con el código (se corrige con un commit + deploy, igual
 * que cualquier otro bug, no con una llamada a la API) y el `init` de una
 * empresa nueva no depende de que alguien haya corrido un seed manual antes.
 * Solo incluye TIPO DE ESTADO 1 y 2 del PDF oficial (Situación Financiera y
 * Resultado Integral) — 3 y 5 son plantillas de reporte, no cuentas postables.
 */
class PlanCuentasNiifProvider
{
    private static ?array $cuentas = null;

    /**
     * @return array<int, array{codigo:string,nombre:string,tipo:string,naturaleza:string,tipo_estado:int,es_detalle:bool,descripcion:string}>
     */
    public static function cuentas(): array
    {
        if (self::$cuentas === null) {
            $json = file_get_contents(__DIR__ . '/plan-cuentas-niif.json');
            self::$cuentas = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        }

        return self::$cuentas;
    }
}
