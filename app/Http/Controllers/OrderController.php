<?php

    namespace App\Http\Controllers;

    use App\Models\Order;
    use Illuminate\Http\Request;

    class OrderController extends Controller
    {
        public function index(Request $request)
        {
            $range = $request->input('range', [0, 49]);
            if (is_string($range)) {
                $range = json_decode($range, true);
            }
            $from = $range[0];
            $to   = $range[1];

            $sort = $request->input('sort', ['id', 'asc']);
            if (is_string($sort)) {
                $sort = json_decode($sort, true);
            }
            $sortField     = $sort[0];
            $sortDirection = strtolower($sort[1] ?? 'asc');

            $query = Order::query();

            $filters = $request->input('filter', []);
            if (!empty($filters) && isset($filters['q'])) {
                $q = $filters['q'];
                $query->where('order_number', 'like', "%{$q}%");
            }

            $total = $query->count();

            $orders = $query->orderBy($sortField, $sortDirection)
                ->skip($from)
                ->take($to - $from + 1)
                ->get();

            return response()->json($orders, 200)
                ->header('X-Total-Count', $total)
                ->header('Content-Range', "items {$from}-{$to}/{$total}")
                ->header('Access-Control-Expose-Headers', 'X-Total-Count, Content-Range');
        }

        public function show($id)
        {
            $order = Order::findOrFail($id);
            return response()->json($order, 200);
        }

        public function store(Request $request)
        {
            $data = $request->validate([
                'member_id'           => 'required|integer|exists:members,id',
                'order_number'        => 'required|string|unique:orders,order_number',
                'status'              => 'required|in:pending,processing,shipped,completed,cancelled',
                'total_amount'        => 'required|numeric',
                'payment_method'      => 'required|in:credit_card,bank_transfer,cash_on_delivery',
                'shipping_fee'        => 'nullable|numeric',
                'delivery_address_id' => 'required|integer|exists:delivery_addresses,id',
                'credit_card_id'      => 'nullable|integer|exists:credit_cards,id'
            ]);

            $order = Order::create($data);
            return response()->json($order, 201);
        }

        public function update(Request $request, $id)
        {
            $order = Order::findOrFail($id);

            $data = $request->validate([
                'status'              => 'in:pending,processing,shipped,completed,cancelled',
                'total_amount'        => 'numeric',
                'payment_method'      => 'in:credit_card,bank_transfer,cash_on_delivery',
                'shipping_fee'        => 'numeric',
                'delivery_address_id' => 'integer|exists:delivery_addresses,id',
                'credit_card_id'      => 'integer|exists:credit_cards,id'
            ]);

            $order->update($data);
            return response()->json($order, 200);
        }

        public function destroy($id)
        {
            $order = Order::findOrFail($id);
            $order->delete();
            return response()->json(['message' => 'Order deleted successfully'], 200);
        }
    }
