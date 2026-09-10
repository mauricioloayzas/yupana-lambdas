<?php

namespace App\Common\Services;

use App\Common\Repositories\ProfileRepository;
use Mauloasan\BobConstruye\DynamoDB\Entities\Orchestrator\ProfileEntity;
use TCPDF;

/**
 * PDF del Estado de Flujos de Efectivo — mismo patrón que
 * BalanceGeneralPdfService / EstadoResultadosPdfService.
 */
class EstadoFlujoEfectivoPdfService
{
    public function generar(array $resultado, string $profileId): string
    {
        $profile = (new ProfileRepository())->get($profileId);

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Clichín');
        $pdf->SetTitle("Estado de Flujos de Efectivo - {$resultado['anio']}");
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();

        $this->escribirCabecera($pdf, $resultado, $profile);
        $pdf->Ln(3);
        $this->escribirTabla($pdf, $resultado['lineas']);
        $pdf->Ln(3);
        $this->escribirChequeo($pdf, $resultado);

        return $pdf->Output('', 'S');
    }

    private function escribirCabecera(TCPDF $pdf, array $resultado, ?ProfileEntity $profile): void
    {
        $esc = fn (?string $v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');
        $periodo = $resultado['mes_desde'] === '01' && $resultado['mes_hasta'] === '12'
            ? "Año {$resultado['anio']}"
            : "{$resultado['mes_desde']}/{$resultado['anio']} a {$resultado['mes_hasta']}/{$resultado['anio']}";

        $pdf->writeHTML(
            '<h2 style="margin:0;color:#111;">Estado de Flujos de Efectivo (método indirecto)</h2>'
            . '<p style="margin:2px 0 0;color:#555;">' . $esc($profile?->name ?? '') . ' &nbsp;·&nbsp; RUC: ' . $esc($profile?->tax_id ?? '') . '</p>'
            . '<p style="margin:2px 0 0;color:#555;">Período: ' . $esc($periodo) . '</p>'
            . '<p style="margin:8px 0 0;padding:6px 8px;background-color:#eff6ff;color:#1e40af;font-size:8pt;">'
            . 'Derivado de los cambios de saldo del resto de cuentas de balance en el período — documento de apoyo, '
            . 'el contador debe validarlo antes de subirlo al portal de la SCVS.'
            . '</p>',
            true, false, true, false, ''
        );
    }

    private function escribirTabla(TCPDF $pdf, array $lineas): void
    {
        $esc = fn (string $v) => htmlspecialchars($v, ENT_QUOTES, 'UTF-8');

        $rows = '';
        foreach ($lineas as $l) {
            $negrita = $l['es_subtotal'] ? 'font-weight:bold;' : '';
            $fondo = $l['es_subtotal'] ? 'background-color:#f9fafb;' : '';
            $indent = 6 + $l['nivel'] * 8;
            $rows .= '<tr>'
                . '<td style="padding:3px 6px;padding-left:' . $indent . 'px;border-bottom:1px solid #eee;' . $negrita . $fondo . '">' . $esc($l['nombre']) . '</td>'
                . '<td style="padding:3px 6px;border-bottom:1px solid #eee;text-align:right;width:70px;' . $negrita . $fondo . '">$' . number_format($l['valor'], 2) . '</td>'
                . '</tr>';
        }

        $pdf->writeHTML(
            '<table cellpadding="0" style="font-size:8pt;width:100%;">'
            . '<tr style="font-weight:bold;color:#555;"><td style="padding:3px 6px;">Concepto</td><td style="padding:3px 6px;text-align:right;">Valor</td></tr>'
            . $rows
            . '</table>',
            true, false, true, false, ''
        );
    }

    private function escribirChequeo(TCPDF $pdf, array $resultado): void
    {
        if (!$resultado['periodo_llega_hasta_hoy']) {
            $pdf->writeHTML(
                '<p style="margin:0;padding:6px 8px;background-color:#f9fafb;color:#555;font-size:8pt;">'
                . 'El período consultado no llega hasta la fecha actual, así que no se compara contra el saldo actual de Efectivo.'
                . '</p>',
                true, false, true, false, ''
            );
            return;
        }

        $color = $resultado['cuadra'] ? '#166534' : '#991b1b';
        $fondo = $resultado['cuadra'] ? '#f0fdf4' : '#fef2f2';
        $mensaje = $resultado['cuadra']
            ? 'OK: Efectivo inicial + movimiento neto = Efectivo actual ($' . number_format($resultado['efectivo_actual_real'], 2) . ')'
            : 'ATENCIÓN: el efectivo calculado &ne; saldo actual de Efectivo ($' . number_format($resultado['efectivo_actual_real'], 2) . ') — diferencia de $' . number_format(abs($resultado['diferencia']), 2) . ', revisar la mayorización.';

        $pdf->writeHTML(
            '<p style="margin:0;padding:6px 8px;background-color:' . $fondo . ';color:' . $color . ';font-size:9pt;font-weight:bold;">' . $mensaje . '</p>',
            true, false, true, false, ''
        );
    }
}
