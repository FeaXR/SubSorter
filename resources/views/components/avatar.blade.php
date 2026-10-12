@props(['url' => null, 'title' => ''])

@if ($url)
    <img class="avatar" src="{{ $url }}" alt="" loading="lazy" referrerpolicy="no-referrer">
@else
    <span class="avatar placeholder" aria-hidden="true">{{ mb_strtoupper(mb_substr($title, 0, 1)) }}</span>
@endif
