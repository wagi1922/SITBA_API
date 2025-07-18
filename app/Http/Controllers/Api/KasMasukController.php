<?php
// File: app/Http/Controllers/Api/KasMasukController.php


namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\KasMasuk;
use Exception;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Barryvdh\DomPDF\Facade\Pdf;
use PhpOffice\PhpWord\TemplateProcessor;
use PhpOffice\PhpWord\IOFactory;
use NumberToWords\NumberToWords;
use App\Imports\KasMasukImport;
use Maatwebsite\Excel\Facades\Excel;

class KasMasukController extends Controller
{
    public function index()
    {
        $data = KasMasuk::orderBy('tgl_transaksi', 'desc')->get();
        return response()->json(['data' => $data]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'tgl_transaksi' => 'required|date',
            'npwz' => 'required|string|max:100',
            'nama' => 'required|string|max:100',
            'nik' => 'required|string|max:100',
            'zakat' => 'nullable|numeric',
            'zakat_fitrah' => 'nullable|numeric',
            'infak' => 'nullable|numeric',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $kas = KasMasuk::create($request->all());
        return response()->json($kas, 201);
    }

    public function show(KasMasuk $kasMasuk)
    {
        return response()->json($kasMasuk);
    }

    public function update(Request $request, KasMasuk $kasMasuk)
    {
        $validator = Validator::make($request->all(), [
            'tgl_transaksi' => 'sometimes|required|date',
            'npwz' => 'sometimes|required|string|max:100',
            'nama' => 'sometimes|required|string|max:100',
        ]);

        if ($validator->fails()) {
            return response()->json($validator->errors(), 422);
        }

        $kasMasuk->update($request->all());
        return response()->json($kasMasuk);
    }

    public function destroy(KasMasuk $kasMasuk)
    {
        $kasMasuk->delete();
        return response()->json(null, 204);
    }

    /**
     * Mengimpor data kas masuk dan memberikan laporan hasil.
     */
    public function import(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'file' => 'required|file|mimes:xlsx,csv,xls',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        try {
            $file = $request->file('file');
            $import = new KasMasukImport();
            Excel::import($import, $file);

            $stats = $import->getStats();

            return response()->json([
                'message' => 'Proses update massal selesai.',
                'data' => [
                    'records_updated' => $stats['updated'],
                    'records_failed_or_skipped' => $stats['failed'],
                    'failures' => $stats['failures'],
                ]
            ], 200);

        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Terjadi kesalahan fatal saat memproses file.',
                'error' => $e->getMessage()
            ], 500);
        }
    }
    public function cetakBuktiPdfFromWord(KasMasuk $kasMasuk)
    {
        try {
            $templatePath = storage_path('app/templates/template_bukti_setoran.docx');
            if (!file_exists($templatePath)) {
                return response()->json(['message' => 'File template Word tidak ditemukan.'], 404);
            }

            $templateProcessor = new TemplateProcessor($templatePath);
            $kasMasuk->load('pendaftaran');
            $total = $kasMasuk->zakat + $kasMasuk->zakat_fitrah + $kasMasuk->infak;

            $this->fillTemplate($templateProcessor, $kasMasuk, $total);

            // Panggil helper method yang sudah dioptimalkan
            $html = $this->getHtmlFromProcessor($templateProcessor);
            $pdf = Pdf::loadHTML($html);
            
            $fileName = 'Bukti Setor - ' . ($kasMasuk->pendaftaran->nama ?? $kasMasuk->nama) . '.pdf';
            return $pdf->stream($fileName);

        } catch (Exception $e) {
            return response()->json(['message' => 'Gagal membuat dokumen.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * FINAL: Mencetak BANYAK bukti setor (batch) dalam satu file PDF dari template Word.
     */
    public function cetakBuktiPdfBatchFromWord(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1',
            'ids.*' => 'integer|exists:pendaftaran_zakat,id',
        ]);

        if ($validator->fails()) { return response()->json(['errors' => $validator->errors()], 422); }

        try {
            $templatePath = storage_path('app/templates/template_bukti_setoran.docx');
            if (!file_exists($templatePath)) { return response()->json(['message' => 'File template Word tidak ditemukan.'], 404); }

            $kasMasukRecords = KasMasuk::with('pendaftaran')->whereIn('id', $request->input('ids'))->get();
            if ($kasMasukRecords->isEmpty()) { return response()->json(['message' => 'Data tidak ditemukan.'], 404); }

            $finalHtml = '';
            foreach ($kasMasukRecords as $index => $kasMasuk) {
                $templateProcessor = new TemplateProcessor($templatePath);
                $total = $kasMasuk->zakat + $kasMasuk->zakat_fitrah + $kasMasuk->infak;
                
                $this->fillTemplate($templateProcessor, $kasMasuk, $total);
                
                // Panggil helper method yang sudah dioptimalkan
                $html = $this->getHtmlFromProcessor($templateProcessor);
                $finalHtml .= $html;
            }

            $pdf = Pdf::loadHTML($finalHtml);
            return $pdf->stream('kumpulan-bukti-setor-' . date('Y-m-d') . '.pdf');

        } catch (Exception $e) {
            return response()->json(['message' => 'Gagal membuat dokumen batch.', 'error' => $e->getMessage()], 500);
        }
    }

    /**
     * Helper method untuk mengisi placeholder di template. (Tidak ada perubahan di sini)
     */
    private function fillTemplate(TemplateProcessor $templateProcessor, KasMasuk $kasMasuk, $total)
    {
         // 1. Buat instance dari pustaka NumberToWords.
        $numberToWords = new NumberToWords();

        // 2. Dapatkan "transformer" khusus untuk Bahasa Indonesia.
        $numberTransformer = $numberToWords->getNumberTransformer('id');

        // 3. Konversi angka ke dalam kata-kata Bahasa Indonesia.
        $terbilangIndonesia = $numberTransformer->toWords($total);
        
        $templateProcessor->setValue('nama_penyetor', $kasMasuk->nama);
        $templateProcessor->setValue('npwz', $kasMasuk->npwz);
        $templateProcessor->setValue('nik', $kasMasuk->nik ?? '-');
        $templateProcessor->setValue('alamat', $kasMasuk->alamat_rumah ?? '-');
        $templateProcessor->setValue('kontak', ($kasMasuk->handphone ?? '-') . ' / ' . ($kasMasuk->email ?? '-'));
        $templateProcessor->setValue('nomor_bukti', $kasMasuk->no_transaksi ? str_pad($kasMasuk->no_transaksi, 6, '0', STR_PAD_LEFT) : 'N/A');
        $templateProcessor->setValue('periode', \Carbon\Carbon::parse($kasMasuk->tgl_transaksi)->isoFormat('MMMM YYYY'));
        $templateProcessor->setValue('jumlah', number_format($total, 0, ',', '.'));
        $templateProcessor->setValue('terbilang', ucwords($terbilangIndonesia) . ' Rupiah');
        $templateProcessor->setValue('catatan_transaksi', $kasMasuk->catatan ?? 'Tidak ada catatan.');
        $templateProcessor->setValue('NO',''.(\Carbon\Carbon::parse($kasMasuk->tgl_transaksi)->isoFormat('DD/MM/YYYY')).' / '.'km'.' / '.($kasMasuk->jumlah_transaksi ?? '-').' / '.($kasMasuk->no_transaksi ? str_pad($kasMasuk->no_transaksi, 6, '0', STR_PAD_LEFT) : 'N/A'));
        $templateProcessor->setValue('tanggal_transaksi', \Carbon\Carbon::parse($kasMasuk->tgl_transaksi)->isoFormat('DD/MM/YYYY'));
        $templateProcessor->setValue('jumlah_ts', $kasMasuk->jumlah_transaksi ?? '-');
    }

    /**
     * --- INI METHOD YANG DIPERBAIKI ---
     * Helper method untuk mengkonversi prosesor template ke HTML dengan CSS yang dioptimalkan.
     */
    private function getHtmlFromProcessor(TemplateProcessor $templateProcessor): string
    {
        $tempFilePath = $templateProcessor->save();
        $phpWord = IOFactory::load($tempFilePath);
        $htmlWriter = IOFactory::createWriter($phpWord, 'HTML');
        $htmlContent = $htmlWriter->getContent();

        if (file_exists($tempFilePath)) {
            unlink($tempFilePath);
        }

        // --- INI KODE PERBAIKANNYA ---
        // Buat CSS untuk "memadatkan" layout
        $compactCss = "
            <style>
                body { font-family: Courier New, serif; font-size: 20.5pt; }
                p { margin: 0; padding: 0; line-height: 1.2; }
                table { border-collapse: collapse; width: 100%; page-break-inside: avoid; }
                td { padding: 1px 2px !important; }
            </style>
        ";

        // Suntikkan (inject) CSS ini ke dalam <head> dari HTML yang dihasilkan
        $htmlContent = str_replace('</head>', $compactCss . '</head>', $htmlContent);

        return $htmlContent;
    }
}

