<?php

    namespace App\Http\Controllers;

    use App\Models\Order;
    use Illuminate\Http\Request;

    class OrderController extends Controller
    {
        // 確保使用 auth middleware
        public function __construct()
        {
            $this->middleware('auth:api');
        }

        /**
         * 獲取訂單列表，根據過濾條件
         */
        public function index(Request $request)
        {
            $user = $request->user(); // 獲取當前授權的使用者

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

            // 僅查詢當前用戶的訂單
            $query = Order::where('member_id', $user->id);

            $filters = $request->input('filter', []);
            if (!empty($filters)) {
                if (isset($filters['q'])) {
                    $q = $filters['q'];
                    $query->where('order_number', 'like', "%{$q}%");
                }
                // 其他過濾條件可以在這裡添加
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

        /**
         * 顯示指定訂單
         */
        public function show(Request $request, $id)
        {
            $user = $request->user();

            $order = Order::where('id', $id)
                ->where('member_id', $user->id)
                ->firstOrFail();

            return response()->json($order, 200);
        }

        /**
         * 新增或更新購物車（pending 訂單）
         */
        public function store(Request $request)
        {
            $data = $request->validate([
                                           'product_id' => 'required|integer|exists:products,id',
                                           'quantity'   => 'required|integer|min:1',
                                           'price'      => 'required|numeric',
                                       ]);

            $user = $request->user(); // 獲取當前授權的使用者 ID

            // 檢查是否已有 pending 訂單
            $pendingOrder = Order::where('member_id', $user->id)
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

                return response()->json($pendingOrder->load(['orderItems.product']), 200);
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
            $data['shipping_fee'] = 0.00; // 預設運費，可根據需求調整
            $data['delivery_address_id'] = null; // 允許 NULL，因為 pending 訂單不需要地址
            $data['member_id'] = $user->id; // 設置為當前用戶的 ID

            $order = Order::create($data);

            // 將品項加入新建立的訂單
            $order->orderItems()->create([
                                             'product_id' => $data['product_id'],
                                             'quantity'   => $data['quantity'],
                                             'price'      => $data['price'],
                                         ]);

            return response()->json($order->load(['orderItems.product']), 201);
        }

        /**
         * 更新訂單品項的數量
         */
        public function updateItemQuantity(Request $request, $orderId, $itemId)
        {
            $data = $request->validate([
                                           'quantity' => 'required|integer|min:1',
                                       ]);

            $user = $request->user();

            $order = Order::where('id', $orderId)
                ->where('member_id', $user->id)
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

            return response()->json($order->load(['orderItems.product']), 200);
        }

        /**
         * 刪除訂單品項
         */
        public function deleteItem(Request $request, $orderId, $itemId)
        {
            $user = $request->user();

            $order = Order::where('id', $orderId)
                ->where('member_id', $user->id)
                ->where('status', 'pending')
                ->firstOrFail();

            $orderItem = $order->orderItems()->where('id', $itemId)->firstOrFail();
            $order->total_amount -= $orderItem->price * $orderItem->quantity;
            $orderItem->delete();
            $order->save();

            return response()->json($order->load(['orderItems.product']), 200);
        }

        /**
         * 更新訂單
         */
        public function update(Request $request, $id)
        {
            $user = $request->user();

            $order = Order::where('id', $id)
                ->where('member_id', $user->id)
                ->firstOrFail();

            $data = $request->validate([
                                           'status'              => 'in:pending,processing,shipped,completed,cancelled',
                                           'total_amount'        => 'numeric',
                                           'payment_method'      => 'in:credit_card,bank_transfer,cash_on_delivery',
                                           'shipping_fee'        => 'numeric',
                                           'delivery_address_id' => 'nullable|integer|exists:delivery_addresses,id',
                                           'credit_card_id'      => 'nullable|integer|exists:credit_cards,id'
                                       ]);

            $order->update($data);
            return response()->json($order, 200);
        }

        /**
         * 刪除訂單
         */
        public function destroy(Request $request, $id)
        {
            $user = $request->user();

            $order = Order::where('id', $id)
                ->where('member_id', $user->id)
                ->firstOrFail();

            $order->delete();
            return response()->json(['message' => 'Order deleted successfully'], 200);
        }

        /**
         * 處理訂單（將狀態從 pending 更新為 processing）
         */
        public function processOrder(Request $request, $id)
        {
            $user = $request->user();

            // 查找指定的 pending 訂單
            $order = Order::where('id', $id)
                ->where('member_id', $user->id)
                ->where('status', 'pending')
                ->firstOrFail();

            // 將訂單狀態更新為 processing
            $order->update(['status' => 'processing']);

            return response()->json($order, 200);
        }
    }
