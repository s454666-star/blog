<?php

    namespace App\Http\Controllers;

    use App\Models\TokenScanHeader;
    use App\Models\TokenScanItem;
    use Illuminate\Http\Request;

    class TokenScanController extends Controller
    {
        public function headers()
        {
            return response()->json([
                'status' => 'ok',
                'items' => TokenScanHeader::query()
                    ->orderBy('id', 'desc')
                    ->get(),
            ]);
        }

        public function items(Request $request, int $peerId)
        {
            $header = TokenScanHeader::query()->where('peer_id', $peerId)->first();

            if (!$header) {
                return response()->json([
                    'status' => 'ok',
                    'peer_id' => $peerId,
                    'items' => [],
                ]);
            }

            $items = TokenScanItem::query()
                ->where('header_id', $header->id)
                ->orderBy('id', 'desc')
                ->get();

            return response()->json([
                'status' => 'ok',
                'peer_id' => $peerId,
                'header' => $header,
                'items' => $items,
            ]);
        }
    }
