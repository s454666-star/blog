<?php

    namespace App\Http\Controllers;

    use App\Models\Order;
    use Illuminate\Http\Request;

    class OrderController extends Controller
    {
        // 確保使用 Sanctum auth middleware
        public function __construct()
        {
            $this->middleware('auth:sanctum');
        }

        /**
         * 獲取訂單列表，根據用戶角色顯示不同的數據
         * - 管理員：獲取所有訂單
         * - 一般用戶：僅獲取自己的訂單
         * 並帶出配送地址
         */
        public function index(Request $request)
        {
            $user = $request->user(); // 獲取當前授權的使用者

            // 解析範圍
            $range = $request->input('range', [0, 49]);
            if (is_string($range)) {
                $range = json_decode($range, true);
            }
            $from = $range[0];
            $to   = $range[1];

            // 解析排序
            $sort = $request->input('sort', ['id', 'asc']);
            if (is_string($sort)) {
                $sort = json_decode($sort, true);
            }
            $sortField     = $sort[0];
            $sortDirection = strtolower($sort[1] ?? 'asc');

            // 判斷用戶角色
            if ($user->role === 'admin') {
                // 管理員可以查看所有訂單
                $query = Order::with([
                    'orderItems.product',
                    'member.defaultDeliveryAddress',
                    'member.deliveryAddresses',
                    'creditCard',
                    'deliveryAddressRelation',
                    'member',
                ]);
            } else {
                // 一般用戶僅查看自己的訂單
                $query = Order::where('member_id', $user->id)
                    ->with([
                        'orderItems.product',
                        'member.defaultDeliveryAddress',
                        'member.deliveryAddresses',
                        'creditCard',
                        'deliveryAddressRelation',
                        'member',
                    ]);
            }

            // 處理過濾
            $filters = $request->input('filter', []);
            if (!empty($filters)) {
                if (isset($filters['q'])) {
                    $q = $filters['q'];
                    $query->where('order_number', 'like', "%{$q}%");
                }
                if (isset($filters['status'])) {
                    $query->where('status', $filters['status']);
                }
                if (isset($filters['member_id']) && $user->role === 'admin') {
                    $query->where('member_id', $filters['member_id']);
                }
                // 可以在此處添加更多的過濾條件
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
         * 顯示指定訂單，包含訂單明細及產品資料
         */
        public function show(Request $request, $id)
        {
            $user = $request->user();

            $order = Order::where('id', $id)
                ->with([
                    'orderItems.product',
                    'member.defaultDeliveryAddress',
                    'member.deliveryAddresses',
                    'member',
                    'creditCard',
                    'deliveryAddressRelation',
                ])
                ->first();

            if (!$order) {
                return response()->json(['message' => 'Order not found'], 404);
            }

            // 如果用戶不是管理員，且訂單不屬於該用戶，則拒絕訪問
            if ($user->role !== 'admin' && $order->member_id !== $user->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            return response()->json($order, 200);
        }

        /**
         * 新增或更新購物車（pending 訂單）
         */
        public function store(Request $request)
        {
            $data = $request->validate([
                'product_id'           => 'required|integer|exists:products,id',
                'quantity'             => 'required|integer|min:1',
                'price'                => 'required|numeric',
                'delivery_address_id'  => 'nullable|integer|exists:delivery_addresses,id',
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
                // 更新配送地址
                if (isset($data['delivery_address_id'])) {
                    $pendingOrder->delivery_address_id = $data['delivery_address_id'];
                }
                $pendingOrder->save();

                return response()->json($pendingOrder->load([
                    'orderItems.product',
                    'member.defaultDeliveryAddress',
                    'member',
                    'creditCard',
                    'deliveryAddress',
                ]), 200);
            }

            // 如果沒有 pending 訂單，則新增一張新的 pending 訂單
            $today = now()->format('Ymd');
            $lastOrder = Order::whereDate('created_at', now()->toDateString())
                ->orderBy('id', 'desc')
                ->first();

            $sequence    = $lastOrder ? intval(substr($lastOrder->order_number, -5)) + 1 : 1;
            $orderNumber = 'O' . $today . str_pad($sequence, 5, '0', STR_PAD_LEFT);
            $data['order_number']     = $orderNumber;
            $data['status']           = 'pending';
            $data['total_amount']     = $data['price'] * $data['quantity'];
            $data['payment_method']   = 'cash_on_delivery'; // 預設付款方式，可根據需求調整
            $data['shipping_fee']     = 0.00; // 預設運費，可根據需求調整
            $data['delivery_address_id'] = $data['delivery_address_id'] ?? null; // 使用傳入的配送地址或 null
            $data['member_id']        = $user->id; // 設置為當前用戶的 ID

            $order = Order::create($data);

            // 將品項加入新建立的訂單
            $order->orderItems()->create([
                'product_id' => $data['product_id'],
                'quantity'   => $data['quantity'],
                'price'      => $data['price'],
            ]);

            return response()->json($order->load([
                'orderItems.product',
                'member.defaultDeliveryAddress',
                'member',
                'creditCard',
                'deliveryAddress',
            ]), 201);
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

            return response()->json($order->load([
                'orderItems.product',
                'member.defaultDeliveryAddress',
                'member',
                'creditCard',
                'deliveryAddress',
            ]), 200);
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

            return response()->json($order->load([
                'orderItems.product',
                'member.defaultDeliveryAddress',
                'member',
                'creditCard',
                'deliveryAddress',
            ]), 200);
        }

        /**
         * 更新訂單
         */
        public function update(Request $request, $id)
        {
            $user = $request->user();

            $order = Order::findOrFail($id);

            // 如果用戶不是管理員，且訂單不屬於該用戶，則拒絕訪問
            if ($user->role !== 'admin' && $order->member_id !== $user->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $data = $request->validate([
                'status'              => 'in:pending,processing,shipped,completed,cancelled',
                'total_amount'        => 'numeric',
                'payment_method'      => 'in:credit_card,bank_transfer,cash_on_delivery',
                'shipping_fee'        => 'numeric',
                'delivery_address_id' => 'nullable|integer|exists:delivery_addresses,id',
                'credit_card_id'      => 'nullable|integer|exists:credit_cards,id',
            ]);

            $order->update($data);

            return response()->json($order->load([
                'orderItems.product',
                'member.defaultDeliveryAddress',
                'member',
                'creditCard',
                'deliveryAddress',
            ]), 200);
        }

        /**
         * 刪除訂單
         */
        public function destroy(Request $request, $id)
        {
            $user = $request->user();

            $order = Order::findOrFail($id);

            // 如果用戶不是管理員，且訂單不屬於該用戶，則拒絕刪除
            if ($user->role !== 'admin' && $order->member_id !== $user->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $order->delete();

            return response()->json(['message' => 'Order deleted successfully'], 200);
        }

        /**
         * 處理訂單（將狀態從 pending 更新為 processing）
         */
        public function processOrder(Request $request)
        {
            $user = $request->user();

            // 查找指定的 pending 訂單
            $order = Order::where('member_id', $user->id)
                ->where('status', 'pending')
                ->firstOrFail();

            // 將訂單狀態更新為 processing
            $order->update(['status' => 'processing']);

            return response()->json($order->load([
                'orderItems.product',
                'member.defaultDeliveryAddress',
                'member',
                'creditCard',
                'deliveryAddress',
            ]), 200);
        }
    }
