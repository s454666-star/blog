<?php

    namespace App\Http\Controllers;

    use App\Models\DeliveryAddress;
    use Illuminate\Http\Request;

    class DeliveryAddressController extends Controller
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

            $query = DeliveryAddress::query();

            $filters = $request->input('filter', []);
            if (!empty($filters) && isset($filters['q'])) {
                $q = $filters['q'];
                $query->where('recipient', 'like', "%{$q}%");
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
            $address = DeliveryAddress::findOrFail($id);
            return response()->json($address, 200);
        }

        public function store(Request $request)
        {
            $data = $request->validate([
                'member_id'   => 'required|integer|exists:members,id',
                'recipient'   => 'required|string',
                'phone'       => 'required|string',
                'address'     => 'required|string',
                'postal_code' => 'required|string',
                'country'     => 'required|string',
                'city'        => 'required|string',
                'is_default'  => 'boolean'
            ]);

            $address = DeliveryAddress::create($data);
            return response()->json($address, 201);
        }

        public function update(Request $request, $id)
        {
            $address = DeliveryAddress::findOrFail($id);

            $data = $request->validate([
                'recipient'   => 'string',
                'phone'       => 'string',
                'address'     => 'string',
                'postal_code' => 'string',
                'country'     => 'string',
                'city'        => 'string',
                'is_default'  => 'boolean'
            ]);

            $address->update($data);
            return response()->json($address, 200);
        }

        public function destroy($id)
        {
            $address = DeliveryAddress::findOrFail($id);
            $address->delete();
            return response()->json(['message' => 'Delivery address deleted successfully'], 200);
        }
    }
