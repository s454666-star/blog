<?php
    namespace App\Http\Controllers;

    use App\Models\ReturnOrder;
    use App\Models\OrderItem;
    use Illuminate\Http\Request;
    use Illuminate\Support\Facades\Validator;

    class ReturnOrderController extends Controller
    {
        public function __construct()
        {
            $this->middleware('auth:sanctum');
        }

        /**
         * 創建退貨單
         */
        public function store(Request $request)
        {
            $validator = Validator::make($request->all(), [
                'order_id'         => 'required|integer|exists:orders,id',
                'order_item_id'    => 'required|integer|exists:order_items,id',
                'reason'           => 'required|string|max:500',
                'return_quantity'  => 'required|integer|min:1',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $user = $request->user();

            $orderItem = OrderItem::where('order_items.id', $request->order_item_id)
                ->where('order_items.order_id', $request->order_id)
                ->where('orders.member_id', $user->id)
                ->join('orders', 'order_items.order_id', '=', 'orders.id')
                ->first();

            if (!$orderItem) {
                return response()->json(['message' => '訂單項目不存在或不屬於該會員'], 404);
            }

            $availableReturn = $orderItem->quantity - $orderItem->return_quantity;

            if ($request->return_quantity > $availableReturn) {
                return response()->json(['message' => '退貨數量超過可退貨量'], 400);
            }

            $returnOrder = ReturnOrder::create([
                'member_id'         => $user->id,
                'order_id'          => $request->order_id,
                'order_item_id'     => $request->order_item_id,
                'reason'            => $request->reason,
                'return_quantity'   => $request->return_quantity,
                'status'            => '已接收',
            ]);

            // 更新 order_items 的 return_quantity
            $orderItem->return_quantity += $request->return_quantity;
            $orderItem->save();

            return response()->json($returnOrder, 201);
        }

        /**
         * 取得會員的所有退貨單
         */
        public function index(Request $request)
        {
            $user = $request->user();

            $returnOrders = ReturnOrder::where('member_id', $user->id)
                ->with(['order', 'orderItem.product'])
                ->get();

            return response()->json($returnOrders, 200);
        }

        /**
         * 取得單一退貨單
         */
        public function show(Request $request, $id)
        {
            $user = $request->user();

            $returnOrder = ReturnOrder::where('id', $id)
                ->where('member_id', $user->id)
                ->with(['order', 'orderItem.product'])
                ->first();

            if (!$returnOrder) {
                return response()->json(['message' => '退貨單不存在'], 404);
            }

            return response()->json($returnOrder, 200);
        }

        /**
         * 更新退貨單狀態
         */
        public function update(Request $request, $id)
        {
            $user = $request->user();

            $returnOrder = ReturnOrder::where('id', $id)
                ->where('member_id', $user->id)
                ->first();

            if (!$returnOrder) {
                return response()->json(['message' => '退貨單不存在'], 404);
            }

            $validator = Validator::make($request->all(), [
                'status' => 'required|in:已接收,物流運送中,已完成,已取消',
            ]);

            if ($validator->fails()) {
                return response()->json(['errors' => $validator->errors()], 422);
            }

            $returnOrder->status = $request->status;
            $returnOrder->save();

            return response()->json($returnOrder, 200);
        }

        /**
         * 刪除退貨單
         */
        public function destroy(Request $request, $id)
        {
            $user = $request->user();

            $returnOrder = ReturnOrder::where('id', $id)
                ->where('member_id', $user->id)
                ->first();

            if (!$returnOrder) {
                return response()->json(['message' => '退貨單不存在'], 404);
            }

            // 回寫 order_items 的 return_quantity
            $orderItem = $returnOrder->orderItem;
            $orderItem->return_quantity -= $returnOrder->return_quantity;
            $orderItem->save();

            $returnOrder->delete();

            return response()->json(['message' => '退貨單已刪除'], 200);
        }
    }
