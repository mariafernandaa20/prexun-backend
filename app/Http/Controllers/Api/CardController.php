<?php

namespace App\Http\Controllers\Api;

use App\Models\Card;
use App\Models\Campus;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Log;

class CardController extends Controller
{
    /**
     * Display a listing of the cards.
     */
    public function index(Request $request)
    {
        $campus_id = $request->get('campus_id');
        $all = filter_var($request->get('all', false), FILTER_VALIDATE_BOOLEAN);
    
        $query = Card::query();
    
        if ($campus_id) {
            $query->where('campus_id', $campus_id);
        }
    
        if (!$all) {
            $query->where('is_hidden', false);
        }
    
        $cards = $query->get();
    
        return response()->json($cards);
    }
    
    /**
     * Show the form for creating a new card.
     */
    public function create()
    {
        $campuses = Campus::all();
        return view('cards.create', compact('campuses'));
    }

    /**
     * Store a newly created card in storage.
     */
    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'number' => 'required|string|unique:cards,number',
            'name' => 'required|string|max:255',

            'campus_id' => 'required|exists:campuses,id',
        ]);

        if ($validator->fails()) {
            return redirect()->route('cards.create')
                ->withErrors($validator)
                ->withInput();
        }

        Card::create($request->all());

        return redirect()->route('cards.index')
            ->with('success', 'Card created successfully.');
    }

    /**
     * Display the specified card.
     */
    public function show(Card $card)
    {
        return view('cards.show', compact('card'));
    }

    /**
     * Show the form for editing the specified card.
     */
    public function edit(Card $card)
    {
        $campuses = Campus::all();
        return view('cards.edit', compact('card', 'campuses'));
    }

    /**
     * Update the specified card in storage.
     */
    public function update(Request $request, Card $card)
    {
        $validator = Validator::make($request->all(), [
            'number' => 'sometimes|string|unique:cards,number,' . $card->id,
            'name' => 'required|string|max:255',
            'campus_id' => 'required|exists:campuses,id',
        ]);

        if ($validator->fails()) {
            return redirect()->route('cards.edit', $card->id)
                ->withErrors($validator)
                ->withInput();
        }

        $card->update($request->all());

        return redirect()->route('cards.index')
            ->with('success', 'Card updated successfully.');
    }

    /**
     * Remove the specified card from storage.
     */
    public function destroy(Card $card)
    {
        $card->delete();

        return redirect()->route('cards.index')
            ->with('success', 'Card deleted successfully.');
    }

    /**
     * API method to create a card
     */
    public function apiStore(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'number' => 'required|string|unique:cards,number',
            'name' => 'required|string|max:255',
            'clabe' => 'required|string|max:255',
            'sat' => 'sometimes|boolean',
            'campus_id' => 'required|exists:campuses,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $card = Card::create($request->all());
        
        return response()->json(['message' => 'Card created successfully', 'data' => $card], 201);
    }

    /**
     * API method to update a card
     */
    public function apiUpdate(Request $request, Card $card)
    {

        Log::info('Updating card: ', $request->all());
        $validator = Validator::make($request->all(), [
            'number' => 'required|string|unique:cards,number,' . $card->id,
            'name' => 'required|string|max:255',
            'campus_id' => 'required|exists:campuses,id',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $card->update($request->all());
        
        if ($request->has('is_hidden')) {
            $card->is_hidden = filter_var($request->is_hidden, FILTER_VALIDATE_BOOLEAN);
            $card->save();
        }
        
        return response()->json(['message' => 'Card updated successfully', 'data' => $card], 200);
    }

    /**
     * API method to delete a card
     */
    public function apiDestroy(Card $card)
    {
        $card->delete();
        
        return response()->json(['message' => 'Card deleted successfully'], 200);
    }
}