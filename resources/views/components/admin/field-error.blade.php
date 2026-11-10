@props(['name', 'id' => null])
@error($name)<p class="field-error" id="{{ $id ?: $name.'-error' }}" role="alert">{{ $message }}</p>@enderror
