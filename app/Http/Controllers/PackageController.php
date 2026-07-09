<?php

namespace App\Http\Controllers;

use App\Models\Package;
use Illuminate\Http\Request;

class PackageController extends Controller
{
    public function index()
    {
        $packages = Package::with('tingkatan')->get()->map(function ($pkg) {
            return [
                'id'             => $pkg->id,
                'nama_paket'     => $pkg->nama_paket,
                'jurusan'        => $pkg->jurusan,
                'tingkatan_id'   => $pkg->tingkatan_id,
                'tingkatan_name' => $pkg->tingkatan ? $pkg->tingkatan->nama_tingkat : null,
                'deskripsi'      => $pkg->deskripsi,
                'created_at'     => $pkg->created_at,
                'updated_at'     => $pkg->updated_at,
            ];
        });
        return response()->json($packages);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'nama_paket'   => 'required|string',
            'jurusan'      => 'required|string',
            'tingkatan_id' => 'required|exists:tingkatans,id',
            'deskripsi'    => 'nullable|string'
        ]);

        $package = Package::create($validated);
        return response()->json(['status' => 'success', 'message' => 'Package created', 'data' => $package], 201);
    }

    public function show($id)
    {
        $package = Package::with('tingkatan')->findOrFail($id);
        return response()->json($package);
    }

    public function update(Request $request, $id)
    {
        $package = Package::findOrFail($id);

        $validated = $request->validate([
            'nama_paket'   => 'sometimes|required|string',
            'jurusan'      => 'sometimes|required|string',
            'tingkatan_id' => 'sometimes|required|exists:tingkatans,id',
            'deskripsi'    => 'nullable|string'
        ]);

        $package->update($validated);
        return response()->json(['status' => 'success', 'message' => 'Package updated', 'data' => $package]);
    }

    public function destroy($id)
    {
        $package = Package::findOrFail($id);
        $package->delete();
        return response()->json(['message' => 'Package deleted successfully']);
    }
}
