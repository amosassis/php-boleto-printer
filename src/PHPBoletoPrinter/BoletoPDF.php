<?php
/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
namespace PHPBoletoPrinter;

class BoletoPDF
{

    private $pdf;

    function __construct()
    {
        $this->pdf = new PDFReport();
    }

    public function AddBoleto(\PHPBoletoPrinter\BoletoAbstract $boleto)
    {
        $this->addPage();
        $this->addComprovanteEntrega($boleto);
        $this->addCabecalhoBoleto($boleto);
        $this->addFichaCompensacao($boleto);
    }

    private function addPage()
    {
        $this->pdf->AddPage();
        $this->pdf->SetFont('Arial', '', 10);
    }

    private function addComprovanteEntrega(\PHPBoletoPrinter\BoletoAbstract $boleto)
    {
        //Layout        
        $this->pdf->Text(10, 15, 'Comprovante');
    }

    private function addCabecalhoBoleto(\PHPBoletoPrinter\BoletoAbstract $boleto)
    {
        $this->pdf->Text(10, 25, 'Cabecalho');
    }

    private function addFichaCompensacao(\PHPBoletoPrinter\BoletoAbstract $boleto)
    {
        $this->pdf->Text(10, 35, 'Ficha compensacao');        
    }

    public function Output($mode = 'I')
    {
        if ($mode == 'I') {
            $this->pdf->Output();
        }
    }
}
