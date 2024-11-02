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
                                           'product_id' => 'required|integer|exists:products,id',
                                           'quantity'   => 'required|integer|min:1',
                                           'price'      => 'required|numeric',
                                       ]);

            // 檢查會員是否已有 pending 狀態的訂單
            $pendingOrder = Order::where('member_id', $data['member_id'])
                ->where('status', 'pending')
                ->first();

            if ($pendingOrder) {
                // 檢查該產品是否已存在於訂單中
                $orderItem = $pendingOrder->orderItems()->where('product_id', $data['product_id'])->first();

                if ($orderItem) {
                    // 增加數量
                    $orderItem->quantity += $data['quantity'];
                    $orderItem->save();
                } else {
                    // 新增品項
                    $pendingOrder->orderItems()->create([
                                                            'product_id' => $data['product_id'],
                                                            'quantity'   => $data['quantity'],
                                                            'price'      => $data['price'],
                                                        ]);
                }

                // 更新訂單總金額
                $pendingOrder->total_amount += $data['price'] * $data['quantity'];
                $pendingOrder->save();

                return response()->json($pendingOrder->load('orderItems'), 200);
            }

            // 如果沒有 pending 訂單，則新增一張新的 pending 訂單
            $today = now()->format('Ymd');
            $lastOrder = Order::whereDate('created_at', now()->toDateString())
                ->orderBy('id', 'desc')
                ->first();

            $sequence    = $lastOrder ? intval(substr($lastOrder->order_number, -5)) + 1 : 1;
            $orderNumber = 'O' . $today . str_pad($sequence, 5, '0', STR_PAD_LEFT);
            $data['order_number'] = $orderNumber;
            $data['status'] = 'pending';
            $data['total_amount'] = $data['price'] * $data['quantity'];
            $data['payment_method'] = 'cash_on_delivery'; // 預設付款方式，可根據需求調整
            $data['shipping_fee'] = 0.00;                 // 預設運費，可根據需求調整
            $data['delivery_address_id'] = 1;             // 預設地址ID，需根據實際情況調整

            $order = Order::create($data);

            // 將品項加入新建立的訂單
            $order->orderItems()->create([
                                             'product_id' => $data['product_id'],
                                             'quantity'   => $data['quantity'],
                                             'price'      => $data['price'],
                                         ]);

            return response()->json($order->load('orderItems'), 201);
        }

        public function updateItemQuantity(Request $request, $orderId, $itemId)
        {
            $data = $request->validate([
                                           'quantity' => 'required|integer|min:1',
                                       ]);

            $order = Order::where('id', $orderId)
                ->where('status', 'pending')
                ->firstOrFail();

            $orderItem = $order->orderItems()->where('id', $itemId)->firstOrFail();

            // 計算金額差異
            $quantityDifference = $data['quantity'] - $orderItem->quantity;
            $order->total_amount += $orderItem->price * $quantityDifference;

            // 更新數量
            $orderItem->quantity = $data['quantity'];
            $orderItem->save();

            // 更新訂單總金額
            $order->save();

            return response()->json($order->load('orderItems'), 200);
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

        public function processOrder($id)
        {
            // 查找指定的 pending 訂單
            $order = Order::where('id', $id)->where('status', 'pending')->firstOrFail();

            // 將訂單狀態更新為 processing
            $order->update(['status' => 'processing']);

            return response()->json($order, 200);
        }
    }
