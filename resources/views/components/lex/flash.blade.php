@if (session('status'))
    <div class="mb-4 rounded-md border border-emerald-200 bg-emerald-50 px-4 py-2 text-sm text-emerald-800" role="status">
        {{ session('status') }}
    </div>
@endif
