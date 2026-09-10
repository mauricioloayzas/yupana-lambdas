<?php

namespace App\Common\Services;

use App\Common\Repositories\ProfileRepository;
use Mauloasan\BobConstruye\DynamoDB\Entities\Orchestrator\ProfileEntity;
use TCPDF;

/**
 * PDF del Balance General — mismo patrón que Formulario104PdfService de
 * caja-registradora (TCPDF, writeHTML con tablas).
 */
class BalanceGeneralPdfService
{
    public function generar(array $resultado, string $profileId): string
    {
        $profile = (new ProfileRepository())->get($profileId);

        $pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Clichín');
        $pdf->SetTitle("Balance General - {$resultado['fecha']}");
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(12, 12, 12);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->AddPage();

        $this->escribirCabecera($pdf, $resultado, $profile);
        $pdf->Ln(3);
        $this->escribirTabla($pdf, $resultado['lineas']);
        $pdf->Ln(3);
        $this->escribirTotales($pdf, $resultado);

        return $pdf->Output('', 'S');
    }

    private function escribirCabecera(TCPDF $pdf, array $resultado, ?ProfileEntity $profile): void
    {
        $esc = fn (?string $v) => htmlspecialchars($v ?? '', ENT_QUOTES, 'UTF-8');

        $pdf->writeHTML(
            '<h2 style="margin:0;color:#111;">Estado de Situación Financiera (Balance General)</h2>'
            . '<p style="margin:2px 0 0;color:#555;">' . $esc($profile?->name ?? '') . ' &nbsp;·&nbsp; RUC: ' . $esc($profile?->tax_id ?? '') . '</p>'
            . '<p style="margin:2px 0 0;color:#555;">Fecha de corte: ' . $esc($resultado['fecha']) . '</p>'
            . '<p style="margin:8px 0 0;padding:6px 8px;background-color:#eff6ff;color:#1e40af;font-size:8pt;">'
            . 'Formato basado en el plan de cuentas NIIF de la Superintendencia de Compañías — documento de apoyo, '
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
            // El catálogo completo tiene ~350 cuentas de tipo_estado=1 — mostrar
            // las que nunca tuvieron movimiento (la enorme mayoría, para un
            // negocio chico) vuelve el reporte ilegible. Se omite cualquier fila
            // en cero, sea grupo o detalle: si un grupo entero está en cero es
            // porque de verdad no pasó nada ahí (ya viene sumado por la
            // mayorización), así que ocultar el grupo tampoco esconde nada real.
            if (abs($l['valor']) < 0.005) {
                continue;
            }
            $negrita = $l['es_detalle'] ? '' : 'font-weight:bold;';
            $indent = 6 + $l['nivel'] * 5;
            $rows .= '<tr>'
                . '<td style="padding:2px 6px;border-bottom:1px solid #eee;font-size:7pt;color:#999;width:60px;">' . $esc($l['codigo']) . '</td>'
                . '<td style="padding:2px 6px;padding-left:' . $indent . 'px;border-bottom:1px solid #eee;' . $negrita . '">' . $esc($l['nombre']) . '</td>'
                . '<td style="padding:2px 6px;border-bottom:1px solid #eee;text-align:right;width:70px;' . $negrita . '">$' . number_format($l['valor'], 2) . '</td>'
                . '</tr>';
        }

        $pdf->writeHTML(
            '<table cellpadding="0" style="font-size:8pt;width:100%;">'
            . '<tr style="font-weight:bold;color:#555;"><td style="padding:2px 6px;">Código</td><td style="padding:2px 6px;">Cuenta</td><td style="padding:2px 6px;text-align:right;">Valor</td></tr>'
            . $rows
            . '</table>',
            true, false, true, false, ''
        );
    }

    private function escribirTotales(TCPDF $pdf, array $resultado): void
    {
        $color = $resultado['cuadra'] ? '#166534' : '#991b1b';
        $fondo = $resultado['cuadra'] ? '#f0fdf4' : '#fef2f2';
        // Sin símbolos ✓/⚠: la fuente por defecto de TCPDF no los renderiza bien.
        $mensaje = $resultado['cuadra']
            ? 'OK: Activo = Pasivo + Patrimonio'
            : 'ATENCIÓN: Activo &ne; Pasivo + Patrimonio — diferencia de $' . number_format(abs($resultado['diferencia']), 2) . ', revisar la mayorización.';

        $pdf->writeHTML(
            '<table cellpadding="0" style="font-size:9pt;width:100%;">'
            . '<tr><td style="padding:2px 6px;font-weight:bold;">Total Activo</td><td style="padding:2px 6px;text-align:right;width:80px;">$' . number_format($resultado['total_activo'], 2) . '</td></tr>'
            . '<tr><td style="padding:2px 6px;font-weight:bold;">Total Pasivo</td><td style="padding:2px 6px;text-align:right;">$' . number_format($resultado['total_pasivo'], 2) . '</td></tr>'
            . '<tr><td style="padding:2px 6px;font-weight:bold;">Total Patrimonio (incl. resultado acumulado)</td><td style="padding:2px 6px;text-align:right;">$' . number_format($resultado['total_patrimonio'], 2) . '</td></tr>'
            . '</table>'
            . '<p style="margin:6px 0 0;padding:6px 8px;background-color:' . $fondo . ';color:' . $color . ';font-size:9pt;font-weight:bold;">' . $mensaje . '</p>',
            true, false, true, false, ''
        );
    }
}
