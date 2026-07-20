<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;

uses(RefreshDatabase::class);

it('imports reservations from an uploaded csv file', function () {
    putenv('API_KEY=test-api-key');

    $user = User::factory()->create();

    $file = UploadedFile::fake()->createWithContent(
        'reservations.csv',
        "reservation_id,hotel_name,guest_name,room_number,arrival_date,departure_date,status,adults,children,reservation_value,currency\nABC123,Demo Hotel,Jane Doe,101,2026-07-20,2026-07-25,confirmed,2,1,250.50,USD\n"
    );

    $response = $this->withHeader('X-API-KEY', 'test-api-key')
        ->actingAs($user)
        ->postJson('/api/reservation/import', [
            'file' => $file,
        ]);

    $response->assertOk()
        ->assertJsonPath('imported', 1);

    $this->assertDatabaseHas('reservations', ['reservation_id' => 'ABC123']);
    $this->assertDatabaseHas('hotels', ['name' => 'Demo Hotel']);
    $this->assertDatabaseHas('guests', ['first_name' => 'Jane', 'last_name' => 'Doe']);
});

it('reads inline string values from an xlsx workbook', function () {
    $path = tempnam(sys_get_temp_dir(), 'reservations') . '.xlsx';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);

    $zip->addFromString('xl/workbook.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"
          xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">
  <sheets>
    <sheet name="Sheet1" sheetId="1" r:id="rId1"/>
  </sheets>
</workbook>
XML);

    $zip->addFromString('xl/_rels/workbook.xml.rels', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">
  <Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>
</Relationships>
XML);

    $zip->addFromString('xl/worksheets/sheet1.xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <sheetData>
    <row r="1">
      <c r="A1" t="inlineStr"><is><t>hotel_name</t></is></c>
      <c r="B1" t="inlineStr"><is><t>guest_name</t></is></c>
      <c r="C1" t="inlineStr"><is><t>reservation_id</t></is></c>
    </row>
    <row r="2">
      <c r="A2" t="inlineStr"><is><t>Grand Hotel</t></is></c>
      <c r="B2" t="inlineStr"><is><t>Jane Doe</t></is></c>
      <c r="C2" t="inlineStr"><is><t>RES-100</t></is></c>
    </row>
  </sheetData>
</worksheet>
XML);

    $zip->addFromString('[Content_Types].xml', <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">
  <Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>
  <Default Extension="xml" ContentType="application/xml"/>
  <Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>
  <Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>
</Types>
XML);

    $zip->close();

    $controller = new App\Http\Controllers\ReservationController;
    $method = new ReflectionMethod($controller, 'readXlsxRows');
    $method->setAccessible(true);

    $rows = $method->invoke($controller, $path);

    expect($rows)->toBe([
        ['hotel_name', 'guest_name', 'reservation_id'],
        ['Grand Hotel', 'Jane Doe', 'RES-100'],
    ]);

    @unlink($path);
});
