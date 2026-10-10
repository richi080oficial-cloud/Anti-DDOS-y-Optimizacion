{{-- Interruptor «Mostrar en el menú lateral». Solo se ve con Vexa Hub instalado (sin él la entrada siempre se muestra). --}}
@if (\Pterodactyl\Extensions\AntiDDoS\Support\Sidebar::hub())
    <form action="{{ route('admin.extensions.antiddos.sidebar') }}" method="POST"
          style="display: flex; flex-wrap: wrap; align-items: center; gap: 6px 14px; margin: 0 0 15px; padding: 9px 14px; border: 1px solid rgba(127, 127, 127, .28); border-radius: 4px;">
        @csrf
        <input type="hidden" name="show" value="0">
        <label style="margin: 0; font-weight: 600; cursor: pointer;">
            <input type="checkbox" name="show" value="1" style="margin: 0 8px 0 0; vertical-align: middle;"
                   onchange="this.form.submit()" @checked(\Pterodactyl\Extensions\AntiDDoS\Support\Sidebar::pinned())>
            Mostrar «Anti-DDoS y Optimización» en el menú lateral
        </label>
        <span style="opacity: .7;">Por defecto está oculto y se abre desde <a href="{{ url('admin/extensions/vexahub') }}">Vexa Hub</a>.</span>
        <noscript><button type="submit" class="btn btn-xs btn-default">Guardar</button></noscript>
    </form>
@endif
