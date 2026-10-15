@extends('layouts.app')
@section('title','Proses ekspor')
@section('content')
<div class="page-heading"><div><h1>Ekspor laporan</h1><p class="text-muted">Permintaan {{ $state['created_at'] }} WIB. Tautan berlaku satu jam setelah berkas siap.</p></div></div>
<div class="card card-body">
@if($state['status']==='ready')
<h2 class="h5">Laporan siap diunduh</h2><p>{{ $state['rows'] }} baris sesuai filter telah diproses.</p><div><a class="btn btn-success" href="{{ route('reports.exportDownload',$token) }}">Unduh laporan</a></div>
@elseif($state['status']==='failed')
<h2 class="h5">Ekspor belum berhasil</h2><p>Silakan ulangi permintaan. Administrator dapat memeriksa antrean dan log server.</p>
@else
<h2 class="h5">{{ $state['status']==='queued'?'Menunggu pemrosesan':'Laporan sedang diproses' }}</h2><p>Anda dapat tetap menggunakan aplikasi. Muat ulang halaman untuk melihat hasil.</p><div><a class="btn btn-primary" href="{{ route('reports.exportStatus',$token) }}">Periksa hasil</a></div>
@endif
<a class="mt-3" href="{{ auth()->user()->hasPermission('reports.view') ? route('reports.index', $state['filters']) : route('customers.index') }}">Kembali ke data</a>
</div>
@endsection
