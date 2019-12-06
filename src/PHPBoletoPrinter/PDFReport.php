<?php
/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */
namespace PHPBoletoPrinter;

use Zend\Barcode\Barcode;

class PDFReport extends \FPDF
{

    private $widths;
    private $aligns;
    private $types;
    //variables of html parser
    var $B;
    var $I;
    var $U;
    var $HREF;
    var $fontList;
    var $issetfont;
    var $issetcolor;
    //variables of Images with alpha
    var $tmpFiles = array();
    //variables of Barcode
    private $T128;                                             // tabela de codigos 128
    private $ABCset = "";                                        // conjunto de caracteres legiveis em 128
    private $Aset = "";                                          // grupo A do conjunto de de caracteres legiveis
    private $Bset = "";                                          // grupo B do conjunto de caracteres legiveis
    private $Cset = "";                                          // grupo C do conjunto de caracteres legiveis
    private $SetFrom;                                          // converter de
    private $SetTo;                                            // converter para
    private $JStart = array("A" => 103, "B" => 104, "C" => 105);     // Caracteres de seleção do grupo 128
    private $JSwap = array("A" => 101, "B" => 100, "C" => 99);       // Caracteres de troca de grupo

    /*     * ************************************************************************
     * 
     * Images With Alpha
     *     
     * ************************************************************************ */

    function Image($file, $x = null, $y = null, $w = 0, $h = 0, $type = '', $link = '', $isMask = false, $maskImg = 0)
    {
        //Put an image on the page 
        if (!isset($this->images[$file])) {
            //First use of image, get info 
            if ($type == '') {
                $pos = strrpos($file, '.');
                if (!$pos)
                    $this->Error('Image file has no extension and no type was specified: ' . $file);
                $type = substr($file, $pos + 1);
            }
            $type = strtolower($type);

            if ($type == 'jpg' || $type == 'jpeg')
                $info = $this->_parsejpg($file);
            elseif ($type == 'png') {
                $info = $this->_parsepng($file);
                if ($info == 'alpha')
                    return $this->ImagePngWithAlpha($file, $x, $y, $w, $h, $link);
            }
            else {
                //Allow for additional formats 
                $mtd = '_parse' . $type;
                if (!method_exists($this, $mtd))
                    $this->Error('Unsupported image type: ' . $type);
                $info = $this->$mtd($file);
            }


            if ($isMask) {
                $info['cs'] = "DeviceGray"; // try to force grayscale (instead of indexed) 
            }
            $info['i'] = count($this->images) + 1;
            if ($maskImg > 0)
                $info['masked'] = $maskImg;### 
            $this->images[$file] = $info;
        } else
            $info = $this->images[$file];
        //Automatic width and height calculation if needed 
        if ($w == 0 && $h == 0) {
            //Put image at 72 dpi 
            $w = $info['w'] / $this->k;
            $h = $info['h'] / $this->k;
        }
        if ($w == 0)
            $w = $h * $info['w'] / $info['h'];
        if ($h == 0)
            $h = $w * $info['h'] / $info['w'];

        // embed hidden, ouside the canvas 
        if ((float) FPDF_VERSION >= 1.7) {
            if ($isMask)
                $x = ($this->CurOrientation == 'P' ? $this->CurPageSize[0] : $this->CurPageSize[1]) + 10;
        }else {
            if ($isMask)
                $x = ($this->CurOrientation == 'P' ? $this->CurPageFormat[0] : $this->CurPageFormat[1]) + 10;
        }

        $this->_out(sprintf('q %.2f 0 0 %.2f %.2f %.2f cm /I%d Do Q', $w * $this->k, $h * $this->k, $x * $this->k, ($this->h - ($y + $h)) * $this->k, $info['i']));
        if ($link)
            $this->Link($x, $y, $w, $h, $link);

        return $info['i'];
    }

    // needs GD 2.x extension 
    // pixel-wise operation, not very fast 
    function ImagePngWithAlpha($file, $x, $y, $w = 0, $h = 0, $link = '')
    {
        $tmp_alpha = tempnam('.', 'mska');
        $this->tmpFiles[] = $tmp_alpha;
        $tmp_plain = tempnam('.', 'mskp');
        $this->tmpFiles[] = $tmp_plain;

        list($wpx, $hpx) = getimagesize($file);
        $img = imagecreatefrompng($file);
        $alpha_img = imagecreate($wpx, $hpx);

        // generate gray scale pallete 
        for ($c = 0; $c < 256; $c++)
            ImageColorAllocate($alpha_img, $c, $c, $c);

        // extract alpha channel 
        $xpx = 0;
        while ($xpx < $wpx) {
            $ypx = 0;
            while ($ypx < $hpx) {
                $color_index = imagecolorat($img, $xpx, $ypx);
                $alpha = 255 - ($color_index >> 24) * 255 / 127; // GD alpha component: 7 bit only, 0..127! 
                imagesetpixel($alpha_img, $xpx, $ypx, $alpha);
                ++$ypx;
            }
            ++$xpx;
        }

        imagepng($alpha_img, $tmp_alpha);
        imagedestroy($alpha_img);

        // extract image without alpha channel 
        $plain_img = imagecreatetruecolor($wpx, $hpx);
        imagecopy($plain_img, $img, 0, 0, 0, 0, $wpx, $hpx);
        imagepng($plain_img, $tmp_plain);
        imagedestroy($plain_img);

        //first embed mask image (w, h, x, will be ignored) 
        $maskImg = $this->Image($tmp_alpha, 0, 0, 0, 0, 'PNG', '', true);

        //embed image, masked with previously embedded mask 
        $this->Image($tmp_plain, $x, $y, $w, $h, 'PNG', $link, false, $maskImg);
    }

