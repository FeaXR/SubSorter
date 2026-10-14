@extends('layout')

@section('title', 'Channels – SubSorter')

@section('styles')
    table { width: 100%; border-collapse: collapse; }
    td { padding: 6px 0; border-top: 1px solid var(--border); vertical-align: middle; }
    td:first-child { border-left: 4px solid transparent; padding-left: 10px; }
    td.target { width: 45%; text-align: right; white-space: nowrap; }
    td.target select { width: calc(100% - 24px); }
    td.content { width: 1%; padding: 0 12px; white-space: nowrap; }
    td.content label { display: inline-flex; align-items: center; gap: 5px; font-size: 13px; color: var(--muted); cursor: pointer; }
    td.content label + label { margin-left: 10px; }
    tr.inactive td.content { opacity: .5; }
    .saved { display: inline-block; width: 20px; text-align: center; color: var(--ok); }
    .saved.err { color: var(--err); }

    /* Color coding by the playlist a channel's videos go to */
    tr.colored td:first-child { border-left-color: hsl(var(--h) 65% var(--pl-l)); }
    tr.colored select { border-color: hsl(var(--h) 65% var(--pl-l)); }
    tr.colored.via-default td:first-child { border-left-color: hsl(var(--h) 65% var(--pl-l) / .5); }
    tr.colored.via-default select { border-color: var(--border); }
    tr.inactive td:first-child { opacity: .5; }

    .legend { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 12px; }
    .legend button { display: inline-flex; align-items: center; gap: 6px; padding: 3px 10px; border-radius: 99px; font-size: 13px; }
    .legend button.active { border-color: var(--text); }
    .legend .count { color: var(--muted); font-variant-numeric: tabular-nums; }
@endsection

@section('content')
    @if ($playlists->isEmpty() || $subscriptions->isEmpty())
        <div class="flash err">
            Load your {{ $playlists->isEmpty() ? 'playlists' : 'subscriptions' }} on the
            <a href="{{ route('dashboard') }}">Videos</a> page first.
        </div>
    @endif

    <section class="card">
        <form method="post" action="{{ route('channels.default') }}" class="row">
            @csrf
            <label for="default">Default playlist</label>
            <select id="default" name="default_playlist_id">
                <option value="">— None (ignore unassigned channels) —</option>
                @foreach ($playlists as $playlist)
                    <option value="{{ $playlist->playlist_id }}" @selected($playlist->playlist_id === $defaultPlaylistId)>{{ $playlist->title }}</option>
                @endforeach
            </select>
            <button>Save</button>
        </form>
        <p class="muted" style="margin: 8px 0 0">Used for every channel set to “Default”.</p>
    </section>

    <section class="card">
        {{-- Channels per playlist; click to filter --}}
        <div class="legend" id="legend">
            @foreach ($playlists as $playlist)
                <button type="button" data-filter="pl:{{ $playlist->playlist_id }}" style="--h: {{ $hues[$playlist->playlist_id] }}">
                    <span class="dot"></span>{{ $playlist->title }}
                    @if ($playlist->playlist_id === $defaultPlaylistId)<span class="muted">(default)</span>@endif
                    <span class="count">0</span>
                </button>
            @endforeach
            <button type="button" data-filter="none">Not checked <span class="count">0</span></button>
        </div>

        <div class="row" style="margin-bottom: 12px">
            <input id="search" type="search" placeholder="Search channels…" style="flex: 1; min-width: 180px">
            <select id="filter" aria-label="Filter channels">
                <option value="all">All channels ({{ $subscriptions->count() }})</option>
                <optgroup label="Setting">
                    <option value="default">Set to Default</option>
                    <option value="assigned">Own playlist</option>
                    <option value="skip">Skipped</option>
                    <option value="none">Not checked (skipped, nothing selected or no target)</option>
                </optgroup>
                <optgroup label="Content">
                    <option value="videos-shorts">Videos + Shorts</option>
                    <option value="videos-only">Videos only</option>
                    <option value="shorts-only">Shorts only</option>
                    <option value="nothing">Nothing selected</option>
                </optgroup>
                <optgroup label="Videos go to playlist">
                    @foreach ($playlists as $playlist)
                        <option value="pl:{{ $playlist->playlist_id }}">{{ $playlist->title }}{{ $playlist->playlist_id === $defaultPlaylistId ? ' (default)' : '' }}</option>
                    @endforeach
                </optgroup>
            </select>
        </div>

        <table>
            <tbody>
            @foreach ($subscriptions as $sub)
                <tr data-title="{{ $sub->title }}">
                    <td>
                        <a class="channel" href="https://www.youtube.com/channel/{{ $sub->channel_id }}" target="_blank" rel="noopener">
                            <x-avatar :url="$sub->thumbnail_url" :title="$sub->title" />
                            <span>{{ $sub->title }}</span>
                        </a>
                    </td>
                    <td class="content">
                        <label title="Add regular (long-form) videos from this channel">
                            <input type="checkbox" data-field="include_videos" data-url="{{ route('channels.update', $sub) }}" @checked($sub->include_videos) aria-label="Include videos from {{ $sub->title }}">
                            Videos
                        </label>
                        <label title="Add Shorts from this channel (with Videos also on: past live streams too)">
                            <input type="checkbox" data-field="include_shorts" data-url="{{ route('channels.update', $sub) }}" @checked($sub->include_shorts) aria-label="Include Shorts from {{ $sub->title }}">
                            Shorts
                        </label>
                    </td>
                    <td class="target">
                        @php($value = $sub->skip ? 'skip' : ($sub->target_playlist_id ?? ''))
                        <select data-url="{{ route('channels.update', $sub) }}" aria-label="Target playlist for {{ $sub->title }}">
                            <option value="" @selected($value === '')>Default</option>
                            <option value="skip" @selected($value === 'skip')>Skip – don’t check</option>
                            @foreach ($playlists as $playlist)
                                <option value="{{ $playlist->playlist_id }}" @selected($value === $playlist->playlist_id)>{{ $playlist->title }}</option>
                            @endforeach
                        </select>
                        <span class="saved"></span>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </section>
