<?php

    namespace App\Http\Controllers;

    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\DB;

    class DialogueReadController extends Controller
    {
        public function page()
        {
            return view('dialogues.mark-read');
        }

        public function mark(Request $request)
        {
            $request->validate([
                'lines' => ['required', 'string'],
            ]);

            $raw = (string) $request->input('lines', '');
            $raw = str_replace(["\r\n", "\r"], "\n", $raw);

            $parts = explode("\n", $raw);

            $texts = [];
            foreach ($parts as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }
                $texts[] = $line;
            }

            $uniqueTexts = array_values(array_unique($texts));

            if (count($uniqueTexts) === 0) {
                return redirect()
                    ->route('dialogues.markRead.page')
                    ->with('mark_result', '沒有可標記的內容（都是空白行）。');
            }

            $updatedTotal = 0;

            $chunkSize = 800;
            $total = count($uniqueTexts);
            $offset = 0;

            while ($offset < $total) {
                $chunk = array_slice($uniqueTexts, $offset, $chunkSize);

                $updated = DB::table('dialogues')
                    ->whereIn('text', $chunk)
                    ->update(['is_read' => 1]);

                $updatedTotal += (int) $updated;
                $offset += $chunkSize;
            }

            return redirect()
                ->route('dialogues.markRead.page')
                ->with('mark_result', '已標記完成。更新筆數：' . $updatedTotal . '（依 text 精準比對）。');
        }
    }
