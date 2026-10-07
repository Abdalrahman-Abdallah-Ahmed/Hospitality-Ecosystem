<?php

/**
 * Rebuilds the binary fixtures in this directory:
 *
 *     php tests/Fixtures/knowledge/build.php
 *
 * The files are committed; this script exists so they can be regenerated and
 * so it is clear exactly what each one contains. PDFs are written by hand
 * (no PDF library is installed): a text page uses Helvetica, the Arabic page
 * maps its byte codes to Arabic presentation forms through a ToUnicode CMap
 * (which is what many real PDFs store), and scanned pages hold only an image.
 */

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

require __DIR__.'/../../../vendor/autoload.php';

$dir = __DIR__;

// ---------------------------------------------------------------- PDF helpers

/**
 * @param  list<string>  $objects  object bodies, numbered from 1
 */
function pdfDocument(array $objects, string $trailerExtra = ''): string
{
    $out = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
    $offsets = [];

    foreach ($objects as $i => $body) {
        $offsets[$i + 1] = strlen($out);
        $out .= ($i + 1)." 0 obj\n{$body}\nendobj\n";
    }

    $xref = strlen($out);
    $out .= 'xref'."\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";

    foreach ($offsets as $offset) {
        $out .= sprintf("%010d 00000 n \n", $offset);
    }

    $out .= 'trailer'."\n<< /Size ".(count($objects) + 1)." /Root 1 0 R {$trailerExtra} >>\nstartxref\n{$xref}\n%%EOF\n";

    return $out;
}

function pdfStream(string $data, string $dict = ''): string
{
    return '<< /Length '.strlen($data)." {$dict} >>\nstream\n{$data}\nendstream";
}

function pdfEscape(string $text): string
{
    return str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], $text);
}

/**
 * A PDF whose pages are either text (list of lines) or null (image only).
 *
 * @param  list<list<string>|null>  $pages
 */
function textPdf(array $pages, string $trailerExtra = ''): string
{
    $objects = [];
    $objects[] = '<< /Type /Catalog /Pages 2 0 R >>';
    $objects[] = ''; // pages, filled below
    $objects[] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';

    $jpeg = tinyJpeg();
    $objects[] = pdfStream($jpeg, '/Type /XObject /Subtype /Image /Width 8 /Height 8 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode');

    $kids = [];

    foreach ($pages as $lines) {
        if ($lines === null) {
            $content = 'q 400 0 0 400 100 300 cm /Im1 Do Q';
        } else {
            $content = "BT /F1 12 Tf 72 760 Td 16 TL\n";
            foreach ($lines as $line) {
                $content .= '('.pdfEscape($line).") Tj T*\n";
            }
            $content .= 'ET';
        }

        $objects[] = pdfStream($content);
        $contentId = count($objects);
        $objects[] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents {$contentId} 0 R "
            .'/Resources << /Font << /F1 3 0 R >> /XObject << /Im1 4 0 R >> >> >>';
        $kids[] = count($objects).' 0 R';
    }

    $objects[1] = '<< /Type /Pages /Kids ['.implode(' ', $kids).'] /Count '.count($kids).' >>';

    if ($trailerExtra !== '') {
        // An indirect object, as real encrypted PDFs reference it.
        $objects[] = $trailerExtra;
        $trailerExtra = '/Encrypt '.count($objects).' 0 R /ID [<0123456789ABCDEF0123456789ABCDEF> <0123456789ABCDEF0123456789ABCDEF>]';
    }

    return pdfDocument($objects, $trailerExtra);
}

/**
 * One page whose glyph codes 1..n map to the given Unicode code points.
 *
 * @param  list<int>  $codePoints
 */
function mappedPdf(array $codePoints): string
{
    $map = '';
    $codes = '';

    foreach ($codePoints as $i => $cp) {
        $code = $i + 1;
        $map .= sprintf("<%02X> <%04X>\n", $code, $cp);
        $codes .= sprintf('%02X', $code);
    }

    $cmap = "/CIDInit /ProcSet findresource begin\n12 dict begin\nbegincmap\n"
        ."/CIDSystemInfo << /Registry (Adobe) /Ordering (UCS) /Supplement 0 >> def\n"
        ."/CMapName /Adobe-Identity-UCS def\n/CMapType 2 def\n"
        ."1 begincodespacerange\n<00> <FF>\nendcodespacerange\n"
        .count($codePoints)." beginbfchar\n{$map}endbfchar\n"
        ."endcmap\nCMapName currentdict /CMap defineresource pop\nend\nend";

    $objects = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [5 0 R] /Count 1 >>',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /ToUnicode 4 0 R >>',
        pdfStream($cmap),
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 6 0 R /Resources << /Font << /F1 3 0 R >> >> >>',
        pdfStream("BT /F1 14 Tf 72 700 Td <{$codes}> Tj ET"),
    ];

    return pdfDocument($objects);
}