    function Close()
    {
        parent::Close();
        // clean up tmp files 
        foreach ($this->tmpFiles as $tmp)
            @unlink($tmp);
    }

    function _putimages()
    {
        $filter = ($this->compress) ? '/Filter /FlateDecode ' : '';
        reset($this->images);       
        foreach ($this->images as $file => $info) {
            $this->_newobj();
            $this->images[$file]['n'] = $this->n;
            $this->_out('<</Type /XObject');
            $this->_out('/Subtype /Image');
            $this->_out('/Width ' . $info['w']);
            $this->_out('/Height ' . $info['h']);

            if (isset($info["masked"]))
                $this->_out('/SMask ' . ($this->n - 1) . ' 0 R'); ### 

            if ($info['cs'] == 'Indexed')
                $this->_out('/ColorSpace [/Indexed /DeviceRGB ' . (strlen($info['pal']) / 3 - 1) . ' ' . ($this->n + 1) . ' 0 R]');
            else {
                $this->_out('/ColorSpace /' . $info['cs']);
                if ($info['cs'] == 'DeviceCMYK')
                    $this->_out('/Decode [1 0 1 0 1 0 1 0]');
            }
            $this->_out('/BitsPerComponent ' . $info['bpc']);
            if (isset($info['f']))
                $this->_out('/Filter /' . $info['f']);
            if (isset($info['parms']))
                $this->_out($info['parms']);
            if (isset($info['trns']) && is_array($info['trns'])) {
                $trns = '';
                for ($i = 0; $i < count($info['trns']); $i++)
                    $trns .= $info['trns'][$i] . ' ' . $info['trns'][$i] . ' ';
                $this->_out('/Mask [' . $trns . ']');
            }
            $this->_out('/Length ' . strlen($info['data']) . '>>');
            $this->_putstream($info['data']);
            unset($this->images[$file]['data']);
            $this->_out('endobj');
            //Palette 
            if ($info['cs'] == 'Indexed') {
                $this->_newobj();
                $pal = ($this->compress) ? gzcompress($info['pal']) : $info['pal'];
                $this->_out('<<' . $filter . '/Length ' . strlen($pal) . '>>');
                $this->_putstream($pal);
                $this->_out('endobj');
            }
        }
    }

    // this method overwriing the original version is only needed to make the Image method support PNGs with alpha channels. 
    // if you only use the ImagePngWithAlpha method for such PNGs, you can remove it from this script. 
    function _parsepng($file)
    {
        //Extract info from a PNG file 
        $f = fopen($file, 'rb');
        if (!$f)
            $this->Error('Can\'t open image file: ' . $file);
        //Check signature 
        if (fread($f, 8) != chr(137) . 'PNG' . chr(13) . chr(10) . chr(26) . chr(10))
            $this->Error('Not a PNG file: ' . $file);
        //Read header chunk 
        fread($f, 4);
        if (fread($f, 4) != 'IHDR')
            $this->Error('Incorrect PNG file: ' . $file);
        $w = $this->_readint($f);
        $h = $this->_readint($f);
        $bpc = ord(fread($f, 1));
        if ($bpc > 8)
            $this->Error('16-bit depth not supported: ' . $file);
        $ct = ord(fread($f, 1));
        if ($ct == 0)
            $colspace = 'DeviceGray';
        elseif ($ct == 2)
            $colspace = 'DeviceRGB';
        elseif ($ct == 3)
            $colspace = 'Indexed';
        else {
            fclose($f);      // the only changes are  
            return 'alpha';  // made in those 2 lines 
        }
        if (ord(fread($f, 1)) != 0)
            $this->Error('Unknown compression method: ' . $file);
        if (ord(fread($f, 1)) != 0)
            $this->Error('Unknown filter method: ' . $file);
        if (ord(fread($f, 1)) != 0)
            $this->Error('Interlacing not supported: ' . $file);
        fread($f, 4);
        $parms = '/DecodeParms <</Predictor 15 /Colors ' . ($ct == 2 ? 3 : 1) . ' /BitsPerComponent ' . $bpc . ' /Columns ' . $w . '>>';
        //Scan chunks looking for palette, transparency and image data 
        $pal = '';
        $trns = '';
        $data = '';
        do {
            $n = $this->_readint($f);
            $type = fread($f, 4);
            if ($type == 'PLTE') {
                //Read palette 
                $pal = fread($f, $n);
                fread($f, 4);
            } elseif ($type == 'tRNS') {
                //Read transparency info 
                $t = fread($f, $n);
                if ($ct == 0)
                    $trns = array(ord(substr($t, 1, 1)));
                elseif ($ct == 2)
                    $trns = array(ord(substr($t, 1, 1)), ord(substr($t, 3, 1)), ord(substr($t, 5, 1)));
                else {
                    $pos = strpos($t, chr(0));
                    if ($pos !== false)
                        $trns = array($pos);
                }
                fread($f, 4);
            }
            elseif ($type == 'IDAT') {
                //Read image data block 
                $data .= fread($f, $n);
                fread($f, 4);
            } elseif ($type == 'IEND')
                break;
            else
                fread($f, $n + 4);
        }
        while ($n);
        if ($colspace == 'Indexed' && empty($pal))
            $this->Error('Missing palette in ' . $file);
        fclose($f);
        return array('w' => $w, 'h' => $h, 'cs' => $colspace, 'bpc' => $bpc, 'f' => 'FlateDecode', 'parms' => $parms, 'pal' => $pal, 'trns' => $trns, 'data' => $data);
    }
    /*     * ************************************************************************
     * Fim Images With Alpha
     * ************************************************************************ */

