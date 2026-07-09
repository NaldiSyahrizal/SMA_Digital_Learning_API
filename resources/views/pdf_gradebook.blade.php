<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Gradebook PDF</title>
    <style>
        body { font-family: 'Helvetica', 'Arial', sans-serif; font-size: 11px; color: #333; }
        .header { text-align: center; margin-bottom: 25px; border-bottom: 2px solid #102B5E; padding-bottom: 10px; }
        .header h1 { margin: 0; font-size: 18px; text-transform: uppercase; color: #102B5E; }
        .header p { margin: 5px 0 0; font-size: 13px; }
        
        table { width: 100%; border-collapse: collapse; margin-top: 15px; page-break-inside: auto; }
        tr { page-break-inside: avoid; page-break-after: auto; }
        th, td { border: 1px solid #777; padding: 6px 4px; text-align: center; }
        th { background-color: #f0f4f8; font-weight: bold; color: #102B5E; }
        .text-left { text-align: left; padding-left: 6px; }
        
        .footer { margin-top: 30px; font-size: 10px; text-align: right; font-style: italic; color: #777; border-top: 1px solid #ccc; padding-top: 5px; }
    </style>
</head>
<body>
    <div class="header">
        <h1>Buku Nilai (Gradebook) Siswa</h1>
        <p>Kelas: <strong>{{ $classroom->nama_kelas }}</strong> &nbsp;|&nbsp; Mata Pelajaran: <strong>{{ $subject->nama }}</strong></p>
    </div>

    <table>
        <thead>
            <tr>
                <th style="width: 3%;">No</th>
                <th class="text-left" style="width: 12%;">NIS</th>
                <th class="text-left" style="width: 25%;">Nama Siswa</th>
                @foreach($contents as $content)
                    <th>{{ ucfirst($content->tipe) }}<br/><span style="font-size: 9px; font-weight: normal;">{{ Str::limit($content->judul, 15) }}</span></th>
                @endforeach
                <th style="width: 10%;">Rata-rata</th>
            </tr>
        </thead>
        <tbody>
            @foreach($students as $index => $student)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td class="text-left">{{ $student->nis }}</td>
                <td class="text-left">{{ $student->nama_lengkap }}</td>
                
                @php 
                    $totalScore = 0; 
                    $totalWeight = 0;
                    $count = count($contents); 
                @endphp

                @foreach($contents as $content)
                    @php
                        $score = $studentScores[$student->id][$content->id] ?? '-';
                        $weight = $content->weight ?? 10; // Fallback jika tidak ada bobot
                        if(is_numeric($score)) {
                            $totalScore += ($score * $weight);
                            $totalWeight += $weight;
                        }
                    @endphp
                    <td>{{ $score }}<br/><span style="font-size: 8px; color: #555;">(Bobot: {{ $weight }})</span></td>
                @endforeach
                
                <td>
                    <strong>{{ $totalWeight > 0 ? round($totalScore / $totalWeight, 1) : 0 }}</strong>
                </td>
            </tr>
            @endforeach
        </tbody>
    </table>

    <div class="footer">
        Dicetak secara otomatis oleh Aplikasi Digital Learning &mdash; {{ date('d F Y H:i:s') }}
    </div>
</body>
</html>