function tinyJpeg(): string
{
    $image = imagecreatetruecolor(8, 8);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 200, 200));
    ob_start();
    imagejpeg($image);

    return ob_get_clean();
}

// ---------------------------------------------------------------- PDFs

$header = 'Grand Hotel - House Rules';

file_put_contents("{$dir}/text.pdf", textPdf([
    [$header, 'Welcome to the Grand Hotel.', 'Check-in starts at 15:00.'],
    [$header, 'The pool is open until 22:00.', 'Towels are available at the pool bar.'],
    [$header, 'Checkout is at 11:00.', 'Late checkout can be requested at reception.'],
]));

// "مرحبا بكم" three times, written with Arabic presentation forms as many
// PDFs store it: meem, reh, hah, beh, alef, space, beh, kaf, meem.
$welcome = [0xFEE3, 0xFEAE, 0xFEA4, 0xFE92, 0xFE8E, 0x20, 0xFE91, 0xFEDC, 0xFEE2];
file_put_contents("{$dir}/arabic.pdf", mappedPdf([...$welcome, 0x20, ...$welcome, 0x20, ...$welcome]));

file_put_contents("{$dir}/scanned.pdf", textPdf([null, null]));

file_put_contents("{$dir}/mixed.pdf", textPdf([
    ['Shuttle timetable', 'The airport shuttle leaves at 07:00 from Gate B.'],
    null,
]));

file_put_contents("{$dir}/protected.pdf", textPdf(
    [['This text is protected.']],
    '<< /Filter /Standard /V 1 /R 2 /O (0123456789abcdef0123456789abcdef) /U (0123456789abcdef0123456789abcdef) /P -4 >>',
));

$valid = file_get_contents("{$dir}/text.pdf");
file_put_contents("{$dir}/corrupt.pdf", substr($valid, 0, 120));

// ---------------------------------------------------------------- DOCX

$docx = new ZipArchive;
$docx->open("{$dir}/menu.docx", ZipArchive::CREATE | ZipArchive::OVERWRITE);
$docx->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
    .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
    .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
    .'<Default Extension="xml" ContentType="application/xml"/>'
    .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
    .'</Types>');
$docx->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
    .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
    .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
    .'</Relationships>');

$p = fn (string $text, ?string $style = null) => '<w:p>'
    .($style ? "<w:pPr><w:pStyle w:val=\"{$style}\"/></w:pPr>" : '')
    .'<w:r><w:t xml:space="preserve">'.htmlspecialchars($text).'</w:t></w:r></w:p>';
$cell = fn (string $text) => '<w:tc>'.$p($text).'</w:tc>';
$row = fn (array $cells) => '<w:tr>'.implode('', array_map($cell, $cells)).'</w:tr>';

$docx->addFromString('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
    .$p('Restaurant menu')
    .$p('Breakfast', 'Heading1')
    .$p('Breakfast is served from 06:30 to 10:30 in the garden restaurant.')
    .$p('Dinner', 'Heading1')
    .$p('Dinner is served from 19:00.')
    .'<w:tbl>'.$row(['Dish', 'Price']).$row(['Grilled fish', '120 EGP']).$row(['Lentil soup', '45 EGP']).'</w:tbl>'
    .'</w:body></w:document>');
$docx->close();

// ---------------------------------------------------------------- XLSX / CSV

$book = new Spreadsheet;
$times = $book->getActiveSheet();
$times->setTitle('Times');
$times->fromArray([
    ['Route', 'Time', 'Gate'],
    ['Airport shuttle', '07:00', 'Gate B'],
    ['City centre', '10:00', 'Gate A'],
    ['Old town', '16:30', 'Gate C'],
]);
$book->createSheet()->setTitle('Empty');
(new Xlsx($book))->save("{$dir}/shuttle.xlsx");

$csv = "الخدمة;السعر\nتدليك;500\nساونا;200\n";
file_put_contents("{$dir}/prices-1256.csv", iconv('UTF-8', 'Windows-1256', $csv));

// ---------------------------------------------------------------- Text

file_put_contents("{$dir}/notes.md", "# Guest notes\n\n## Parking\n\nParking is free for hotel guests.\n\n## Pets\n\nSmall pets are welcome on request.\n");
file_put_contents("{$dir}/plain.txt", "Reception is open 24 hours a day.\nThe spa opens at 09:00.\n");
file_put_contents("{$dir}/empty.txt", "   \n\n ");

// ---------------------------------------------------------------- Images

$sign = imagecreatetruecolor(240, 60);
imagefill($sign, 0, 0, imagecolorallocate($sign, 255, 255, 255));
imagestring($sign, 5, 10, 20, 'SPA OPEN 09:00-21:00', imagecolorallocate($sign, 0, 0, 0));
imagepng($sign, "{$dir}/sign.png");
copy("{$dir}/sign.png", "{$dir}/fake.pdf");

echo "Fixtures written to {$dir}\n";