    /*     * ************************************************************************
     * HTML Parser
     * ************************************************************************ */

    function txtentities($html)
    {
        $trans = get_html_translation_table(HTML_ENTITIES);
        $trans = array_flip($trans);
        return strtr($html, $trans);
    }

    function setHtml($html)
    {
        //HTML parser
        $html = strip_tags($html, "<h2><table><b><u><i><a><img><p><br><strong><em><font><tr><blockquote>"); //excessoes
        $html = str_replace("\n", ' ', $html); //nova linha
        $a = preg_split('/<(.*)>/U', $html, -1, PREG_SPLIT_DELIM_CAPTURE);
        foreach ($a as $i => $e) {
            if ($i % 2 == 0) {
                //Text
                if ($this->HREF) {
                    $this->PutLink($this->HREF, $e);
                } else {
                    $this->Write(5, stripslashes(utf8_decode($this->txtentities($e))));
                }
            } else {
                //Tag
                if ($e[0] == '/') {
                    $this->CloseTag(strtoupper(substr($e, 1)));
                } else {
                    //Extract attributes
                    $a2 = explode(' ', $e);
                    $tag = strtoupper(array_shift($a2));
                    $attr = array();
                    foreach ($a2 as $v) {
                        if (preg_match('/([^=]*)=["\']?([^"\']*)/', $v, $a3))
                            $attr[strtoupper($a3[1])] = $a3[2];
                    }
                    $this->OpenTag($tag, $attr);
                }
            }
        }
    }

    function OpenTag($tag, $attr)
    {
        //Opening tag
        switch ($tag) {
            case 'H2':
                $this->SetFont('Arial', 'B', 15);
                break;
            case 'STRONG':
                $this->SetStyle('B', true);
                break;
            case 'EM':
                $this->SetStyle('I', true);
                break;
            case 'B':
            case 'I':
            case 'U':
                $this->SetStyle($tag, true);
                break;
            case 'A':
                $this->HREF = $attr['HREF'];
                break;
            case 'IMG':
                if (isset($attr['SRC']) && (isset($attr['WIDTH']) || isset($attr['HEIGHT']))) {
                    if (!isset($attr['WIDTH']))
                        $attr['WIDTH'] = 0;
                    if (!isset($attr['HEIGHT']))
                        $attr['HEIGHT'] = 0;
                    $this->Image($attr['SRC'], $this->GetX(), $this->GetY(), px2mm($attr['WIDTH']), px2mm($attr['HEIGHT']));
                }
                break;
            case 'TR':
            case 'BLOCKQUOTE':
            case 'BR':
                $this->Ln(4);
                break;
            case 'P':
                $this->Ln(4);
                break;
            case 'FONT':
                if (isset($attr['COLOR']) && $attr['COLOR'] != '') {
                    $coul = hex2dec($attr['COLOR']);
                    $this->SetTextColor($coul['R'], $coul['V'], $coul['B']);
                    $this->issetcolor = true;
                }
                if (isset($attr['FACE']) && in_array(strtolower($attr['FACE']), $this->fontlist)) {
                    $this->SetFont(strtolower($attr['FACE']));
                    $this->issetfont = true;
                }
                break;
        }
    }

    function CloseTag($tag)
    {
        //Closing tag
        if ($tag == 'H2') {
            $this->SetFont('Arial', '', 9);
        }
        if ($tag == 'STRONG')
            $tag = 'B';
        if ($tag == 'EM')
            $tag = 'I';
        if ($tag == 'B' || $tag == 'I' || $tag == 'U')
            $this->SetStyle($tag, false);
        if ($tag == 'A')
            $this->HREF = '';
        if ($tag == 'FONT') {
            if ($this->issetcolor == true) {
                $this->SetTextColor(0);
            }
            if ($this->issetfont) {
                $this->SetFont('arial');
                $this->issetfont = false;
            }
        }
    }

    function SetStyle($tag, $enable)
    {
        //Modify style and select corresponding font
        $this->$tag += ($enable ? 1 : -1);
        $style = '';
        foreach (array('B', 'I', 'U') as $s) {
            if ($this->$s > 0)
                $style .= $s;
        }
        $this->SetFont('', $style);
    }

    function PutLink($URL, $txt)
    {
        //Put a hyperlink
        $this->SetTextColor(0, 0, 255);
        $this->SetStyle('U', true);
        $this->Write(5, $txt, $URL);
        $this->SetStyle('U', false);
        $this->SetTextColor(0);
    }
    /*     * ************************************************************************
     * Fim HTML Parser
     * ************************************************************************ */

    ///Cell with horizontal scaling if text is too wide
    function CellFit($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '', $scale = false, $force = true)
    {
        //Get string width
        $str_width = $this->GetStringWidth($txt);

        //Calculate ratio to fit cell
        if ($w == 0)
            $w = $this->w - $this->rMargin - $this->x;
        $ratio = ($w - $this->cMargin * 2) / $str_width;

        $fit = ($ratio < 1 || ($ratio > 1 && $force));
        if ($fit) {
            if ($scale) {
                //Calculate horizontal scaling
                $horiz_scale = $ratio * 100.0;
                //Set horizontal scaling
                $this->_out(sprintf('BT %.2F Tz ET', $horiz_scale));
            } else {
                //Calculate character spacing in points
                $char_space = ($w - $this->cMargin * 2 - $str_width) / max($this->MBGetStringLength($txt) - 1, 1) * $this->k;
                //Set character spacing
                $this->_out(sprintf('BT %.2F Tc ET', $char_space));
            }
            //Override user alignment (since text will fill up cell)
            $align = '';
        }

        //Pass on to Cell method
        $this->Cell($w, $h, $txt, $border, $ln, $align, $fill, $link);

        //Reset character spacing/horizontal scaling
        if ($fit)
            $this->_out('BT ' . ($scale ? '100 Tz' : '0 Tc') . ' ET');
    }

