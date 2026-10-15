@extends('layouts.app')
@section('title', 'Pengaturan WhatsApp')
@section('content')
<div class="page-heading"><div><span class="eyebrow">PENGINGAT TAGIHAN</span><h1>Pengaturan WhatsApp</h1><p>Koneksi resmi Meta Cloud API dan jadwal pengingat pelanggan.</p></div><a class="button secondary" href="{{ route('whatsapp.index') }}">Kembali</a></div>
<div class="card padded mb-4">
    <div class="d-flex flex-wrap align-items-center gap-3 mb-3"><span class="badge {{ $settings->connected_at ? 'text-bg-success' : 'text-bg-secondary' }}">{{ $settings->connected_at ? 'Terhubung' : 'Belum Terhubung' }}</span><span>{{ $settings->sender_name ?: 'Identitas pengirim belum terverifikasi' }}</span></div>
    @if($canConfigure)<p class="text-muted">Simpan konfigurasi, lalu uji koneksi. Token yang tersimpan tidak ditampilkan. Membiarkan kolom rahasia kosong akan mempertahankan nilai sebelumnya.</p>@endif
    <form method="post" action="{{ route('whatsapp.settings.update') }}">
        @csrf @method('PUT')
        @if($canConfigure)<div class="row g-3">
            <div class="col-md-6"><label class="field">Penyedia<select name="provider"><option value="meta">Meta WhatsApp Cloud API</option></select></label></div>
            <div class="col-md-6"><label class="field">Versi Graph API<input name="graph_version" value="{{ old('graph_version', $settings->graph_version) }}" required pattern="v[0-9]+\.[0-9]+" placeholder="v25.0"></label></div>
            <div class="col-md-6"><label class="field">Phone Number ID<input name="phone_number_id" value="{{ old('phone_number_id', $settings->phone_number_id) }}" inputmode="numeric" pattern="[0-9]+"></label></div>
            <div class="col-md-6"><label class="field">WhatsApp Business Account ID<input name="waba_id" value="{{ old('waba_id', $settings->waba_id) }}" inputmode="numeric" pattern="[0-9]+"></label></div>
            <div class="col-md-6"><label class="field">Access Token {{ $settings->access_token ? '(tersimpan)' : '' }}<input name="access_token" type="password" autocomplete="new-password" maxlength="5000"></label></div>
            <div class="col-md-6"><label class="field">App Secret {{ $settings->app_secret ? '(tersimpan)' : '' }}<input name="app_secret" type="password" autocomplete="new-password" maxlength="1000"></label></div>
            <div class="col-md-6"><label class="field">Verify Token webhook {{ $settings->verify_token ? '(tersimpan)' : '' }}<input name="verify_token" type="password" autocomplete="new-password" minlength="16" maxlength="255"></label></div>
            <div class="col-md-6"><label class="field">Nama PAM dalam pesan<input name="pam_name" value="{{ old('pam_name', $settings->pam_name) }}" required maxlength="255"></label></div>
            <div class="col-md-6"><label class="field">Rekening atau petunjuk pembayaran<textarea name="payment_account" rows="3" maxlength="1000">{{ old('payment_account', $settings->payment_account) }}</textarea></label></div>
            <div class="col-md-6"><label class="field">Kontak Bendahara<input name="treasurer_contact" value="{{ old('treasurer_contact', $settings->treasurer_contact) }}" maxlength="255"></label></div>
        </div>
        @endif
        @if($canSchedule)
        <hr class="my-4"><h2 class="h5">Jadwal otomatis</h2><p class="text-muted">Zona waktu Asia/Jakarta. Pengingat berhenti setelah lunas atau persetujuan pelanggan dicabut. Batas dan jeda pengiriman juga berlaku untuk pengiriman manual.</p>
        <div class="row g-3">
            <div class="col-md-6"><label class="field">Pengiriman otomatis<select name="auto_enabled"><option value="0" @selected(!old('auto_enabled', $settings->auto_enabled))>Nonaktif</option><option value="1" @selected(old('auto_enabled', $settings->auto_enabled))>Aktif</option></select></label></div>
            <div class="col-md-6"><label class="field">Jam pengiriman<input type="time" name="send_time" value="{{ old('send_time', substr($settings->send_time ?? '09:00', 0, 5)) }}" required></label></div>
            <div class="col-md-6"><label class="field">Hari sebelum jatuh tempo (0 = nonaktif)<input type="number" name="before_days" min="0" max="90" value="{{ old('before_days', $settings->before_days ?? 3) }}" required></label></div>
            <div class="col-md-6"><label class="field">Saat jatuh tempo<select name="on_due"><option value="1" @selected(old('on_due', $settings->on_due))>Aktif</option><option value="0" @selected(!old('on_due', $settings->on_due))>Nonaktif</option></select></label></div>
            <div class="col-md-6"><label class="field">Mulai setelah terlambat (hari; 0 = nonaktif)<input type="number" name="after_days" min="0" max="365" value="{{ old('after_days', $settings->after_days ?? 3) }}" required></label></div>
            <div class="col-md-6"><label class="field">Ulangi setiap (hari)<input type="number" name="repeat_days" min="1" max="365" value="{{ old('repeat_days', $settings->repeat_days ?? 7) }}" required></label></div>
            <div class="col-md-6"><label class="field">Batas pengingat per pelanggan untuk tagihan terutang<input type="number" name="max_reminders" min="1" max="20" value="{{ old('max_reminders', $settings->max_reminders ?? 3) }}" required></label></div>
            <div class="col-md-6"><label class="field">Jeda antar pesan (detik)<input type="number" name="interval_seconds" min="1" max="3600" value="{{ old('interval_seconds', $settings->interval_seconds ?? 30) }}" required></label></div>
            <div class="col-md-12"><label class="field">Template otomatis<select name="template_id"><option value="">Pilih template yang disetujui</option>@foreach($templates as $template)<option value="{{ $template->id }}" @selected(old('template_id', $settings->template_id) == $template->id)>{{ $template->name }} — {{ $template->status }}{{ !$template->active ? ' (nonaktif)' : '' }}</option>@endforeach</select></label></div>
        </div>
        @endif
        <div class="mt-3"><button class="button">Simpan pengaturan</button></div>
    </form>
    @if($canConfigure)<form method="post" action="{{ route('whatsapp.settings.connect') }}" class="mt-3">@csrf<button class="button secondary">Uji koneksi API</button></form>@endif
</div>
@if($canConfigure)<div class="card padded"><h2 class="h5">Webhook Meta</h2><p>Daftarkan alamat HTTPS berikut sebagai callback di Meta Developer, gunakan Verify Token di atas, dan berlangganan field <code>messages</code> serta <code>message_template_status_update</code>.</p><p class="text-break"><code>{{ url('/whatsapp/webhook') }}</code></p><p class="mb-0 text-muted">Status terkirim, diterima, dan dibaca berasal dari webhook yang diverifikasi dengan App Secret. Pesan masuk STOP atau BERHENTI akan mencabut persetujuan pelanggan.</p></div>@endif
@endsection
