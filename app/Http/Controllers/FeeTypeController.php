<?php

namespace App\Http\Controllers;

use App\Models\FeeType;
use Illuminate\Http\Request;

class FeeTypeController extends Controller
{
    public function index()
    {
        return view('fees.types', ['feeTypes' => FeeType::orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:100', 'unique:fee_types']]);
        FeeType::create($data);

        return back()->with('status', 'Fee type added.');
    }
}
