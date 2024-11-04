<?php
    namespace App\Http\Controllers;

    use App\Models\CreditCard;
    use Illuminate\Http\Request;

    class CreditCardController extends Controller
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

            $query = CreditCard::where('member_id', $user->id);

            $filters = $request->input('filter', []);
            if (!empty($filters)) {
                if (isset($filters['q'])) {
                    $q = $filters['q'];
                    $query->where('cardholder_name', 'like', "%{$q}%");
                }
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
            $user       = request()->user();
            $creditCard = CreditCard::where('id', $id)->where('member_id', $user->id)->firstOrFail();
            return response()->json($creditCard, 200);
        }

        public function store(Request $request)
        {
            $data = $request->validate([
                                           'cardholder_name' => 'required|string',
                                           'card_number'     => 'required|string|unique:credit_cards,card_number',
                                           'expiry_date'     => 'required|string',
                                           'card_type'       => 'required|in:Visa,MasterCard,American Express,Discover',
                                           'billing_address' => 'required|string',
                                           'postal_code'     => 'required|string',
                                           'country'         => 'required|string',
                                           'is_default'      => 'boolean'
                                       ]);

            $user              = $request->user();
            $data['member_id'] = $user->id;

            // 如果新增的是主要信用卡，則其他信用卡設為非主要
            if (isset($data['is_default']) && $data['is_default']) {
                CreditCard::where('member_id', $user->id)->update(['is_default' => false]);
            }

            $creditCard = CreditCard::create($data);
            return response()->json($creditCard, 201);
        }

        public function update(Request $request, $id)
        {
            $user       = $request->user();
            $creditCard = CreditCard::where('id', $id)->where('member_id', $user->id)->firstOrFail();

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

            if (isset($data['is_default']) && $data['is_default']) {
                CreditCard::where('member_id', $user->id)->update(['is_default' => false]);
            }

            $creditCard->update($data);
            return response()->json($creditCard, 200);
        }

        public function destroy($id)
        {
            $user       = request()->user();
            $creditCard = CreditCard::where('id', $id)->where('member_id', $user->id)->firstOrFail();
            $creditCard->delete();
            return response()->json(['message' => 'Credit card deleted successfully'], 200);
        }
    }
