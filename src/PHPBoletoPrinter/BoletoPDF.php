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
    private $posY;
    private $fontLabel = array('font' => 'Arial', 'style' => '', 'size' => 7);
    private $fontText = array('font' => 'Arial', 'style' => 'B', 'size' => 8);

    function __construct()
    {
        $this->pdf = new PDFReport();
    }

    public function AddBoleto(\PHPBoletoPrinter\BoletoAbstract $boleto)
    {
        $this->posY = 12;
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
        //Logotipo Banco
        $this->addTopoBoleto($boleto, 13, 'Comprovante de entrega');
        //Beneficiario        
        $this->addTextBox(13, 24, 68, 7, 'Beneficiário', $boleto->getCedente()->getNome());
        //Agencia / Codigo
        $this->addTextBox(81, 24, 30, 7, 'Agência / Cod. do beneficiário', $boleto->getAgenciaCodigoCedente());
        //Motivo de nao entrega        
        $this->pdf->setTextBox(111, 24, 80, 21, 'Motivo de não entrega', $this->fontLabel);
        $this->pdf->Text(116, 32, utf8_decode('(  ) Mudou-se        (  ) Ausente            (  ) Não existe Nº'));
        $this->pdf->Text(116, 37, utf8_decode('(  ) Recusado        (  ) Não procurado (  ) Endereço insuficiente'));
        $this->pdf->Text(116, 42, utf8_decode('(  ) Desconhecido (  ) Falecido            (  ) Outros (Anotar no verso)'));
        //Pagador        
        $this->addTextBox(13, 31, 68, 7, 'Pagador', $boleto->getSacado()->getNome());
        //Nosso Numero        
        $this->addTextBox(81, 31, 30, 7, 'Nosso número', $boleto->getNossoNumero(), 'R');
        //Vencimento
        $this->addTextBox(13, 38, 24, 7, 'Vencimento', $boleto->getDataVencimento()->format('d/m/Y'));
        //Num Documento
        $this->addTextBox(37, 38, 30, 7, 'Nº documento', $boleto->getNumeroDocumento());
        //Especie
        $this->addTextBox(67, 38, 14, 7, 'Espécie', $boleto::$especie[$boleto->getMoeda()]);
        //Valor do Documento      
        $this->addTextBox(81, 38, 30, 7, 'Valor do Documento', $boleto::formataDinheiro($boleto->getValor()), 'R');
        //Recebemos o titulo
        $this->addTextBox(13, 45, 68, 7, 'Recebemos o título', 'Com as características acima');
        //Data
        $this->addTextBox(81, 45, 30, 7, 'Data', '');
        //Assinatura
        $this->addTextBox(111, 45, 80, 7, 'Assinatura', '');
        //Data Processamento
        $this->addTextBox(13, 52, 24, 7, 'Data processamento', $boleto->getDataProcessamento()->format('d/m/Y'));
        //Local de pagamento
        $this->addTextBox(37, 52, 154, 7, 'Local de pagamento', $boleto->getLocalPagamento());
        //Linha Pontilhada
        $this->addLinhaPontilhada(66);

        $this->posY = 72;
    }

    private function addCabecalhoBoleto(\PHPBoletoPrinter\BoletoAbstract $boleto)
    {
        $y = $this->posY;
        $this->addTopoBoleto($boleto, $y + 1);

        //Cedente
        $this->addTextBox(13, $y + 12, 79, 7, 'Cedente', $boleto->getCedente()->getNome());
        //CPF/CNPJ
        $this->addTextBox(92, $y + 12, 32, 7, 'CPF/CNPJ', $boleto->getCedente()->getDocumento());
        //Agencia cod cedente
        $this->addTextBox(124, $y + 12, 34, 7, 'Agência / Cód. do Cedente', $boleto->getAgenciaCodigoCedente(), 'R');
        //Vencimento      
        $this->addTextBox(158, $y + 12, 32, 7, 'Vencimento', $boleto->getDataVencimento()->format('d/m/Y'), 'R');
        //Sacado
        $this->addTextBox(13, $y + 19, 111, 7, 'Sacado', $boleto->getSacado()->getNome());
        //Num documento
        $this->addTextBox(124, $y + 19, 34, 7, 'Nº Documento', $boleto->getNumeroDocumento(), 'R');
        //Nosso numero      
        $this->addTextBox(158, $y + 19, 32, 7, 'Nosso número', $boleto->getNossoNumero(), 'R');
        //Especie
        $this->addTextBox(13, $y + 26, 33, 7, 'Espécie', $boleto::$especie[$boleto->getMoeda()]);
        //Quantidade
        $this->addTextBox(46, $y + 26, 46, 7, 'Quantidade', $boleto->getQuantidade(), 'R');
        //Valor unitario
        $this->addTextBox(92, $y + 26, 32, 7, 'Valor', $boleto::formataDinheiro($boleto->getValorUnitario()), 'R');
        //Descontos abatimentos
        $this->addTextBox(124, $y + 26, 34, 7, '(-) Descontos / Abatimentos', $boleto::formataDinheiro($boleto->getDescontosAbatimentos()), 'R');
        //Valor documento      
        $this->addTextBox(158, $y + 26, 32, 7, '(=) Valor Documento', $boleto::formataDinheiro($boleto->getValor()), 'R');


        //Demonstrativo
        $this->addTextBox(13, $y + 33, 79, 7, '', 'Demonstrativo');
        //Outras deducoes
        $this->addTextBox(92, $y + 33, 32, 7, '(-) Outras deduções', $boleto::formataDinheiro($boleto->getOutrasDeducoes()));
        //Outros acrescimos
        $this->addTextBox(124, $y + 33, 34, 7, '(+) Outros acréscimos', $boleto::formataDinheiro($boleto->getOutrosAcrescimos()), 'R');
        //Valor Cobrado      
        $this->addTextBox(158, $y + 33, 32, 7, '(=) Valor cobrado', $boleto::formataDinheiro($boleto->getValorCobrado()), 'R');

        //Observacoes
        $this->pdf->setTextBox(13, $y + 40, 177, 25, '');
        $this->pdf->SetFont('Arial', '', 7);
        $this->pdf->Text(160, $y + 44, utf8_decode('Autenticação mecanica'));

        $yLine = $y + 45;
        foreach ($boleto->getDescricaoDemonstrativo() as $descricao) {
            $this->pdf->SetFont('Arial', 'B', 8);
            $this->pdf->Text(15, $yLine, utf8_decode($descricao));
            $yLine += 3;
        }

        $this->addLinhaPontilhada($y + 72);

        $this->posY = $y + 77;
    }

    private function addFichaCompensacao(\PHPBoletoPrinter\BoletoAbstract $boleto)
    {
        $y = $this->posY;
        $this->addTopoBoleto($boleto, $y + 1);

        //Local de pagamento
        $this->addTextBox(13, $y + 12, 130, 7, 'Local de pagamento', $boleto->getLocalPagamento());
        //Vencimento      
        $this->addTextBox(143, $y + 12, 47, 7, 'Vencimento', $boleto->getDataVencimento()->format('d/m/Y'), 'R');
        //Cedente
        $this->addTextBox(13, $y + 19, 130, 7, 'Cedente', $boleto->getCedente()->getNome());
        //Agencia cod cedente
        $this->addTextBox(143, $y + 19, 47, 7, 'Agência / Cód. do Cedente', $boleto->getAgenciaCodigoCedente(), 'R');
        //Data documento      
        $this->addTextBox(13, $y + 26, 33, 7, 'Data do documento', $boleto->getDataDocumento()->format('d/m/Y'), 'R');
        //Num documento
        $this->addTextBox(46, $y + 26, 34, 7, 'Nº Documento', $boleto->getNumeroDocumento(), 'R');
        //Especie doc
        $this->addTextBox(80, $y + 26, 21, 7, 'Espécie doc', $boleto->getEspecieDoc());
        //Aceite
        $this->addTextBox(101, $y + 26, 10, 7, 'Aceite', $boleto->getAceite());
        //Data processamento      
        $this->addTextBox(111, $y + 26, 32, 7, 'Data processamento', $boleto->getDataProcessamento()->format('d/m/Y'), 'R');
        //Nosso numero      
        $this->addTextBox(143, $y + 26, 47, 7, 'Nosso número', $boleto->getNossoNumero(), 'R');
        //Uso do banco          
        $this->addTextBox(13, $y + 33, 33, 7, 'Uso do banco', $boleto->getUsoBanco(), 'R');
        //Carteira          
        $this->addTextBox(46, $y + 33, 20, 7, 'Carteira', $boleto->getCarteira(), 'R');
        //Especie
        $this->addTextBox(66, $y + 33, 14, 7, 'Espécie', $boleto::$especie[$boleto->getMoeda()]);
        //Quantidade
        $this->addTextBox(80, $y + 33, 31, 7, 'Quantidade', $boleto->getQuantidade(), 'R');
        //Valor unitario
        $this->addTextBox(111, $y + 33, 32, 7, 'Valor', $boleto::formataDinheiro($boleto->getValorUnitario()), 'R');
        //Valor documento      
        $this->addTextBox(143, $y + 33, 47, 7, '(=) Valor Documento', $boleto::formataDinheiro($boleto->getValor()), 'R');

        //Instrucoes
        $this->pdf->setTextBox(13, $y + 40, 177, 35, '');

        $yLine = $y + 45;
        foreach ($boleto->getInstrucoes() as $descricao) {
            $this->pdf->SetFont('Arial', 'B', 8);
            $this->pdf->Text(15, $yLine, utf8_decode($descricao));
            $yLine += 3;
        }

        //Descontos abatimentos
        $this->addTextBox(143, $y + 40, 47, 7, '(-) Descontos / Abatimentos', $boleto::formataDinheiro($boleto->getDescontosAbatimentos()), 'R');
        //Outras deducoes
        $this->addTextBox(143, $y + 47, 47, 7, '(-) Outras deduções', $boleto::formataDinheiro($boleto->getOutrasDeducoes()));
        //Mora
        $this->addTextBox(143, $y + 54, 47, 7, '(+) Mora / Multa', $boleto::formataDinheiro($boleto->getMoraMulta()));
        //Outros acrescimos
        $this->addTextBox(143, $y + 61, 47, 7, '(+) Outros acréscimos', $boleto::formataDinheiro($boleto->getOutrosAcrescimos()), 'R');
        //Valor Cobrado      
        $this->addTextBox(143, $y + 68, 47, 7, '(=) Valor cobrado', $boleto::formataDinheiro($boleto->getValorCobrado()), 'R');


        //Sacado
        $this->pdf->setTextBox(13, $y + 75, 177, 18, 'Sacado', $this->fontLabel);
        $this->pdf->SetFont('Arial', 'B', 7);
        //Nome
        $this->pdf->Text(14, $y + 81, utf8_decode($boleto->getSacado()->getNome()));
        //Endereco
        $this->pdf->Text(14, $y + 85, utf8_decode($boleto->getSacado()->getEndereco()));
        //Cep Cidade UF
        $this->pdf->Text(14, $y + 89, utf8_decode($boleto->getSacado()->getCepCidadeUf()));

        //Sacador Avalista
        $this->pdf->SetFont('Arial', '', 7);
        $this->pdf->Text(13, $y + 96, utf8_decode('Sacador / Avalista'));
        //Nome
        $this->pdf->Text(14, $y + 99, utf8_decode($boleto->getSacadorAvalista() ? $boleto->getSacadorAvalista()->getNomeDocumento() : ''));

        $this->pdf->SetFont('Arial', 'B', 7);
        $this->pdf->Text(133, $y + 96, utf8_decode('Autenticação mecanica - Ficha de compensação'));

        //$this->pdf->Text(13, $y + 102, $boleto->getNumeroFebraban());
        $this->pdf->BarCode(11, $y + 101, 115, 14, 'code25interleaved', $boleto->getCodigoDeBarras());

        $this->posY = 102;
    }

    public function Output($mode = 'I', $name = '')
    {
        $this->pdf->Output($mode, $name);
    }

    //Helpers

    /**
     * Adiciona caixa de texto
     * @param type $x
     * @param type $y
     * @param type $w
     * @param type $h
     * @param type $label
     * @param type $text
     */
    private function addTextBox($x, $y, $w, $h, $label, $text, $textAlign = 'L')
    {
        $this->pdf->setLabeledTextBox($x, $y, $w, $h, $label, utf8_decode($text), $this->fontLabel, $this->fontText, $textAlign);
    }

    /**
     * Adiciona topo do boleto
     * @param \PHPBoletoPrinter\BoletoAbstract $boleto
     * @param type $y
     * @param type $texto
     */
    private function addTopoBoleto(\PHPBoletoPrinter\BoletoAbstract $boleto, $y, $texto = null)
    {
        //Logo
        $pic = $boleto->getLogoBancoBase64();

        //Numero banco
        $this->pdf->Line(60, $y + 4, 60, $y + 11);
        $this->pdf->Line(77, $y + 4, 77, $y + 11);
        $this->pdf->SetFont('Arial', 'B', 13);
        $this->pdf->Text(63, $y + 9, $boleto->getCodigoBancoComDv());

        //Texto Lateral
        $this->pdf->Image($pic, 13, $y, 40, 10, 'jpg');
        if (!$texto) {
            $texto = $boleto->getLinhaDigitavel();
        }
        $font = $this->fontText;
        $font["size"] = 13;
        $this->pdf->setTextBox(81, $y + 2, 110, 7, $texto, $font, 'B', 'R', 0);
    }

    /**
     * Adiciona linha pontilhada
     * @param type $y
     */
    private function addLinhaPontilhada($y)
    {
        $this->pdf->SetFont('Arial', '', 7);
        $this->pdf->Text(163, $y, 'Corte na linha pontilhada');
        $this->pdf->DashedRect(13, $y + 1, 191, $y + 1, 0.1, 65);
    }
}
