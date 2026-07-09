<?php

namespace App\Http\Controllers;

use App\Models\Tingkatan;
use Illuminate\Http\Request;

class TingkatanController extends Controller
{
    public function index()
    {
        $tingkatans = Tingkatan::all()->map(function ($t) {
            return [
                'id'             => $t->id,
                'nama_tingkatan' => $t->nama_tingkat,
            ];
        });

        return response()->json($tingkatans);
    }
}
