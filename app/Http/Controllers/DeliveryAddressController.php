<?php
    namespace App\Http\Controllers;

    use App\Models\DeliveryAddress;
    use Illuminate\Http\Request;

    class DeliveryAddressController extends Controller
    {
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

            $query = DeliveryAddress::where('member_id', $user->id);

            $filters = $request->input('filter', []);
            if (!empty($filters)) {
                if (isset($filters['q'])) {
                    $q = $filters['q'];
                    $query->where('recipient', 'like', "%{$q}%");
                }
            }

            $total = $query->count();

            $addresses = $query->orderBy($sortField, $sortDirection)
                ->skip($from)
                ->take($to - $from + 1)
                ->get();

            return response()->json($addresses, 200)
                ->header('X-Total-Count', $total)
                ->header('Content-Range', "items {$from}-{$to}/{$total}")
                ->header('Access-Control-Expose-Headers', 'X-Total-Count, Content-Range');
        }

        public function show($id)
        {
            $user    = request()->user();
            $address = DeliveryAddress::where('id', $id)->where('member_id', $user->id)->firstOrFail();
            return response()->json($address, 200);
        }

        public function store(Request $request)
        {
            $data = $request->validate([
                'recipient'   => 'required|string',
                'phone'       => 'required|string',
                'address'     => 'required|string',
                'postal_code' => 'required|string',
                'country'     => 'required|string',
                'city'        => 'required|string',
                'is_default'  => 'boolean'
            ]);

            $user              = $request->user();
            $data['member_id'] = $user->id;

            // 如果新增的是主要地址，則其他地址設為非主要
            if (isset($data['is_default']) && $data['is_default']) {
                DeliveryAddress::where('member_id', $user->id)->update(['is_default' => false]);
            }

            $address = DeliveryAddress::create($data);
            return response()->json($address, 201);
        }

        public function update(Request $request, $id)
        {
            $user    = $request->user();
            $address = DeliveryAddress::where('id', $id)->where('member_id', $user->id)->firstOrFail();

            $data = $request->validate([
                'recipient'   => 'string',
                'phone'       => 'string',
                'address'     => 'string',
                'postal_code' => 'string',
                'country'     => 'string',
                'city'        => 'string',
                'is_default'  => 'boolean'
            ]);

            if (isset($data['is_default']) && $data['is_default']) {
                DeliveryAddress::where('member_id', $user->id)->update(['is_default' => false]);
            }

            $address->update($data);
            return response()->json($address, 200);
        }

        public function destroy($id)
        {
            $user    = request()->user();
            $address = DeliveryAddress::where('id', $id)->where('member_id', $user->id)->firstOrFail();
            $address->delete();
            return response()->json(['message' => 'Delivery address deleted successfully'], 200);
        }
    }
