@extends('layouts.app')
@section('title', 'Pembayaran & Kuitansi')
@section('content')
<div class="page-heading"><div><h1>Pembayaran & kuitansi</h1><p class="text-muted">Catat penerimaan, alokasikan ke tagihan, dan telusuri setiap kuitansi.</p></div></div>

@if(auth()->user()->hasPermission('payments.create'))
<section class="card p-4 mb-4">
    <h2 class="h5">Terima pembayaran</h2>
    <form method="get" action="{{ route('payments.index') }}" class="row g-3 align-items-end mb-3">
        <div class="col-md-9"><label class="form-label" for="choose-customer">Pilih pelanggan</label><select id="choose-customer" name="customer_id" class="form-select" required><option value="">Pilih ID dan nama pelanggan</option>@foreach($customers as $option)<option value="{{ $option->id }}" @selected($customer?->id === $option->id)>{{ $option->number }} · {{ $option->name }} · RT {{ $option->rt->code }}</option>@endforeach</select></div>
        <div class="col-md-3"><button class="button w-100">Tampilkan tagihan</button></div>
    </form>
    @if($customer)
        <div class="alert alert-light border"><strong>{{ $customer->number }} · {{ $customer->name }}</strong><br>{{ $customer->address }} · RT {{ $customer->rt->code }}</div>
        @if($invoices->isEmpty())
            <p class="text-muted mb-0">Tidak ada tagihan terbit yang masih harus dibayar untuk pelanggan ini.</p>
        @else
        <form method="post" action="{{ route('payments.store') }}" enctype="multipart/form-data" id="payment-form">
            @csrf
            <input type="hidden" name="customer_id" value="{{ $customer->id }}"><input type="hidden" name="idempotency_key" value="{{ $idempotencyKey }}">
            <div class="table-wrap mb-3"><table class="table align-middle"><thead><tr><th>Tagihan / periode</th><th>Jatuh tempo</th><th class="text-end">Total</th><th class="text-end">Telah dibayar</th><th class="text-end">Sisa</th><th style="min-width:150px">Alokasi manual (Rp)</th></tr></thead><tbody>
                @foreach($invoices as $invoice)<tr><td><strong>{{ $invoice->number }}</strong><div class="small text-muted">{{ $invoice->period->name }}</div></td><td>{{ $invoice->due_date->format('d/m/Y') }}</td><td class="text-end">{{ number_format($invoice->total, 0, ',', '.') }}</td><td class="text-end">{{ number_format($invoice->paid_amount, 0, ',', '.') }}</td><td class="text-end">{{ number_format($invoice->total - $invoice->paid_amount, 0, ',', '.') }}</td><td><input class="form-control allocation-input" type="number" min="0" max="{{ $invoice->total - $invoice->paid_amount }}" step="1" name="allocations[{{ $invoice->id }}]" value="{{ old('allocations.'.$invoice->id, $invoice->total - $invoice->paid_amount) }}" aria-label="Alokasi {{ $invoice->number }}"></td></tr>@endforeach
            </tbody><tfoot><tr><th colspan="4">Total piutang pelanggan</th><th class="text-end">Rp{{ number_format($invoices->sum(fn($invoice) => $invoice->total - $invoice->paid_amount), 0, ',', '.') }}</th><th></th></tr></tfoot></table></div>
            <div class="row g-3">
                <div class="col-md-4"><label for="amount" class="form-label">Nominal diterima (Rp)</label><input id="amount" class="form-control" type="number" name="amount" min="1" step="1" max="9000000000000" required value="{{ old('amount', $invoices->sum(fn($invoice) => $invoice->total - $invoice->paid_amount)) }}"><div class="form-text">Rupiah bulat. Kelebihan pembayaran ditolak.</div></div>
                <div class="col-md-4"><label for="paid-on" class="form-label">Tanggal diterima</label><input id="paid-on" class="form-control" type="date" name="paid_on" max="{{ today()->format('Y-m-d') }}" value="{{ old('paid_on', today()->format('Y-m-d')) }}" required></div>
                <div class="col-md-4"><label for="allocation-mode" class="form-label">Alokasi pembayaran</label><select id="allocation-mode" class="form-select" name="allocation_mode"><option value="oldest" @selected(old('allocation_mode', $allocationMode) === 'oldest')>Tunggakan tertua terlebih dahulu</option><option value="manual" @selected(old('allocation_mode', $allocationMode) === 'manual')>Tagihan tertentu (manual)</option></select></div>
                <div class="col-md-4"><label for="method" class="form-label">Metode pembayaran</label><select id="method" class="form-select" name="payment_method_id" required><option value="">Pilih metode</option>@foreach($methods as $method)<option value="{{ $method->id }}" @selected((string)old('payment_method_id') === (string)$method->id)>{{ $method->name }}</option>@endforeach</select></div>
                <div class="col-md-4"><label for="account" class="form-label">Masuk ke akun kas/bank</label><select id="account" class="form-select" name="account_id" required><option value="">Pilih akun</option>@foreach($accounts as $account)<option value="{{ $account->id }}" @selected((string)old('account_id') === (string)$account->id)>{{ $account->name }}</option>@endforeach</select></div>
                <div class="col-md-4"><label for="reference" class="form-label">Referensi bank / catatan</label><input id="reference" class="form-control" name="reference" maxlength="150" value="{{ old('reference') }}"></div>
                <div class="col-md-8"><label for="proof" class="form-label">Bukti pembayaran (opsional)</label><input id="proof" class="form-control" type="file" name="proof" accept=".jpg,.jpeg,.png,.pdf"><div class="form-text">JPG, PNG, atau PDF, maksimal 5 MB. Berkas hanya dapat diakses pengguna berwenang.</div></div>
                <div class="col-12"><button class="button" id="save-payment">Simpan pembayaran & buat kuitansi</button></div>
            </div>
        </form>
        <script>document.addEventListener('DOMContentLoaded', function () { const mode = document.getElementById('allocation-mode'); const sync = () => document.querySelectorAll('.allocation-input').forEach(input => { input.disabled = mode.value !== 'manual'; }); mode.addEventListener('change', sync); sync(); document.getElementById('payment-form').addEventListener('submit', function () { document.getElementById('save-payment').disabled = true; }); });</script>
        @endif
    @endif
