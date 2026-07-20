<?php

namespace App\Http\Controllers;

use App\Enums\ReservationStatus;
use App\Http\Requests\GlopalIndexRequest;
use App\Models\Guest;
use App\Models\Hotel;
use App\Models\Reservation;
use App\Models\Room;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class ReservationController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(GlopalIndexRequest $request)
    {
        $reservations = Reservation::with(['hotel', 'guest', 'room'])
        ->where('hotel_id', $request->user()->hotel_id)
        ->get();
        return response()->json($reservations);
    }

    public function importFromExcel(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimetypes:text/csv,text/plain,application/csv,application/vnd.ms-excel,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'],
        ]);

        $file = $request->file('file');
        $extension = strtolower($file->getClientOriginalExtension());
        $imported = 0;

        if ($extension === 'xlsx') {
            $rows = $this->readXlsxRows($file->getRealPath());
        } else {
            $handle = fopen($file->getRealPath(), 'r');
            if ($handle === false) {
                throw ValidationException::withMessages([
                    'file' => 'Unable to read the uploaded file.',
                ]);
            }

            $rows = [];
            while (($row = fgetcsv($handle)) !== false) {
                $rows[] = $row;
            }
            fclose($handle);
        }

        if (empty($rows)) {
            throw ValidationException::withMessages([
                'file' => 'The uploaded file is empty.',
            ]);
        }

        $header = array_map(fn ($column) => strtolower(trim((string) $column)), $rows[0]);
        $headerMap = array_values(array_filter($header, fn ($value) => $value !== ''));

        foreach (array_slice($rows, 1) as $row) {
            if ($row === [null] || $row === false) {
                continue;
            }

            if (empty(array_filter($row, fn ($value) => $value !== null && $value !== ''))) {
                continue;
            }

            $rowCount = count($row);
            $headerCount = count($headerMap);
            if ($rowCount !== $headerCount) {
                $row = array_pad($row, $headerCount, null);
                if ($rowCount > $headerCount) {
                    $row = array_slice($row, 0, $headerCount);
                }
            }

            $data = array_combine($headerMap, $row);
            if ($data === false) {
                continue;
            }

            $hotelName = trim((string) ($data['hotel_name'] ?? ''));
            $guestName = trim((string) ($data['guest_name'] ?? ''));
            $reservationId = trim((string) ($data['reservation_id'] ?? ''));

            if ($hotelName === '' || $guestName === '' || $reservationId === '') {
                continue;
            }

            $hotel = Hotel::firstOrCreate(
                ['slug' => Str::slug($hotelName)],
                [
                    'owner_id' => $request->user()?->id,
                    'name' => $hotelName,
                    'slug' => Str::slug($hotelName),
                    'currency' => $data['currency'] ?? 'USD',
                ]
            );

            $nameParts = preg_split('/\s+/', $guestName, 2) ?: [$guestName];
            $firstName = $nameParts[0] ?? '';
            $lastName = $nameParts[1] ?? '';

            $guest = Guest::firstOrCreate(
                [
                    'hotel_id' => $hotel->id,
                    'email' => trim((string) ($data['guest_email'] ?? '')),
                ],
                [
                    'hotel_id' => $hotel->id,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                ]
            );

            $roomNumber = trim((string) ($data['room_number'] ?? ''));
            $room = null;
            if ($roomNumber !== '') {
                $room = Room::firstOrCreate(
                    [
                        'hotel_id' => $hotel->id,
                        'room_number' => $roomNumber,
                    ],
                    [
                        'hotel_id' => $hotel->id,
                        'room_number' => $roomNumber,
                        'room_type' => $data['room_type'] ?? null,
                        'floor' => $data['floor'] ?? null,
                        'status' => 'available',
                    ]
                );
            }

            Reservation::create([
                'hotel_id' => $hotel->id,
                'guest_id' => $guest->id,
                'room_id' => $room?->id,
                'reservation_id' => $reservationId,
                'arrival_date' => $data['arrival_date'] ?? null,
                'departure_date' => $data['departure_date'] ?? null,
                'status' => $this->normalizeStatus($data['status'] ?? null),
                'adults' => (int) ($data['adults'] ?? 1),
                'children' => (int) ($data['children'] ?? 0),
                'source' => 'excel_import',
                'special_requests' => $data['special_requests'] ?? null,
                'reservation_value' => (float) ($data['reservation_value'] ?? 0),
                'currency' => $data['currency'] ?? $hotel->currency,
            ]);

            $imported++;
        }

        return response()->json([
            'message' => 'Reservations imported successfully.',
            'imported' => $imported,
        ]);
    }

    protected function readXlsxRows(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            return [];
        }
        
        
        $sharedStrings = [];
        $sharedStringsPath = 'xl/sharedStrings.xml';
        if ($zip->locateName($sharedStringsPath) !== false) {
            $sharedStringsXml = $zip->getFromName($sharedStringsPath);
            if ($sharedStringsXml !== false) {
                $sharedStringsXml = simplexml_load_string($sharedStringsXml);
                if ($sharedStringsXml !== false) {
                    foreach ($sharedStringsXml->si as $si) {
                        $text = [];
                        foreach ($si->t as $t) {
                            $text[] = (string) $t;
                        }
                        $sharedStrings[] = implode('', $text);
                    }
                }
            }
        }

        $sheetXml = $zip->getFromName('xl/workbook.xml');
        if ($sheetXml === false) {
            $zip->close();

            return [];
        }

        $sheetXml = simplexml_load_string($sheetXml);
        if ($sheetXml === false) {
            $zip->close();

            return [];
        }

        $sheetName = (string) $sheetXml->sheets->sheet[0]['name'];
        $sheetId = (string) $sheetXml->sheets->sheet[0]['sheetId'];
        $relationshipsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
        $relationships = simplexml_load_string($relationshipsXml);

        $sheetPath = null;
        if ($relationships !== false) {
            foreach ($relationships->Relationship as $relationship) {
                if ((string) $relationship['Id'] === 'rId' . $sheetId) {
                    $sheetPath = (string) $relationship['Target'];
                    break;
                }
            }
        }

        if ($sheetPath === null) {
            $zip->close();

            return [];
        }

        $sheetXml = $zip->getFromName('xl/' . ltrim($sheetPath, '/'));
        if ($sheetXml === false) {
            $zip->close();

            return [];
        }

        $sheetXml = simplexml_load_string($sheetXml);
        if ($sheetXml === false) {
            $zip->close();

            return [];
        }

        $rows = [];
        $mainNamespace = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheetData = $sheetXml->children($mainNamespace)->sheetData;

        foreach ($sheetData->row as $row) {
            $values = [];
            foreach ($row->children($mainNamespace)->c as $cell) {
                $cellType = (string) $cell->attributes()['t'];
                $value = '';

                if ($cellType === 'inlineStr') {
                    $textNodes = [];
                    foreach ($cell->children($mainNamespace)->is->children($mainNamespace)->t as $textNode) {
                        $textNodes[] = (string) $textNode;
                    }
                    $value = implode('', $textNodes);
                } elseif ($cellType === 's') {
                    $sharedIndex = (int) (string) $cell->children($mainNamespace)->v;
                    $value = $sharedStrings[$sharedIndex] ?? '';
                } elseif (isset($cell->children($mainNamespace)->v)) {
                    $value = (string) $cell->children($mainNamespace)->v;
                }

                $values[] = $value;
            }
            $rows[] = $values;
        }

        $zip->close();

        return $rows;
    }

    protected function normalizeStatus(?string $status): string
    {
        $statusValue = strtolower(trim((string) $status));

        return match ($statusValue) {
            'confirmed', 'confirm', 'booked' => ReservationStatus::CONFIRMED->value,
            'checked_in', 'checkin', 'checked-in' => ReservationStatus::CHECKED_IN->value,
            'checked_out', 'checkout', 'checked-out' => ReservationStatus::CHECKED_OUT->value,
            'cancelled', 'canceled', 'cancel' => ReservationStatus::CANCELLED->value,
            default => ReservationStatus::PENDING->value,
        };
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     */
    public function show(Reservation $reservation)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Reservation $reservation)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Reservation $reservation)
    {
        //
    }
}
