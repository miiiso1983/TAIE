<?php

namespace App\Modules\Advisor\Services;

use DateTimeInterface;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;
use XMLReader;
use ZipArchive;

class TabularFileService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }

    private function guardXlsx(string $path): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            $this->fail('ملف XLSX غير صالح.');
        }
        try {
            if ($zip->numFiles > 200) {
                $this->fail('ملف Excel يحتوي مكونات أكثر من الحد المسموح.');
            }
            foreach (['[Content_Types].xml', 'xl/workbook.xml', 'xl/_rels/workbook.xml.rels'] as $required) {
                if ($zip->locateName($required) === false) {
                    $this->fail('بنية XLSX غير مكتملة.');
                }
            }
            $size = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                $name = $stat['name'];
                $size += $stat['size'];
                if ($size > 20 * 1024 * 1024 || $stat['size'] > 10 * 1024 * 1024 || ($stat['size'] > 1024 * 1024 && $stat['size'] > max(1, $stat['comp_size']) * 100)) {
                    $this->fail('الحجم بعد فك ضغط Excel يتجاوز الحد الآمن.');
                }
                if (str_contains($name, '..') || str_contains($name, '\\') || str_starts_with($name, '/') || preg_match('/(?:vba|externalLinks|activeX|embeddings)/i', $name)) {
                    $this->fail('لا يُقبل ملف يحوي وحدات ماكرو أو روابط خارجية أو مكونات مضمّنة.');
                }
                if (str_ends_with($name, '.xml') || str_ends_with($name, '.rels')) {
                    $xml = $zip->getFromIndex($i);
                    if ($xml === false) {
                        $this->fail('تعذر قراءة Excel.');
                    }
                    if (preg_match('/<!\s*(?:DOCTYPE|ENTITY)/i', $xml)) {
                        $this->fail('تصريحات XML غير مدعومة.');
                    }
                    $reader = new XMLReader;
                    $prior = libxml_use_internal_errors(true);
                    try {
                        if (! $reader->XML($xml, null, LIBXML_NONET)) {
                            $this->fail('XML غير صالح.');
                        }
                        while ($reader->read()) {
                            if ($reader->nodeType === XMLReader::ELEMENT) {
                                if (str_starts_with($name, 'xl/worksheets/') && $reader->localName === 'f') {
                                    $this->fail('حوّل الصيغ الحسابية إلى قيم ثابتة قبل الرفع؛ لا نستخدم نتائج صيغ مخزّنة قديمة.');
                                }
                                if ($reader->localName === 'Relationship' && strcasecmp((string) $reader->getAttribute('TargetMode'), 'External') === 0) {
                                    $this->fail('احذف الروابط الخارجية من الملف ثم ارفعه كقيم ثابتة.');
                                }
                            }
                        }
                        if (libxml_get_errors()) {
                            $this->fail('XML غير صالح.');
                        }
                    } finally {
                        $reader->close();
                        libxml_clear_errors();
                        libxml_use_internal_errors($prior);
                    }
                }
            }
        } finally {
            $zip->close();
        }
    }

    private function clean(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_bool($value)) {
            $this->fail('الخلايا المنطقية ليست قيماً صالحة للاستيراد.');
        }
        if (is_float($value)) {
            $value = json_encode($value, JSON_THROW_ON_ERROR);
        }
        $value = trim((string) $value);
        if (strlen($value) > 10000 || ! mb_check_encoding($value, 'UTF-8') || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value)) {
            $this->fail('خلايا طويلة أو ترميز غير صالح.');
        }

        return $value;
    }

    private function header(array $cells): array
    {
        if (count($cells) > 100) {
            $this->fail('الحد الأقصى 100 عمود.');
        }
        $head = array_map(fn ($v) => $this->clean($v), $cells);
        if ($head) {
            $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);
        }
        if (! $head || in_array('', $head, true) || count(array_unique($head)) !== count($head)) {
            $this->fail('أسماء الأعمدة يجب أن تكون موجودة ومختلفة؛ احذف الأعمدة الفارغة.');
        }

        return $head;
    }

    public function read(string $path, string $format, int $sheet = 0, bool $headersOnly = false): array
    {
        if ($format === 'csv') {
            if ($sheet !== 0) {
                $this->fail('CSV يحتوي ورقة واحدة.');
            }
            $stream = fopen($path, 'rb');
            try {
                $head = $this->header(fgetcsv($stream, 0, ',', '"', '') ?: []);
                $rows = [];
                $line = 1;
                if (! $headersOnly) {
                    while (($cells = fgetcsv($stream, 0, ',', '"', '')) !== false) {
                        $line++;
                        if ($cells === [null]) {
                            continue;
                        }if (count($rows) >= 1000) {
                            $this->fail('الحد الأقصى 1000 سجل لكل دفعة.');
                        }if (count($cells) !== count($head)) {
                            $this->fail("عدد الأعمدة غير صحيح في السجل {$line}.");
                        }$rows[] = ['line' => $line, 'cells' => array_map(fn ($v) => $this->clean($v), $cells)];
                    }
                }
                if (! $headersOnly && ! $rows) {
                    $this->fail('الملف لا يحتوي على سجلات.');
                }

                return ['sheets' => [['index' => 0, 'name' => 'CSV', 'columns' => $head]], 'header' => $head, 'rows' => $rows];
            } finally {
                fclose($stream);
            }
        }
        if ($format !== 'xlsx') {
            $this->fail('يدعم الاستيراد CSV UTF-8 وXLSX فقط.');
        }
        $this->guardXlsx($path);
        $reader = new Reader;
        $sheets = [];
        $rows = [];
        $selectedHeader = null;
        try {
            $reader->open($path);
            foreach ($reader->getSheetIterator() as $current) {
                if (count($sheets) >= 10) {
                    $this->fail('الحد الأقصى 10 أوراق لكل ملف.');
                }
                $index = $current->getIndex();
                $header = null;
                foreach ($current->getRowIterator() as $line => $row) {
                    $values = $row->toArray();
                    if ($header === null) {
                        $header = $this->header($values);
                        $sheets[] = ['index' => $index, 'name' => $current->getName(), 'columns' => $header];
                        if ($index === $sheet) {
                            $selectedHeader = $header;
                        }if ($headersOnly || $index !== $sheet) {
                            break;
                        }

                        continue;
                    }
                    if (count($rows) >= 1000) {
                        $this->fail('الحد الأقصى 1000 سجل لكل دفعة.');
                    }
                    if (count($values) > count($header) && array_filter(array_slice($values, count($header)), fn ($v) => $v !== null && $v !== '')) {
                        $this->fail('توجد قيم خارج أعمدة رأس الورقة.');
                    }
                    $values = array_slice(array_pad($values, count($header), null), 0, count($header));
                    $rows[] = ['line' => $line, 'cells' => array_map(fn ($v) => $this->clean($v), $values)];
                }
            }
            if ($headersOnly && $selectedHeader === null && $sheets) {
                $selectedHeader = $sheets[0]['columns'];
            }
            if ($selectedHeader === null) {
                $this->fail('الورقة المطلوبة غير موجودة أو فارغة.');
            }
            if (! $headersOnly && ! $rows) {
                $this->fail('الورقة لا تحتوي على سجلات.');
            }

            return ['sheets' => $sheets, 'header' => $selectedHeader, 'rows' => $rows];
        } catch (ValidationException $e) {
            throw $e;
        } catch (Throwable) {
            $this->fail('تعذر قراءة Excel؛ ارفع XLSX غير محمي بكلمة مرور وبقيم ثابتة.');
        } finally {
            $reader->close();
        }
    }

    public function normalize(array $table, string $kind, array $mapping): array
    {
        $targets = CsvImportService::HEADERS[$kind];
        $header = $table['header'];
        if (! $mapping) {
            if ($header !== $targets) {
                $this->fail('اربط أعمدة الملف بالحقول المطلوبة أولاً.');
            }$mapping = array_combine($targets, $targets);
        }
        if (array_diff(array_keys($mapping), $targets) || array_diff($targets, array_keys($mapping))) {
            $this->fail('ربط الأعمدة يجب أن يشمل جميع حقول القالب فقط.');
        }
        $used = [];
        foreach ($targets as $field) {
            $column = $mapping[$field];
            if ($column === '' && ! in_array($field, CsvImportService::REQUIRED[$kind], true)) {
                continue;
            }if (! is_string($column) || ! in_array($column, $header, true) || isset($used[$column])) {
                $this->fail('العمود المحدد غير موجود أو مربوط بأكثر من حقل.');
            }$used[$column] = true;
        }

        return array_map(function ($row) use ($targets, $mapping, $header) {
            $d = [];
            foreach ($targets as $field) {
                $d[$field] = $mapping[$field] === '' ? '' : $row['cells'][array_search($mapping[$field], $header, true)];
            }

            return ['line' => $row['line'], 'values' => $d];
        }, $table['rows']);
    }
}
