<?php

namespace App\Http\Controllers;

use App\Models\Subject;
use App\Models\Package;
use Illuminate\Http\Request;

class SubjectController extends Controller
{
    public function index()
    {
        $subjects = Subject::with(['packages', 'tingkatan'])->get()->map(function ($subject) {
            return [
                'id'             => $subject->id,
                'kode_mapel'     => $subject->kode_mapel,
                'nama'           => $subject->nama,
                'kategori'       => $subject->kategori,
                'tingkatan_id'   => $subject->tingkatan_id,
                'tingkatan_name' => $subject->tingkatan ? $subject->tingkatan->nama_tingkat : null,
                'jam_pelajaran'  => $subject->jam_pelajaran,
                'package_ids'    => $subject->packages->pluck('id'),
                'packages'       => $subject->packages->pluck('nama_paket')->implode(', ')
            ];
        });
        return response()->json($subjects);
    }

    public function store(Request $request)
    {
        // Handle field mapping from Android if necessary
        if (!$request->has('nama') && $request->has('nama_mapel')) {
            $request->merge(['nama' => $request->nama_mapel]);
        }

        $validated = $request->validate([
            'kode_mapel'    => 'required|string|unique:subjects',
            'nama'          => 'required|string',
            'kategori'      => 'required|in:umum,pilihan',
            'tingkatan_id'  => 'required|exists:tingkatans,id',
            'jam_pelajaran' => 'nullable|integer|min:1|max:10',
            'package_ids'   => 'nullable|array',
            'package_ids.*' => 'exists:packages,id',
            'packages'      => 'nullable|string'
        ]);

        $subject = Subject::create([
            'kode_mapel'    => $validated['kode_mapel'],
            'nama'          => $validated['nama'],
            'kategori'      => $validated['kategori'],
            'tingkatan_id'  => $validated['tingkatan_id'],
            'jam_pelajaran' => $validated['jam_pelajaran'] ?? 3,
        ]);

        // Support multiple packages from comma-separated string
        if ($request->has('packages') && !empty($request->packages)) {
            $packageNames = explode(', ', $request->packages);
            $packageIds = Package::whereIn('nama_paket', $packageNames)
                        ->orWhereIn('jurusan', $packageNames)
                        ->pluck('id');
            
            if ($packageIds->isNotEmpty()) {
                $subject->packages()->attach($packageIds);
            }
        }

        if (!empty($validated['package_ids'])) {
            $subject->packages()->attach($validated['package_ids']);
        }

        $subject->load(['packages', 'tingkatan']);
        
        return response()->json([
            'status' => 'success',
            'message' => 'Subject created successfully',
            'data' => [
                'id'             => $subject->id,
                'kode_mapel'     => $subject->kode_mapel,
                'nama'           => $subject->nama,
                'kategori'       => $subject->kategori,
                'tingkatan_id'   => $subject->tingkatan_id,
                'tingkatan_name' => $subject->tingkatan ? $subject->tingkatan->nama_tingkat : null,
                'jam_pelajaran'  => $subject->jam_pelajaran,
                'package_ids'    => $subject->packages->pluck('id'),
                'packages'       => $subject->packages->pluck('nama_paket')->implode(', ')
            ]
        ], 201);
    }

    public function show($id)
    {
        $subject = Subject::with(['packages', 'tingkatan'])->findOrFail($id);
        return response()->json([
            'id'             => $subject->id,
            'kode_mapel'     => $subject->kode_mapel,
            'nama'           => $subject->nama,
            'kategori'       => $subject->kategori,
            'tingkatan_id'   => $subject->tingkatan_id,
            'tingkatan_name' => $subject->tingkatan ? $subject->tingkatan->nama_tingkat : null,
            'jam_pelajaran'  => $subject->jam_pelajaran,
            'package_ids'    => $subject->packages->pluck('id'),
            'packages'       => $subject->packages->pluck('nama_paket')->implode(', ')
        ]);
    }

    public function update(Request $request, $id)
    {
        $subject = Subject::findOrFail($id);

        if (!$request->has('nama') && $request->has('nama_mapel')) {
            $request->merge(['nama' => $request->nama_mapel]);
        }

        $validated = $request->validate([
            'kode_mapel'    => 'sometimes|required|string|unique:subjects,kode_mapel,' . $id,
            'nama'          => 'sometimes|required|string',
            'kategori'      => 'sometimes|required|in:umum,pilihan',
            'tingkatan_id'  => 'sometimes|required|exists:tingkatans,id',
            'jam_pelajaran' => 'sometimes|required|integer|min:1|max:10',
            'package_ids'   => 'nullable|array',
            'package_ids.*' => 'exists:packages,id',
            'packages'      => 'nullable|string'
        ]);

        $subject->update($validated);

        // Sync multiple packages from comma-separated string
        if ($request->has('packages') && !empty($request->packages)) {
            $packageNames = explode(', ', $request->packages);
            $packageIds = Package::whereIn('nama_paket', $packageNames)
                        ->orWhereIn('jurusan', $packageNames)
                        ->pluck('id');
            
            if ($packageIds->isNotEmpty()) {
                $subject->packages()->sync($packageIds);
            }
        }

        if (isset($validated['package_ids'])) {
            $subject->packages()->sync($validated['package_ids']);
        }

        $subject->load(['packages', 'tingkatan']);

        return response()->json([
            'status' => 'success',
            'message' => 'Subject updated successfully',
            'data' => [
                'id'             => $subject->id,
                'kode_mapel'     => $subject->kode_mapel,
                'nama'           => $subject->nama,
                'kategori'       => $subject->kategori,
                'tingkatan_id'   => $subject->tingkatan_id,
                'tingkatan_name' => $subject->tingkatan ? $subject->tingkatan->nama_tingkat : null,
                'jam_pelajaran'  => $subject->jam_pelajaran,
                'package_ids'    => $subject->packages->pluck('id'),
                'packages'       => $subject->packages->pluck('nama_paket')->implode(', ')
            ]
        ]);
    }

    public function destroy($id)
    {
        $subject = Subject::findOrFail($id);
        $subject->delete();
        return response()->json([
            'status' => 'success',
            'message' => 'Subject deleted successfully'
        ]);
    }
}
