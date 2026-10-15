@extends('layouts.app')
@section('title', 'Template WhatsApp')
@section('content')
<div class="page-heading"><div><span class="eyebrow">PENGINGAT TAGIHAN</span><h1>Template WhatsApp</h1><p>Kelola isi pesan, bahasa, dan persetujuan template dari Meta.</p></div><a class="button secondary" href="{{ route('whatsapp.index') }}">Kembali</a></div>
<div class="alert alert-info">Hanya template BODY teks yang aktif, disetujui Meta, dan telah disinkronkan dengan isi yang sama yang dapat dikirim. Mengubah isi membatalkan status persetujuan lokal; gunakan nama Meta versi baru bila diperlukan.</div>
<div class="card padded mb-4"><h2 class="h5">Variabel tersedia</h2><div class="d-flex flex-wrap gap-2">@foreach($variables as $variable)<code>{<span>{{ $variable }}</span>}</code>@endforeach</div><p class="text-muted mb-0 mt-3">Data yang kosong akan ditandai pada pratinjau dan mencegah pengiriman. Persetujuan pelanggan dan nomor WhatsApp yang valid tetap diperlukan.</p></div>
@foreach($templates as $template)
<details class="card padded mb-3" @if($loop->first) open @endif>
    <summary class="d-flex flex-wrap align-items-center gap-2"><strong>{{ $template->name }}</strong><span class="badge {{ $template->status === 'APPROVED' && $template->synced_body_hash ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $template->status }}</span><span class="text-muted">{{ $template->active ? 'Aktif' : 'Nonaktif' }} · {{ $template->provider_name }} · {{ $template->language }}</span></summary>
    <form method="post" action="{{ route('whatsapp.templates.update', $template) }}" class="mt-3">
        @csrf @method('PUT')
        <div class="row g-3">
            <div class="col-md-6"><label class="field">Nama template<input name="name" value="{{ $template->name }}" required maxlength="255"></label></div>
            <div class="col-md-6"><label class="field">Nama template di Meta<input name="provider_name" value="{{ $template->provider_name }}" required pattern="[a-z][a-z0-9_]*" maxlength="128"></label></div>
            <div class="col-md-6"><label class="field">Bahasa<input name="language" value="{{ $template->language }}" required placeholder="id"></label></div>
            <div class="col-md-6"><label class="field">Penggunaan<select name="active"><option value="1" @selected($template->active)>Aktif</option><option value="0" @selected(!$template->active)>Nonaktif</option></select></label></div>
            <div class="col-12"><label class="field">Isi pesan<textarea name="body" rows="10" required maxlength="5000">{{ $template->body }}</textarea></label></div>
        </div><button class="button mt-3">Simpan perubahan</button>
    </form>
    <div class="d-flex flex-wrap gap-2 mt-3">
        <form method="post" action="{{ route('whatsapp.templates.sync', $template) }}">@csrf<input type="hidden" name="action" value="submit"><button class="button secondary" @disabled($template->provider_template_id)>Ajukan ke Meta</button></form>
        <form method="post" action="{{ route('whatsapp.templates.sync', $template) }}">@csrf<input type="hidden" name="action" value="sync"><button class="button secondary">Sinkronkan status &amp; isi</button></form>
    </div><p class="text-muted small mb-0 mt-3">Sinkronisasi terakhir: {{ $template->last_synced_at?->format('d/m/Y H:i') ?? 'Belum pernah' }}. Status persetujuan tidak dapat diubah manual.</p>
</details>
@endforeach
@php($defaultBody = "Yth. Bapak/Ibu {nama_pelanggan}, pelanggan {nama_pam} dengan ID {id_pelanggan}, RT {rt}.\n\nKami mengingatkan bahwa masih terdapat tagihan air yang belum dilunasi dengan rincian:\n{rincian_tunggakan}\n\nTotal sisa pembayaran: {total_tunggakan}.\n\nPembayaran dapat dilakukan melalui {rekening_pembayaran} atau menghubungi Bendahara di {kontak_bendahara}.\n\nApabila sudah melakukan pembayaran tetapi belum tercatat, silakan menghubungi Bendahara untuk konfirmasi. Terima kasih.\nKetik BERHENTI untuk menghentikan pengingat WhatsApp.")
<div class="card padded mt-4"><h2 class="h5">Tambah template</h2>
    <form method="post" action="{{ route('whatsapp.templates.store') }}">@csrf
        <div class="row g-3">
            <div class="col-md-6"><label class="field">Nama template<input name="name" value="{{ old('name') }}" required maxlength="255" placeholder="Pengingat tagihan air"></label></div>
            <div class="col-md-6"><label class="field">Nama template di Meta<input name="provider_name" value="{{ old('provider_name') }}" required pattern="[a-z][a-z0-9_]*" maxlength="128" placeholder="pengingat_tagihan_pam_v1"></label></div>
            <div class="col-md-6"><label class="field">Bahasa<input name="language" value="{{ old('language', 'id') }}" required></label></div>
            <div class="col-md-6"><label class="field">Penggunaan<select name="active"><option value="1">Aktif</option><option value="0">Nonaktif</option></select></label></div>
            <div class="col-12"><label class="field">Isi pesan<textarea name="body" rows="12" required maxlength="5000">{{ old('body', $defaultBody) }}</textarea></label></div>
        </div><button class="button mt-3">Buat template</button>
    </form>
</div>
@endsection
