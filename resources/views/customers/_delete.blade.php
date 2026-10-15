@can('delete', $customer)
<button type="button" class="button danger small" data-bs-toggle="modal" data-bs-target="#delete-customer-{{ $customer->id }}"><i class="bi bi-trash3" aria-hidden="true"></i> Hapus Pelanggan</button>
<div class="modal fade text-start" id="delete-customer-{{ $customer->id }}" tabindex="-1" aria-labelledby="delete-customer-title-{{ $customer->id }}" aria-hidden="true">
    <div class="modal-dialog">
        <form class="modal-content" method="post" action="{{ route('customers.destroy', $customer) }}">
            @csrf
            @method('DELETE')
            <div class="modal-header">
                <h5 class="modal-title" id="delete-customer-title-{{ $customer->id }}">Konfirmasi Hapus Pelanggan</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Tutup"></button>
            </div>
            <div class="modal-body">
                <p>Pastikan pelanggan yang akan dihapus sudah benar.</p>
                <dl class="mb-3">
                    <dt class="small text-secondary">ID pelanggan</dt>
                    <dd class="fw-semibold">{{ $customer->number }}</dd>
                    <dt class="small text-secondary">Nama pelanggan</dt>
                    <dd class="fw-semibold">{{ $customer->name }}</dd>
                </dl>
                <p class="subtle-note">Pelanggan akan dihapus dari daftar operasional. Riwayat meter, tagihan, pembayaran, dan laporan keuangan tetap tersimpan. Penghapusan ditolak apabila masih ada tagihan belum lunas.</p>
                <label class="field" for="deletion-reason-{{ $customer->id }}">Alasan penghapusan
                    <textarea id="deletion-reason-{{ $customer->id }}" name="reason" rows="3" minlength="5" maxlength="2000" required placeholder="Jelaskan alasan penghapusan pelanggan">{{ old('reason') }}</textarea>
                </label>
            </div>
            <div class="modal-footer">
                <button type="button" class="button secondary" data-bs-dismiss="modal">Batal</button>
                <button type="submit" class="button danger">Hapus Pelanggan</button>
            </div>
        </form>
    </div>
</div>
@endcan