    //Cell with horizontal scaling always
    function CellFitScaleForce($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '')
    {
        $this->CellFit($w, $h, $txt, $border, $ln, $align, $fill, $link, true, true);
    }

    /**
     * Barcode
     * @param type $x
     * @param type $y
     * @param type $type
     * @param type $code
     * @param type $h
     * @param type $drawText
     */
    public function BarCode($x, $y, $w, $h, $type, $code, $drawText = false, $fontSize = 1)
    {
        $barcodeOptions = array(
            'text' => $code,
            'barHeight' => $h,
            'drawText' => $drawText,
            'font' => $fontSize
        );
        // No required options
        $rendererOptions = array();
        $renderer = Barcode::factory(
                $type, 'image', $barcodeOptions, $rendererOptions
        );
        $pathbarcode = __DIR__ . '/../../resources/images/barcodes/barcode_' . Date("YmdHis") . rand(0, 100) . '.png';
        imagepng($renderer->draw(), $pathbarcode);
        $this->Image($pathbarcode, $x, $y, $w, $h);
        chmod($pathbarcode, 0777);
        unlink($pathbarcode);
    }

    //fim Code128

    function RoundedRect($x, $y, $w, $h, $r, $style = '')
    {
        $k = $this->k;
        $hp = $this->h;
        if ($style == 'F')
            $op = 'f';
        elseif ($style == 'FD' || $style == 'DF')
            $op = 'B';
        else
            $op = 'S';
        $MyArc = 4 / 3 * (sqrt(2) - 1);
        $this->_out(sprintf('%.2F %.2F m', ($x + $r) * $k, ($hp - $y) * $k));
        $xc = $x + $w - $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - $y) * $k));

        $this->_Arc($xc + $r * $MyArc, $yc - $r, $xc + $r, $yc - $r * $MyArc, $xc + $r, $yc);
        $xc = $x + $w - $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', ($x + $w) * $k, ($hp - $yc) * $k));
        $this->_Arc($xc + $r, $yc + $r * $MyArc, $xc + $r * $MyArc, $yc + $r, $xc, $yc + $r);
        $xc = $x + $r;
        $yc = $y + $h - $r;
        $this->_out(sprintf('%.2F %.2F l', $xc * $k, ($hp - ($y + $h)) * $k));
        $this->_Arc($xc - $r * $MyArc, $yc + $r, $xc - $r, $yc + $r * $MyArc, $xc - $r, $yc);
        $xc = $x + $r;
        $yc = $y + $r;
        $this->_out(sprintf('%.2F %.2F l', ($x) * $k, ($hp - $yc) * $k));
        $this->_Arc($xc - $r, $yc - $r * $MyArc, $xc - $r * $MyArc, $yc - $r, $xc, $yc - $r);
        $this->_out($op);
    }

    function _Arc($x1, $y1, $x2, $y2, $x3, $y3)
    {
        $h = $this->h;
        $this->_out(sprintf('%.2F %.2F %.2F %.2F %.2F %.2F c ', $x1 * $this->k, ($h - $y1) * $this->k, $x2 * $this->k, ($h - $y2) * $this->k, $x3 * $this->k, ($h - $y3) * $this->k));
    }

    function DashedRect($x1, $y1, $x2, $y2, $width = 1, $nb = 15)
    {
        $this->SetLineWidth($width);
        $longueur = abs($x1 - $x2);
        $hauteur = abs($y1 - $y2);
        if ($longueur > $hauteur) {
            $Pointilles = ($longueur / $nb) / 2; // length of dashes
        } else {
            $Pointilles = ($hauteur / $nb) / 2;
        }
        for ($i = $x1; $i <= $x2; $i += $Pointilles + $Pointilles) {
            for ($j = $i; $j <= ($i + $Pointilles); $j++) {
                if ($j <= ($x2 - 1)) {
                    $this->Line($j, $y1, $j + 1, $y1); // upper dashes
                    $this->Line($j, $y2, $j + 1, $y2); // lower dashes
                }
            }
        }
        for ($i = $y1; $i <= $y2; $i += $Pointilles + $Pointilles) {
            for ($j = $i; $j <= ($i + $Pointilles); $j++) {
                if ($j <= ($y2 - 1)) {
                    $this->Line($x1, $j, $x1, $j + 1); // left dashes
                    $this->Line($x2, $j, $x2, $j + 1); // right dashes
                }
            }
        }
    }

    function drawTextBox($strText, $w, $h, $align = 'L', $valign = 'T', $border = true)
    {
        $xi = $this->GetX();
        $yi = $this->GetY();

        $hrow = $this->FontSize;
        $textrows = $this->drawRows($w, $hrow, $strText, 0, $align, 0, 0, 0);
        $maxrows = floor($h / $this->FontSize);
        $rows = min($textrows, $maxrows);

        $dy = 0;
        if (strtoupper($valign) == 'M')
            $dy = ($h - $rows * $this->FontSize) / 2;
        if (strtoupper($valign) == 'B')
            $dy = $h - $rows * $this->FontSize;

        $this->SetY($yi + $dy);
        $this->SetX($xi);

        $this->drawRows($w, $hrow, $strText, 0, $align, false, $rows, 1);

        if ($border)
            $this->Rect($xi, $yi, $w, $h);
    }

    function drawRows($w, $h, $txt, $border = 0, $align = 'J', $fill = false, $maxline = 0, $prn = 0)
    {
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0)
            $w = $this->w - $this->rMargin - $this->x;
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 && $s[$nb - 1] == "\n")
            $nb--;
        $b = 0;
        if ($border) {
            if ($border == 1) {
                $border = 'LTRB';
                $b = 'LRT';
                $b2 = 'LR';
            } else {
                $b2 = '';
                if (is_int(strpos($border, 'L')))
                    $b2 .= 'L';
                if (is_int(strpos($border, 'R')))
                    $b2 .= 'R';
                $b = is_int(strpos($border, 'T')) ? $b2 . 'T' : $b2;
            }
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $ns = 0;
        $nl = 1;
        while ($i < $nb) {
            //Get next character
            $c = $s[$i];
            if ($c == "\n") {
                //Explicit line break
                if ($this->ws > 0) {
                    $this->ws = 0;
                    if ($prn == 1)
                        $this->_out('0 Tw');
                }
                if ($prn == 1) {
                    $this->Cell($w, $h, substr($s, $j, $i - $j), $b, 2, $align, $fill);
                }
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $ns = 0;
                $nl++;
                if ($border && $nl == 2)
                    $b = $b2;
                if ($maxline && $nl > $maxline)
                    return substr($s, $i);
                continue;
            }
            if ($c == ' ') {
                $sep = $i;
                $ls = $l;
                $ns++;
            }
            $l += $cw[$c];
            if ($l > $wmax) {
                //Automatic line break
                if ($sep == -1) {
                    if ($i == $j)
                        $i++;
                    if ($this->ws > 0) {
                        $this->ws = 0;
                        if ($prn == 1)
                            $this->_out('0 Tw');
                    }
                    if ($prn == 1) {
                        $this->Cell($w, $h, substr($s, $j, $i - $j), $b, 2, $align, $fill);
                    }
                } else {
                    if ($align == 'J') {
                        $this->ws = ($ns > 1) ? ($wmax - $ls) / 1000 * $this->FontSize / ($ns - 1) : 0;
                        if ($prn == 1)
                            $this->_out(sprintf('%.3F Tw', $this->ws * $this->k));
                    }
                    if ($prn == 1) {
                        $this->Cell($w, $h, substr($s, $j, $sep - $j), $b, 2, $align, $fill);
                    }
                    $i = $sep + 1;
                }
                $sep = -1;
                $j = $i;
                $l = 0;
                $ns = 0;
                $nl++;
                if ($border && $nl == 2)
                    $b = $b2;
                if ($maxline && $nl > $maxline)
                    return substr($s, $i);
            } else
                $i++;
        }
        //Last chunk
        if ($this->ws > 0) {
            $this->ws = 0;
            if ($prn == 1)
                $this->_out('0 Tw');
        }
        if ($border && is_int(strpos($border, 'B')))
            $b .= 'B';
        if ($prn == 1) {
            $this->Cell($w, $h, substr($s, $j, $i - $j), $b, 2, $align, $fill);
        }
        $this->x = $this->lMargin;
        return $nl;
    }

    function WordWrap(&$text, $maxwidth)
    {
        $text = trim($text);
        if ($text === '')
            return 0;
        $space = $this->GetStringWidth(' ');
        $lines = explode("\n", $text);
        $text = '';
        $count = 0;

        foreach ($lines as $line) {
            $words = preg_split('/ +/', $line);
            $width = 0;

            foreach ($words as $word) {
                $wordwidth = $this->GetStringWidth($word);
                if ($wordwidth > $maxwidth) {
                    // Word is too long, we cut it
                    for ($i = 0; $i < strlen($word); $i++) {
                        $wordwidth = $this->GetStringWidth(substr($word, $i, 1));
                        if ($width + $wordwidth <= $maxwidth) {
                            $width += $wordwidth;
                            $text .= substr($word, $i, 1);
                        } else {
                            $width = $wordwidth;
                            $text = rtrim($text) . "\n" . substr($word, $i, 1);
                            $count++;
                        }
                    }
                } elseif ($width + $wordwidth <= $maxwidth) {
                    $width += $wordwidth + $space;
                    $text .= $word . ' ';
                } else {
                    $width = $wordwidth + $space;
                    $text = rtrim($text) . "\n" . $word . ' ';
                    $count++;
                }
            }
            $text = rtrim($text) . "\n";
            $count++;
        }
        $text = rtrim($text);
        return $count;
    }

    //Cell with horizontal scaling only if necessary
    function CellFitScale($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '')
    {
        $this->CellFit($w, $h, $txt, $border, $ln, $align, $fill, $link, true, false);
    }

    //Cell with character spacing only if necessary
    function CellFitSpace($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '')
    {
        $this->CellFit($w, $h, $txt, $border, $ln, $align, $fill, $link, false, false);
    }

    //Cell with character spacing always
    function CellFitSpaceForce($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '')
    {
        //Same as calling CellFit directly
        $this->CellFit($w, $h, $txt, $border, $ln, $align, $fill, $link, false, true);
    }

    //Patch to also work with CJK double-byte text
    function MBGetStringLength($s)
    {
        if ($this->CurrentFont['type'] == 'Type0') {
            $len = 0;
            $nbbytes = strlen($s);
            for ($i = 0; $i < $nbbytes; $i++) {
                if (ord($s[$i]) < 128)
                    $len++;
                else {
                    $len++;
                    $i++;
                }
            }
            return $len;
        } else
            return strlen($s);
    }
    /*     * *************************************************************************
     * Fim Barcode
     * *********************************************************************** */

    function SetWidths($w)
    {
        //Set the array of column widths
        $this->widths = $w;
    }

    function SetAligns($a)
    {
        //Set the array of column alignments
        $this->aligns = $a;
    }

    function SetTypes($t)
    {
        //Set the array of column alignments
        $this->types = $t;
    }

    function Row($data, $line_width = 0.1, $line_spacing = 5)
    {
        //Calculate the height of the row
        $nb = 0;
        for ($i = 0; $i < count($data); $i++) {
            $nb = max($nb, $this->NbLines($this->widths[$i], $data[$i]));
        }
        $h = $line_spacing * $nb;
        //Issue a page break first if needed
        $this->CheckPageBreak($h);
        //Draw the cells of the row
        for ($i = 0; $i < count($data); $i++) {
            $w = $this->widths[$i];
            $a = isset($this->aligns[$i]) ? $this->aligns[$i] : 'L';
            $t = isset($this->types[$i]) ? $this->types[$i] : 'string';

            //Save the current position
            $x = $this->GetX();
            $y = $this->GetY();

            if ($line_width > 0) {
                //Draw the border
                $this->SetLineWidth($line_width);
                $this->Rect($x, $y, $w, $h);
            }

            //Format type
            $data[$i] = $this->formatTextType($data[$i], $t);

            //Print the text
            $encodingText = mb_detect_encoding($data[$i], 'UTF-8, ISO-8859-1,ASCII');
            if ($encodingText === 'UTF-8') {
                $data[$i] = utf8_decode($data[$i]);
            }
            $this->MultiCell($w, $line_spacing, $data[$i], 0, $a);
            //Put the position to the right of the cell
            $this->SetXY($x + $w, $y);
        }
        //Go to the next line
        $this->Ln($h);
    }

    function formatTextType($text, $type)
    {
        switch ($type) {
            case "string":
            default :
                return $text;
            case "date":
                return Date("d/m/Y", strtotime($text));
            case "integer":
                return number_format($text, 0);
            case "double2":
                return number_format($text, 2, ',', '.');
            case "double3":
                return number_format($text, 3, ',', '.');
        }
    }

    function CheckPageBreak($h)
    {
        //If the height h would cause an overflow, add a new page immediately
        if ($this->GetY() + $h > $this->PageBreakTrigger)
            $this->AddPage($this->CurOrientation);
    }

    function NbLines($w, $txt)
    {
        //Computes the number of lines a MultiCell of width w will take
        $cw = &$this->CurrentFont['cw'];
        if ($w == 0) {
            $w = $this->w - $this->rMargin - $this->x;
        }
        $wmax = ($w - 2 * $this->cMargin) * 1000 / $this->FontSize;
        $s = str_replace("\r", '', $txt);
        $nb = strlen($s);
        if ($nb > 0 and $s[$nb - 1] == "\n") {
            $nb--;
        }
        $sep = -1;
        $i = 0;
        $j = 0;
        $l = 0;
        $nl = 1;
        while ($i < $nb) {
            $c = $s[$i];
            if ($c == "\n") {
                $i++;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
                continue;
            }
            if ($c == ' ')
                $sep = $i;
            $l += $cw[$c];
            if ($l > $wmax) {
                if ($sep == -1) {
                    if ($i == $j)
                        $i++;
                } else
                    $i = $sep + 1;
                $sep = -1;
                $j = $i;
                $l = 0;
                $nl++;
            } else
                $i++;
        }
        return $nl;
    }

    public function setParams()
    {
        $this->AliasNbPages();
        $this->SetMargins(5, 6, 5);
        $this->AddPage('P', 'A4');
        $this->SetFont('Arial', '', 9);
    }

    public function setLine($text = null)
    {
        $yPoint = $this->GetY() - 5;
        if ($text) {
            $this->Ln(5);
            $this->SetFont('Arial', 'B', 8);
            $this->Cell(1, 0, $text, 0, 0, 'L');
            $this->Ln(4);
            $yPoint = $this->GetY();
        }
        $this->Line(220, $yPoint, 0, $yPoint);
        $this->Ln(4);
    }

    public function setText($text = null, $fontSize = 8, $fontStyle = 'B')
    {
        $yPoint = $this->GetY() - 5;
        if ($text) {
            $this->Ln(5);
            $this->SetFont('Arial', $fontStyle, $fontSize);
            $this->Cell(1, 0, $text, 0, 0, 'L');
            $this->Ln(4);
            $yPoint = $this->GetY();
        }
        $this->Ln(4);
    }

    /**
     * setTextBox
     * Cria uma caixa de texto com ou sem bordas. Esta função perimite o alinhamento horizontal
     * ou vertical do texto dentro da caixa.
     * Atenção : Esta função é dependente de outras classes de FPDF
     * Ex. $this->setTextBox(2,20,34,8,'Texto',array('fonte'=>$this->fontePadrao,
     * 'size'=>10,'style='B'),'C','L',FALSE,'http://www.nfephp.org')
     *
     * @param number $x Posição horizontal da caixa, canto esquerdo superior
     * @param number $y Posição vertical da caixa, canto esquerdo superior
     * @param number $w Largura da caixa
     * @param number $h Altura da caixa
     * @param string $text Conteúdo da caixa
     * @param array $aFont Matriz com as informações para formatação do texto com fonte, tamanho e estilo
     * @param string $vAlign Alinhamento vertical do texto, T-topo C-centro B-base
     * @param string $hAlign Alinhamento horizontal do texto, L-esquerda, C-centro, R-direita
     * @param boolean $border TRUE ou 1 desenha a borda, FALSE ou 0 Sem borda
     * @param string $link Insere um hiperlink
     * @param boolean $force Se for true força a caixa com uma unica linha
     * e para isso atera o tamanho do fonte até caber no espaço,
     * se falso mantem o tamanho do fonte e usa quantas linhas forem necessárias
     * @param number $hmax
     * @param number $vOffSet incremento forçado na na posição Y
     * @return number $height Qual a altura necessária para desenhar esta textBox
     */
    public function setTextBox(
    $x, $y, $w, $h, $text = '', $aFont = array('font' => 'Times', 'size' => 8, 'style' => ''), $vAlign = 'T', $hAlign = 'L', $border = 1, $link = '', $force = true, $hmax = 0, $vOffSet = 0
    )
    {
        $oldY = $y;
        $temObs = false;
        $resetou = false;
        if ($w < 0) {
            return $y;
        }
        if (is_object($text)) {
            $text = '';
        }
        if (is_string($text)) {
            //remover espaços desnecessários
            $text = trim($text);
            //converter o charset para o fpdf
            $text = utf8_decode($text);
        } else {
            $text = (string) $text;
        }
        //desenhar a borda da caixa
        if ($border) {
            $this->RoundedRect($x, $y, $w, $h, 0, '1234', 'D');
        }
        //estabelecer o fonte
        $this->SetFont($aFont['font'], $aFont['style'], $aFont['size']);
        //calcular o incremento
        $incY = $this->FontSize; //tamanho da fonte na unidade definida
        if (!$force) {
            //verificar se o texto cabe no espaço
            $n = $this->WordWrap($text, $w);
        } else {
            $n = 1;
        }
        //calcular a altura do conjunto de texto
        $altText = $incY * $n;
        //separar o texto em linhas
        $lines = explode("\n", $text);
        //verificar o alinhamento vertical
        if ($vAlign == 'T') {
            //alinhado ao topo
            $y1 = $y + $incY;
        }
        if ($vAlign == 'C') {
            //alinhado ao centro
            $y1 = $y + $incY + (($h - $altText) / 2);
        }
        if ($vAlign == 'B') {
            //alinhado a base
            $y1 = ($y + $h) - 0.5;
        }
        //para cada linha
        foreach ($lines as $line) {
            //verificar o comprimento da frase
            $texto = trim($line);
            $comp = $this->GetStringWidth($texto);
            if ($force) {
                $newSize = $aFont['size'];
                while ($comp > $w) {
                    //estabelecer novo fonte
                    $this->SetFont($aFont['font'], $aFont['style'], --$newSize);
                    $comp = $this->GetStringWidth($texto);
                }
            }
            //ajustar ao alinhamento horizontal
            if ($hAlign == 'L') {
                $x1 = $x + 0.6;
            }
            if ($hAlign == 'C') {
                $x1 = $x + (($w - $comp) / 2);
            }
            if ($hAlign == 'R') {
                $x1 = $x + $w - ($comp + 0.5);
            }
            //escrever o texto
            if ($vOffSet > 0) {
                if ($y1 > ($oldY + $vOffSet)) {
                    if (!$resetou) {
                        $y1 = $oldY;
                        $resetou = true;
                    }
                    $this->Text($x1, $y1, $texto);
                }
            } else {
                $this->Text($x1, $y1, $texto);
            }
            //incrementar para escrever o proximo
            $y1 += $incY;
            if (($hmax > 0) && ($y1 > ($y + ($hmax - 1)))) {
                $temObs = true;
                break;
            }
        }
        return ($y1 - $y) - $incY;
    }

    /**
     * Cria uma caixa de texto com um Label e um Texto
     * @param type $x
     * @param type $y
     * @param type $w
     * @param type $h
     * @param type $label
     * @param type $text
     * @param type $labelFont
     * @param type $textFont
     */
    public function setLabeledTextBox(
    $x, $y, $w, $h, $label = '', $text = '', $labelFont = array('font' => 'Times', 'size' => 8, 'style' => ''), $textFont = array('font' => 'Times', 'size' => 8, 'style' => ''), $textAlign = 'L'
    )
    {
        $this->setTextBox($x, $y, $w, $h, $label, $labelFont, 'T', 'L', 0.1, '', true, 0, 0);
        $this->SetFont($textFont['font'], $textFont['style'], $textFont['size']);

        $comp = $this->GetStringWidth($text);
        if ($textAlign === 'L') {
            $x1 = $x + 0.6;
        }
        if ($textAlign === 'C') {
            $x1 = $x + (($w - $comp) / 2);
        }
        if ($textAlign === 'R') {
            $x1 = $x + $w - ($comp + 0.7);
        }
        $this->Text($x1, $y + 6, $text);
    }

    public function setDados(array $dados, $espacamento = 0, $fontFamily = 'Arial', $font_style = '')
    {
        foreach ($dados as $key => $value) {
            if ($key <> $value) {
                $this->SetFont($fontFamily, 'B', 8);
                $this->Cell(20 + $espacamento, 0, $key . ':', 0, 0);
                $this->SetFont($fontFamily, $font_style, 8);
                $this->Cell(40, 0, utf8_decode($value), 0, 0);
            }
            $this->Ln(4);
        }
        $this->Ln(4);
    }

    public function setTabela(array $titulos, array $dados, array $width_colunas, $borda = 0, $fontSize = 7, $line_spacing = 5, $fontFamily = 'Arial', $font_style = '')
    {
        $this->SetWidths($width_colunas);

        //Colunas
        $this->SetFont($fontFamily, 'B', $fontSize);
        $this->Row($titulos, $borda);

        //Linhas
        $this->SetFont($fontFamily, $font_style, $fontSize);
        foreach ($dados as $linha) {
            $this->Row($linha, $borda, $line_spacing);
        }
    }

    public function setTotais(array $totais)
    {
        foreach ($totais as $key => $value) {
            $this->SetFont('Arial', 'B', 8);
            $this->Cell(150, 10, $key, 0, 0, 'R');
            $this->SetFont('Arial', '', 8);
            $this->Cell(40, 10, $value, 0, 0);
            $this->Ln(4);
        }
        $this->Ln(10);
    }

    public function valorPorExtenso($valor = 0, $bolExibirMoeda = true, $bolPalavraFeminina = false)
    {

        $valor = $this->removerFormatacaoNumero($valor);

        $singular = null;
        $plural = null;

        if ($bolExibirMoeda) {
            $singular = array("centavo", "real", "mil", "milhao", "bilhao", "trilhao", "quatrilhao");
            $plural = array("centavos", "reais", "mil", "milhoes", "bilhoes", "trilhoes", "quatrilhoes");
        } else {
            $singular = array("", "", "mil", "milhao", "bilhao", "trilhao", "quatrilhao");
            $plural = array("", "", "mil", "milhoes", "bilhoes", "trilhoes", "quatrilhoes");
        }

        $c = array("", "cem", "duzentos", "trezentos", "quatrocentos", "quinhentos", "seiscentos", "setecentos", "oitocentos", "novecentos");
        $d = array("", "dez", "vinte", "trinta", "quarenta", "cinquenta", "sessenta", "setenta", "oitenta", "noventa");
        $d10 = array("dez", "onze", "doze", "treze", "quatorze", "quinze", "dezesseis", "dezesete", "dezoito", "dezenove");
        $u = array("", "um", "dois", "tres", "quatro", "cinco", "seis", "sete", "oito", "nove");


        if ($bolPalavraFeminina) {

            if ($valor == 1) {
                $u = array("", "uma", "duas", "tres", "quatro", "cinco", "seis", "sete", "oito", "nove");
            } else {
                $u = array("", "um", "duas", "tres", "quatro", "cinco", "seis", "sete", "oito", "nove");
            }


            $c = array("", "cem", "duzentas", "trezentas", "quatrocentas", "quinhentas", "seiscentas", "setecentas", "oitocentas", "novecentas");
        }


        $z = 0;

        $valor = number_format($valor, 2, ".", ".");
        $inteiro = explode(".", $valor);

        for ($i = 0; $i < count($inteiro); $i++) {
            for ($ii = mb_strlen($inteiro[$i]); $ii < 3; $ii++) {
                $inteiro[$i] = "0" . $inteiro[$i];
            }
        }

        // $fim identifica onde que deve se dar jun褯 de centenas por "e" ou por "," ;)
        $rt = null;
        $fim = count($inteiro) - ($inteiro[count($inteiro) - 1] > 0 ? 1 : 2);
        for ($i = 0; $i < count($inteiro); $i++) {
            $valor = $inteiro[$i];
            $rc = (($valor > 100) && ($valor < 200)) ? "cento" : $c[$valor[0]];
            $rd = ($valor[1] < 2) ? "" : $d[$valor[1]];
            $ru = ($valor > 0) ? (($valor[1] == 1) ? $d10[$valor[2]] : $u[$valor[2]]) : "";

            $r = $rc . (($rc && ($rd || $ru)) ? " e " : "") . $rd . (($rd && $ru) ? " e " : "") . $ru;
            $t = count($inteiro) - 1 - $i;
            $r .= $r ? " " . ($valor > 1 ? $plural[$t] : $singular[$t]) : "";
            if ($valor == "000")
                $z++;
            elseif ($z > 0)
                $z--;

            if (($t == 1) && ($z > 0) && ($inteiro[0] > 0))
                $r .= ( ($z > 1) ? " de " : "") . $plural[$t];

            if ($r)
                $rt = $rt . ((($i > 0) && ($i <= $fim) && ($inteiro[0] > 0) && ($z < 1)) ? ( ($i < $fim) ? ", " : " e ") : " ") . $r;
        }

        $rt = mb_substr($rt, 1);

        return($rt ? trim($rt) : "zero");
    }

    public function removerFormatacaoNumero($strNumero)
    {

        $strNumero = trim(str_replace("R$", null, $strNumero));

        $vetVirgula = explode(",", $strNumero);
        if (count($vetVirgula) == 1) {
            $acentos = array(".");
            $resultado = str_replace($acentos, "", $strNumero);
            return $resultado;
        } else if (count($vetVirgula) != 2) {
            return $strNumero;
        }

        $strNumero = $vetVirgula[0];
        $strDecimal = mb_substr($vetVirgula[1], 0, 2);

        $acentos = array(".");
        $resultado = str_replace($acentos, "", $strNumero);
        $resultado = $resultado . "." . $strDecimal;

        return $resultado;
    }

    public function setObservacoes($dados, $width = 40)
    {
        foreach ($dados as $key => $value) {
            $this->SetFont('Arial', 'B', 8);
            $this->Cell($width, 3, $key, 0, 0);
            $this->SetFont('Arial', '', 8);
            $this->MultiCell(0, 3, ($value), 0, 'L');
            $this->Ln(1);
        }
    }

    public function outputPDF($filename, $mode)
    {
        if ($mode === 'F') {
            $path = BASEPATH . '../outputfiles/' . $_SESSION["identificacao"] . '/pdf/';
        } else {
            $path = '';
        }
        $this->Output($path . $filename, $mode);
    }
}
