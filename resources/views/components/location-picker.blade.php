@props(['lat', 'lng'])
<div {{ $attributes }} x-data="locationPicker({ lat: '{{ $lat }}', lng: '{{ $lng }}' })" class="space-y-2">
    <div style="display: flex; flex-wrap: wrap; gap: 0.5rem">
        <input type="text" x-model="q" x-on:keydown.enter.prevent="find()" class="input" style="flex: 1 1 14rem; font-size: 16px" placeholder="Search a place or paste a Google Maps link">
        <button type="button" x-on:click="find()" x-bind:disabled="busy" class="btn-outline" style="padding: 0.4rem 0.9rem">Find</button>
        <button type="button" x-on:click="locate()" x-bind:disabled="busy" class="btn-outline" style="padding: 0.4rem 0.9rem">Use my location</button>
    </div>
    <div wire:ignore>
        <div x-ref="map" style="height: 16rem; border-radius: 0.5rem; border: 1px solid #e5e5e5; background: #f5f5f5; z-index: 0"></div>
    </div>
    <p class="text-xs text-critical-700" x-show="msg" x-text="msg" style="display: none"></p>
    <p class="text-xs text-neutral-500" x-show="lat !== null" style="display: none">
        Pinned <span class="font-mono" x-text="lat + ', ' + lng"></span>
        &middot; <a x-bind:href="link()" target="_blank" rel="noopener" class="font-semibold text-primary-700">Check in Google Maps</a>
        &middot; <button type="button" x-on:click="clear()" class="font-semibold text-neutral-600 underline">Clear</button>
    </p>
    <p class="text-xs text-neutral-400" x-show="lat === null">Tap the map to drop a pin.</p>
</div>