</section>
@endif

<section class="card p-4">
    <h2 class="h5">Riwayat penerimaan</h2>
    <form method="get" class="row g-2 mb-4 align-items-end">
        @if($customer)<input type="hidden" name="customer_id" value="{{ $customer->id }}">@endif
        <div class="col-md-3"><label class="form-label" for="search">Kuitansi / pelanggan</label><input id="search" name="search" value="{{ request('search') }}" class="form-control" placeholder="Nomor atau nama"></div>
        <div class="col-md-2"><label for="rt-filter" class="form-label">Wilayah</label><select id="rt-filter" name="rt_id" class="form-select"><option value="">Seluruh RT</option>@foreach($rts as $rt)<option value="{{ $rt->id }}" @selected((string)request('rt_id') === (string)$rt->id)>RT {{ $rt->code }}</option>@endforeach</select></div>
        <div class="col-md-2"><label for="from" class="form-label">Diterima dari</label><input id="from" type="date" name="from" class="form-control" value="{{ request('from') }}"></div><div class="col-md-2"><label for="to" class="form-label">Sampai</label><input id="to" type="date" name="to" class="form-control" value="{{ request('to') }}"></div>
        <div class="col-md-2"><label for="payment-status" class="form-label">Status</label><select id="payment-status" class="form-select" name="status"><option value="">Semua status</option><option value="posted" @selected(request('status') === 'posted')>Sah</option><option value="reversed" @selected(request('status') === 'reversed')>Dibatalkan</option></select></div><div class="col-md-1"><button class="button w-100">Filter</button></div>
    </form>
    <div class="table-wrap"><table class="table align-middle"><thead><tr><th>Tanggal / kuitansi</th><th>Pelanggan</th><th>Metode / akun</th><th class="text-end">Penerimaan</th><th>Status</th><th></th></tr></thead><tbody>
    @forelse($payments as $payment)<tr><td>{{ $payment->paid_on->format('d/m/Y') }}<div class="small text-muted">{{ $payment->number }}</div></td><td>{{ $payment->customer->name }}<div class="small text-muted">{{ $payment->customer->number }} · RT {{ $payment->customer->rt->code }}</div></td><td>{{ $payment->method->name }}<div class="small text-muted">{{ $payment->account->name }}</div></td><td class="text-end text-nowrap">Rp{{ number_format($payment->amount, 0, ',', '.') }}</td><td><span class="badge {{ $payment->status === 'posted' ? 'text-bg-success' : 'text-bg-danger' }}">{{ $payment->status === 'posted' ? 'Sah' : 'Dibatalkan' }}</span></td><td>@if(auth()->user()->hasPermission('payments.print'))<a href="{{ route('payments.receipt', $payment) }}" class="button secondary btn-sm">Kuitansi</a>@endif
    @if($payment->proof_path)<a href="{{ route('payments.proof', $payment) }}" class="small d-block mt-2" target="_blank" rel="noopener">Lihat bukti</a>@endif
    @if($payment->status === 'posted' && auth()->user()->hasPermission('payments.cancel'))<details class="mt-2"><summary class="small">Batalkan pembayaran</summary><form method="post" action="{{ route('payments.cancel', $payment) }}" class="mt-2">@csrf<input name="reason" class="form-control form-control-sm mb-2" required minlength="5" maxlength="1000" placeholder="Alasan pembatalan"><button class="button secondary btn-sm">Buat pembalik</button></form></details>@endif
    </td></tr>
    @empty<tr><td colspan="6" class="text-center text-muted py-4">Belum ada pembayaran yang sesuai filter.</td></tr>@endforelse
    </tbody></table></div>{{ $payments->links() }}
</section>
@endsection
