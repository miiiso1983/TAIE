<?php

namespace App\Modules\Advisor\Http\Controllers;

use App\Modules\Advisor\Services\TabularFileService;
use Illuminate\Http\Request;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;

class FormImportController
{
    public function template(Request $request)
    {
        $data = $request->validate(['columns' => 'required|array|min:1|max:100', 'columns.*' => 'required|string|max:100|regex:/^[a-z][a-z0-9_]*$/|distinct']);
        $path = tempnam(sys_get_temp_dir(), 'teami_form_template_');
        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->addRow(new Row(array_map(fn ($column) => Cell::fromValue($column), $data['columns'])));
            $writer->close();

            return response()->download($path, 'TAIE-addition-template.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])->deleteFileAfterSend(true);
        } catch (\Throwable $e) {
            if (is_file($path)) {
                unlink($path);
            } throw $e;
        }
    }

    public function preview(Request $request, TabularFileService $files)
    {
        $data = $request->validate(['file' => 'required|file|max:1024', 'sheet' => 'sometimes|integer|min:0|max:9']);
        $table = $files->read($request->file('file')->getRealPath(), strtolower($request->file('file')->getClientOriginalExtension()), (int) ($data['sheet'] ?? 0));
        abort_if(count($table['rows']) > 100, 422, 'الحد الأقصى 100 صف في نوافذ الإضافة؛ قسم الملف إلى دفعات أصغر.');

        return response()->json(['data' => $table]);
    }
}
