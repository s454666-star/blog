<?php

    namespace App\Http\Controllers;

    use App\Models\CreditCard;
    use Illuminate\Http\Request;

    class CreditCardController extends Controller
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

            $query = CreditCard::query();

            $filters = $request->input('filter', []);
            if (!empty($filters) && isset($filters['q'])) {
                $q = $filters['q'];
                $query->where('cardholder_name', 'like', "%{$q}%");
            }

            $total = $query->count();

            $creditCards = $query->orderBy($sortField, $sortDirection)
                ->skip($from)
                ->take($to - $from + 1)
                ->get();

            return response()->json($creditCards, 200)
                ->header('X-Total-Count', $total)
                ->header('Content-Range', "items {$from}-{$to}/{$total}")
                ->header('Access-Control-Expose-Headers', 'X-Total-Count, Content-Range');
        }

        public function show($id)
        {
            $creditCard = CreditCard::findOrFail($id);
            return response()->json($creditCard, 200);
        }

        public function store(Request $request)
        {
            $data = $request->validate([
                'member_id'       => 'required|integer|exists:members,id',
                'cardholder_name' => 'required|string',
                'card_number'     => 'required|string|unique:credit_cards,card_number',
                'expiry_date'     => 'required|string',
                'card_type'       => 'required|in:Visa,MasterCard,American Express,Discover',
                'billing_address' => 'required|string',
                'postal_code'     => 'required|string',
                'country'         => 'required|string',
                'is_default'      => 'boolean'
            ]);

            $creditCard = CreditCard::create($data);
            return response()->json($creditCard, 201);
        }

        public function update(Request $request, $id)
        {
            $creditCard = CreditCard::findOrFail($id);

            $data = $request->validate([
                'cardholder_name' => 'string',
                'card_number'     => 'string|unique:credit_cards,card_number,' . $creditCard->id,
                'expiry_date'     => 'string',
                'card_type'       => 'in:Visa,MasterCard,American Express,Discover',
                'billing_address' => 'string',
                'postal_code'     => 'string',
                'country'         => 'string',
                'is_default'      => 'boolean'
            ]);

            $creditCard->update($data);
            return response()->json($creditCard, 200);
        }

        public function destroy($id)
        {
            $creditCard = CreditCard::findOrFail($id);
            $creditCard->delete();
            return response()->json(['message' => 'Credit card deleted successfully'], 200);
        }
    }
