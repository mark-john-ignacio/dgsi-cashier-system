<?php

namespace App\Http\Controllers;

use App\Models\FeeStructure;
use App\Models\FeeType;
use App\Models\SchoolYear;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class FeeStructureController extends Controller
{
    public function index()
    {
        return view('fees.structures', [
            'structures' => FeeStructure::with(['schoolYear', 'items.feeType'])
                ->orderByDesc('school_year_id')->orderBy('grade_level')->get(),
        ]);
    }

    public function create()
    {
        return $this->form(new FeeStructure());
    }

    public function edit(FeeStructure $feeStructure)
    {
        return $this->form($feeStructure->load('items'));
    }

    private function form(FeeStructure $structure)
    {
        return view('fees.structure-form', [
            'structure' => $structure,
            'years' => SchoolYear::orderByDesc('name')->get(),
            'feeTypes' => FeeType::orderBy('name')->get(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);

        DB::transaction(function () use ($data) {
            $structure = FeeStructure::create([
                'school_year_id' => $data['school_year_id'],
                'grade_level' => $data['grade_level'],
            ]);
            foreach ($data['items'] as $item) {
                $structure->items()->create($item);
            }
        });

        return redirect()->route('fee-structures.index')->with('status', 'Fee structure created.');
    }

    public function update(Request $request, FeeStructure $feeStructure)
    {
        $data = $this->validated($request, $feeStructure);

        DB::transaction(function () use ($feeStructure, $data) {
            $feeStructure->update([
                'school_year_id' => $data['school_year_id'],
                'grade_level' => $data['grade_level'],
            ]);
            $feeStructure->items()->delete(); // safe: ledgers hold copies, never references
            foreach ($data['items'] as $item) {
                $feeStructure->items()->create($item);
            }
        });

        return redirect()->route('fee-structures.index')
            ->with('status', 'Fee structure updated. Existing student ledgers are unchanged.');
    }

    private function validated(Request $request, ?FeeStructure $existing = null): array
    {
        return $request->validate([
            'school_year_id' => ['required', 'exists:school_years,id'],
            'grade_level' => ['required', 'string', 'max:30',
                Rule::unique('fee_structures')
                    ->where('school_year_id', $request->input('school_year_id'))
                    ->ignore($existing)],
            'items' => ['required', 'array', 'min:1'],
            'items.*.fee_type_id' => ['required', 'exists:fee_types,id'],
            'items.*.amount' => ['required', 'numeric', 'min:0'],
        ]);
    }
}
