<?php
    namespace App\Http\Controllers;

    use App\Models\Order;
    use App\Models\OrderItem;
    use Illuminate\Http\Request;
    use Carbon\Carbon;

    class OrderController extends Controller
    {
        public function __construct()
        {
            $this->middleware('auth:sanctum');
        }

        public function index(Request $request)
        {
            $user = $request->user();

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

            if ($user->role === 'admin') {
                $query = Order::with([
                    'orderItems.product',
                    'member.defaultDeliveryAddress',
                    'creditCard',
                    'deliveryAddress', // 修改此處
                    'member',
                    'returnOrders.orderItem.product',
                    'returnOrders.order.deliveryAddress', // 修改此處
                ]);
            } else {
                $query = Order::where('member_id', $user->id)
                    ->with([
                        'orderItems.product',
                        'member.defaultDeliveryAddress',
                        'creditCard',
                        'deliveryAddress', // 修改此處
                        'member',
                        'returnOrders.orderItem.product',
                        'returnOrders.order.deliveryAddress', // 修改此處
                    ]);
            }

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

        public function show(Request $request, $id)
        {
            $user = $request->user();

            $order = Order::where('id', $id)
                ->where(function($query) use ($user) {
                    if ($user->role !== 'admin') {
                        $query->where('member_id', $user->id);
                    }
                })
                ->with([
                    'orderItems.product',
                    'member.defaultDeliveryAddress',
                    'creditCard',
                    'deliveryAddress', // 修改此處
                    'member',
                    'returnOrders.orderItem.product',
                    'returnOrders.order.deliveryAddress', // 修改此處
                ])
                ->first();

            if (!$order) {
                return response()->json(['message' => '訂單不存在'], 404);
            }

            return response()->json($order, 200);
        }

        public function store(Request $request)
        {
            $data = $request->validate([
                'product_id'          => 'required|integer|exists:products,id',
                'quantity'            => 'required|integer|min:1',
                'price'               => 'required|numeric',
                'delivery_address_id' => 'nullable|integer|exists:delivery_addresses,id',
            ]);

            $user = $request->user();

            $pendingOrder = Order::where('member_id', $user->id)
                ->where('status', 'pending')
                ->first();

            if ($pendingOrder) {
                $orderItem = $pendingOrder->orderItems()->where('product_id', $data['product_id'])->first();

                if ($orderItem) {
                    $orderItem->quantity += $data['quantity'];
                    $orderItem->save();
                } else {
                    $pendingOrder->orderItems()->create([
                        'product_id' => $data['product_id'],
                        'quantity'   => $data['quantity'],
                        'price'      => $data['price'],
                    ]);
                }

                $pendingOrder->total_amount += $data['price'] * $data['quantity'];
                if (isset($data['delivery_address_id'])) {
                    $pendingOrder->delivery_address_id = $data['delivery_address_id'];
                }
                $pendingOrder->save();

                return response()->json($pendingOrder->load([
                    'orderItems.product',
                    'member.defaultDeliveryAddress',
                    'member',
                    'creditCard',
                    'deliveryAddress', // 修改此處
                    'returnOrders.orderItem.product',
                    'returnOrders.order.deliveryAddress', // 修改此處
                ]), 200);
            }

            $today     = now()->format('Ymd');
            $lastOrder = Order::whereDate('created_at', now()->toDateString())
                ->orderBy('id', 'desc')
                ->first();

            $sequence                    = $lastOrder ? intval(substr($lastOrder->order_number, -5)) + 1 : 1;
            $orderNumber                 = 'O' . $today . str_pad($sequence, 5, '0', STR_PAD_LEFT);
            $data['order_number']        = $orderNumber;
            $data['status']              = 'pending';
            $data['total_amount']        = $data['price'] * $data['quantity'];
            $data['payment_method']      = 'cash_on_delivery';
            $data['shipping_fee']        = 0.00;
            $data['delivery_address_id'] = $data['delivery_address_id'] ?? null;
            $data['member_id']           = $user->id;

            $order = Order::create($data);

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
                'deliveryAddress', // 修改此處
                'returnOrders.orderItem.product',
                'returnOrders.order.deliveryAddress', // 修改此處
            ]), 201);
        }

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

            $quantityDifference  = $data['quantity'] - $orderItem->quantity;
            $order->total_amount += $orderItem->price * $quantityDifference;

            $orderItem->quantity = $data['quantity'];
            $orderItem->save();

            $order->save();

            return response()->json($order->load([
                'orderItems.product',
                'member.defaultDeliveryAddress',
                'member',
                'creditCard',
                'deliveryAddress', // 修改此處
                'returnOrders.orderItem.product',
                'returnOrders.order.deliveryAddress', // 修改此處
            ]), 200);
        }

        public function deleteItem(Request $request, $orderId, $itemId)
        {
            $user = $request->user();

            $order = Order::where('id', $orderId)
                ->where('member_id', $user->id)
                ->where('status', 'pending')
                ->firstOrFail();

            $orderItem           = $order->orderItems()->where('id', $itemId)->firstOrFail();
            $order->total_amount -= $orderItem->price * $orderItem->quantity;
            $orderItem->delete();
            $order->save();

            return response()->json($order->load([
                'orderItems.product',
                'member.defaultDeliveryAddress',
                'member',
                'creditCard',
                'deliveryAddress', // 修改此處
                'returnOrders.orderItem.product',
                'returnOrders.order.deliveryAddress', // 修改此處
            ]), 200);
        }

        public function update(Request $request, $id)
        {
            $user = $request->user();

            $query = Order::with([
                'orderItems.product',
                'member.defaultDeliveryAddress',
                'creditCard',
                'deliveryAddress', // 修改此處
                'member',
                'returnOrders.orderItem.product',
                'returnOrders.order.deliveryAddress', // 修改此處
            ]);

            if ($user->role !== 'admin') {
                $query->where('member_id', $user->id);
            }

            $order = $query->where('id', $id)->first();

            if (!$order) {
                return response()->json(['message' => '訂單不存在'], 404);
            }

            $data = $request->validate([
                'status'              => 'in:pending,processing,shipped,completed,cancelled',
                'total_amount'        => 'numeric',
                'payment_method'      => 'in:credit_card,bank_transfer,cash_on_delivery',
                'shipping_fee'        => 'numeric',
                'delivery_address_id' => 'nullable|integer|exists:delivery_addresses,id',
                'credit_card_id'      => 'nullable|integer|exists:credit_cards,id',
                'order_date'          => 'nullable|date',
            ]);

            if (isset($data['status'])) {
                $order->status = $data['status'];
            }

            if (isset($data['order_date'])) {
                $order->order_date = Carbon::parse($data['order_date']);
            }

            $order->update($data);

            return response()->json($order->load([
                'orderItems.product',
                'member.defaultDeliveryAddress',
                'member',
                'creditCard',
                'deliveryAddress', // 修改此處
                'returnOrders.orderItem.product',
                'returnOrders.order.deliveryAddress', // 修改此處
            ]), 200);
        }

        public function destroy(Request $request, $id)
        {
            $user = $request->user();

            $order = Order::findOrFail($id);

            if ($user->role !== 'admin' && $order->member_id !== $user->id) {
                return response()->json(['message' => 'Unauthorized'], 403);
            }

            $order->status = 'cancelled';
            $order->save();

            return response()->json(['message' => 'Order status set to cancelled'], 200);
        }

        public function processOrder(Request $request)
        {
            $user = $request->user();

            $order = Order::where('member_id', $user->id)
                ->where('status', 'pending')
                ->firstOrFail();

            $order->update([
                'status' => 'processing',
                'order_date' => Carbon::now(),
            ]);

            return response()->json($order->load([
                'orderItems.product',
                'member.defaultDeliveryAddress',
                'member',
                'creditCard',
                'deliveryAddress', // 修改此處
                'returnOrders.orderItem.product',
                'returnOrders.order.deliveryAddress', // 修改此處
            ]), 200);
        }
    }