@endsection

@section('scripts')
<script>
    const token = document.querySelector('meta[name=csrf-token]').content;
    const defaultPlaylist = @json($defaultPlaylistId);
    const hues = @json($hues);
    const rows = [...document.querySelectorAll('tbody tr')];
    const search = document.getElementById('search');
    const filter = document.getElementById('filter');
    const legend = [...document.querySelectorAll('#legend button')];

    // Accent- and case-insensitive search text: "Árvíztűrő" -> "arvizturo"
    const LETTERS = { 'ß': 'ss', 'æ': 'ae', 'œ': 'oe', 'ø': 'o', 'ł': 'l', 'đ': 'd', 'ð': 'd', 'þ': 'th', 'ı': 'i' };
    function normalize(text) {
        return text.toLowerCase()
            .normalize('NFD').replace(/\p{Diacritic}/gu, '')
            .replace(/[ßæœøłđðþı]/g, c => LETTERS[c])
            .replace(/\s+/g, ' ').trim();
    }
    rows.forEach(row => row.dataset.search = normalize(row.dataset.title));

    // Derive a row's state from its controls. Dropdown: "" = default, "skip", or a playlist ID
    function describe(row) {
        const value = row.querySelector('select').value;
        const videos = row.querySelector('[data-field=include_videos]').checked;
        const shorts = row.querySelector('[data-field=include_shorts]').checked;
        const target = value === 'skip' || !(videos || shorts) ? null : (value || defaultPlaylist);
        return {
            kind: value === '' ? 'default' : value === 'skip' ? 'skip' : 'assigned',
            target: target && target in hues ? target : null,
            content: videos && shorts ? 'videos-shorts' : videos ? 'videos-only' : shorts ? 'shorts-only' : 'nothing',
        };
    }

    function paint(row) {
        const { kind, target } = describe(row);
        row.classList.toggle('colored', target !== null);
        row.classList.toggle('via-default', kind === 'default');
        row.classList.toggle('inactive', target === null);
        target !== null ? row.style.setProperty('--h', hues[target]) : row.style.removeProperty('--h');
    }

    function matches(row, f) {
        const { kind, target, content } = describe(row);
        if (f === 'all') return true;
        if (f === 'none') return target === null;
        if (f.startsWith('pl:')) return target === f.slice(3);
        if (['videos-shorts', 'videos-only', 'shorts-only', 'nothing'].includes(f)) return content === f;
        return kind === f;
    }

    function render() {
        const q = normalize(search.value);
        for (const row of rows) {
            row.hidden = !row.dataset.search.includes(q) || !matches(row, filter.value);
        }
        for (const button of legend) {
            button.querySelector('.count').textContent = rows.filter(r => matches(r, button.dataset.filter)).length;
            button.classList.toggle('active', button.dataset.filter === filter.value);
        }
    }

    // Save a channel setting as soon as it changes; the ✓ / ! mark sits next to the dropdown
    async function save(row, url, payload) {
        const mark = row.querySelector('.saved');
        mark.className = 'saved';
        mark.textContent = '…';
        paint(row);
        render();
        row.hidden = false; // don't yank the row away while editing; it's filtered on the next search/filter change
        try {
            const res = await fetch(url, {
                method: 'PATCH',
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': token },
                body: JSON.stringify(payload),
            });
            if (!res.ok) throw new Error(res.status);
            mark.textContent = '✓';
        } catch {
            mark.className = 'saved err';
            mark.textContent = '!';
            mark.title = 'Saving failed, reload the page';
        }
    }

    document.querySelectorAll('select[data-url]').forEach(select => {
        select.addEventListener('change', () => save(select.closest('tr'), select.dataset.url, { target: select.value || null }));
    });
    document.querySelectorAll('input[type=checkbox][data-url]').forEach(box => {
        box.addEventListener('change', () => save(box.closest('tr'), box.dataset.url, { [box.dataset.field]: box.checked }));
    });

    legend.forEach(button => button.addEventListener('click', () => {
        filter.value = filter.value === button.dataset.filter ? 'all' : button.dataset.filter;
        render();
    }));
    search.addEventListener('input', render);
    filter.addEventListener('change', render);

    rows.forEach(paint);
    render();
</script>
@endsection
