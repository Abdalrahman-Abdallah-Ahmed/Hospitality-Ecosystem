<?php

namespace App\Http\Requests\Knowledge\Concerns;

use App\Support\Knowledge\Extraction\ExtractorRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Validator;
use ZipArchive;

/**
 * The upload rules shared by "upload a document" and "replace its file".
 *
 * The type is judged from the file's content (finfo), and has to agree with
 * the extension: a PNG renamed to .pdf is refused here, before anything is
 * stored, rather than failing later in the pipeline.
 */
trait ValidatesKnowledgeFile
{
    /**
     * What finfo may report for each extension. DOCX and XLSX can come back
     * as a plain zip; those are opened to check which kind of zip they are.
     *
     * @var array<string, list<string>>
     */
    private const DETECTED_TYPES = [
        'pdf' => ['application/pdf'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'csv' => ['text/csv', 'text/plain', 'application/csv'],
        'txt' => ['text/plain'],
        'md' => ['text/plain', 'text/markdown', 'text/x-markdown'],
        'jpg' => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png' => ['image/png'],
        'webp' => ['image/webp'],
    ];

    /** Password-protected Office files are compound (OLE) documents, not zips. */
    private const ENCRYPTED_OFFICE_TYPES = ['application/x-ole-storage', 'application/cdfv2', 'application/vnd.ms-office', 'application/encrypted'];

    /**
     * @return list<string>
     */
    protected function knowledgeFileRules(): array
    {
        return [
            'required',
            'file',
            'max:'.(int) config('knowledge.max_upload_kb'),
            'extensions:'.implode(',', array_keys(self::DETECTED_TYPES)),
        ];
    }

    protected function validateKnowledgeFile(Validator $validator, string $field = 'file'): void
    {
        if ($validator->errors()->has($field)) {
            return;
        }

        $file = $this->file($field);

        if (! $file instanceof UploadedFile) {
            return;
        }

        $extension = strtolower($file->getClientOriginalExtension());
        $detected = strtolower((string) $file->getMimeType());

        if (in_array($extension, ['docx', 'xlsx'], true) && in_array($detected, self::ENCRYPTED_OFFICE_TYPES, true)) {
            $validator->errors()->add($field, 'Password-protected Office files are not supported.');

            return;
        }

        $allowed = self::DETECTED_TYPES[$extension] ?? [];

        if (! in_array($detected, $allowed, true)) {
            $validator->errors()->add($field, 'The file type does not match its extension.');

            return;
        }

        if ($detected === 'application/zip' && ! $this->zipHas($file->getRealPath(), $extension === 'docx' ? 'word/document.xml' : 'xl/workbook.xml')) {
            $validator->errors()->add($field, 'The file type does not match its extension.');
        }
    }

    /**
     * The MIME type the document is stored and extracted under: the canonical
     * type for its extension, once the content check above has passed.
     */
    public function knowledgeMimeType(string $field = 'file'): string
    {
        $extension = strtolower($this->file($field)->getClientOriginalExtension());

        return ExtractorRegistry::EXTENSIONS[$extension];
    }

    private function zipHas(string $path, string $entry): bool
    {
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::RDONLY) !== true) {
            return false;
        }

        $found = $zip->locateName($entry) !== false;
        $zip->close();

        return $found;
    }
}
