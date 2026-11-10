@props(['user', 'size' => 'medium'])
@php
    $avatar = $user->avatar;
    $initials = collect(preg_split('/\s+/u', trim((string) $user->name), -1, PREG_SPLIT_NO_EMPTY) ?: [])
        ->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('');
    $isOwn = auth()->check() && auth()->user()->is($user);
    $avatarUrl = $isOwn ? route('admin.profile.avatar') : route('admin.users.avatar', $user);
@endphp
<span {{ $attributes->class(['user-avatar', 'user-avatar-'.$size]) }} aria-label="{{ $user->name }}">
    @if($avatar)
        <img src="{{ $avatarUrl }}?v={{ substr($avatar->checksum, 0, 16) }}" alt="" loading="lazy">
    @else
        <span aria-hidden="true">{{ $initials ?: 'U' }}</span>
    @endif
</span>
