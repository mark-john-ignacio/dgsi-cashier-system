<?php

namespace App\Http\Controllers;

use App\Models\SchoolYear;
use Illuminate\Http\Request;

class SchoolYearController extends Controller
{
    public function index()
    {
        return view('school-years.index', ['years' => SchoolYear::orderByDesc('name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:20', 'unique:school_years']]);
        SchoolYear::create($data);

        return back()->with('status', 'School year added.');
    }

    public function activate(SchoolYear $schoolYear)
    {
        $schoolYear->activate();

        return back()->with('status', "{$schoolYear->name} is now the active school year.");
    }
}
